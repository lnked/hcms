<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Core\AdminBase;
use Cms\Http\HttpClient;

final class OAuthService
{
    private const STATE_TTL_SECONDS = 600;
    private const TELEGRAM_AUTH_TTL_SECONDS = 86400;

    private readonly AdminBase $adminBase;
    private readonly OidcClient $oidcClient;

    public function __construct(
        private readonly OAuthSettings $settings,
        private readonly UserIdentityStore $identities,
        private readonly TokenService $tokens,
        private readonly HttpClient $http,
        private readonly string $appUrl,
        private readonly string $appSecret,
        ?AdminBase $adminBase = null,
        ?OidcClient $oidcClient = null,
    ) {
        $this->adminBase = $adminBase ?? AdminBase::default();
        $this->oidcClient = $oidcClient ?? new OidcClient($http);
    }

    public function googleRedirectUri(): string
    {
        return rtrim($this->appUrl, '/') . $this->adminBase->apiPrefix() . '/auth/google/callback';
    }

    public function oidcRedirectUri(): string
    {
        return rtrim($this->appUrl, '/') . $this->adminBase->apiPrefix() . '/auth/oidc/callback';
    }

    /** @deprecated use googleRedirectUri() */
    public function redirectUri(): string
    {
        return $this->googleRedirectUri();
    }

    public function completeUrl(): string
    {
        return rtrim($this->appUrl, '/') . $this->adminBase->path('/oauth/complete');
    }

    /**
     * @return array{
     *   google: array{enabled: bool, clientId: string},
     *   telegram: array{enabled: bool, botUsername: string},
     *   oidc: array{enabled: bool, label: string}
     * }
     */
    public function publicProviders(): array
    {
        return $this->settings->publicProviders();
    }

    /**
     * @param 'login'|'link' $intent
     */
    public function googleAuthorizeUrl(string $intent, ?int $userId = null): string
    {
        $google = $this->settings->google();
        if (!$google['enabled'] || $google['clientId'] === '' || $google['clientSecret'] === '') {
            throw new OAuthException('PROVIDER_DISABLED', 'Google login is not configured', 400);
        }
        if ($intent === 'link' && $userId === null) {
            throw new OAuthException('UNAUTHORIZED', 'Unauthorized', 401);
        }

        $preset = OidcClient::googlePreset();
        $config = [
            'clientId' => $google['clientId'],
            'clientSecret' => $google['clientSecret'],
            'scopes' => 'openid email profile',
            'claimEmail' => 'email',
            'claimSub' => 'sub',
            'authorizationEndpoint' => $preset['authorizationEndpoint'],
            'tokenEndpoint' => $preset['tokenEndpoint'],
            'userinfoEndpoint' => $preset['userinfoEndpoint'],
            'issuer' => $preset['issuer'],
        ];
        $state = self::signState([
            'intent' => $intent,
            'userId' => $userId,
            'provider' => 'google',
        ], $this->appSecret);

        return $this->oidcClient->authorizeUrl($config, $this->googleRedirectUri(), $state, [
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);
    }

    /**
     * @param 'login'|'link' $intent
     */
    public function oidcAuthorizeUrl(string $intent, ?int $userId = null): string
    {
        $oidc = $this->settings->oidc();
        if (!$oidc['enabled'] || $oidc['clientId'] === '' || $oidc['clientSecret'] === '') {
            throw new OAuthException('PROVIDER_DISABLED', 'OIDC login is not configured', 400);
        }
        if ($intent === 'link' && $userId === null) {
            throw new OAuthException('UNAUTHORIZED', 'Unauthorized', 401);
        }

        $endpoints = $this->oidcClient->resolveEndpoints([
            'issuer' => $oidc['issuer'],
            'authorizationEndpoint' => $oidc['authorizationEndpoint'],
            'tokenEndpoint' => $oidc['tokenEndpoint'],
            'userinfoEndpoint' => $oidc['userinfoEndpoint'],
        ]);
        $config = [
            'clientId' => $oidc['clientId'],
            'clientSecret' => $oidc['clientSecret'],
            'scopes' => $oidc['scopes'],
            'claimEmail' => $oidc['claimEmail'],
            'claimSub' => $oidc['claimSub'],
            'authorizationEndpoint' => $endpoints['authorizationEndpoint'],
            'tokenEndpoint' => $endpoints['tokenEndpoint'],
            'userinfoEndpoint' => $endpoints['userinfoEndpoint'],
            'issuer' => $endpoints['issuer'],
        ];
        $state = self::signState([
            'intent' => $intent,
            'userId' => $userId,
            'provider' => 'oidc',
        ], $this->appSecret);

        return $this->oidcClient->authorizeUrl($config, $this->oidcRedirectUri(), $state);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeState(string $state): array
    {
        return self::parseState($state, $this->appSecret);
    }

    /**
     * @param array{intent?: mixed, userId?: mixed, provider?: mixed} $payload
     */
    public static function signState(array $payload, string $appSecret): string
    {
        $data = [
            'intent' => $payload['intent'] ?? 'login',
            'userId' => $payload['userId'] ?? null,
            'provider' => $payload['provider'] ?? 'google',
            'ts' => time(),
            'nonce' => bin2hex(random_bytes(16)),
        ];
        $json = json_encode($data, JSON_UNESCAPED_SLASHES) ?: '{}';
        $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return $b64 . '.' . hash_hmac('sha256', $b64, $appSecret);
    }

    /**
     * @return array<string, mixed>
     */
    public static function parseState(string $state, string $appSecret): array
    {
        $parts = explode('.', $state, 2);
        if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new OAuthException('INVALID_STATE', 'Invalid OAuth state', 400);
        }
        [$payload, $sig] = $parts;
        $expected = hash_hmac('sha256', $payload, $appSecret);
        if (!hash_equals($expected, $sig)) {
            throw new OAuthException('INVALID_STATE', 'Invalid OAuth state', 400);
        }
        $b64 = strtr($payload, '-_', '+/');
        $pad = \strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $json = base64_decode($b64, true);
        if ($json === false) {
            throw new OAuthException('INVALID_STATE', 'Invalid OAuth state', 400);
        }
        $data = json_decode($json, true);
        if (!\is_array($data)) {
            throw new OAuthException('INVALID_STATE', 'Invalid OAuth state', 400);
        }
        $ts = isset($data['ts']) && \is_int($data['ts']) ? $data['ts'] : 0;
        if ($ts < time() - self::STATE_TTL_SECONDS) {
            throw new OAuthException('INVALID_STATE', 'OAuth state expired', 400);
        }

        return $data;
    }

    /**
     * @return array{id: string, email: string, emailVerified: bool, name: string}
     */
    public function exchangeGoogleCode(string $code): array
    {
        $google = $this->settings->google();
        if (!$google['enabled'] || $google['clientId'] === '' || $google['clientSecret'] === '') {
            throw new OAuthException('PROVIDER_DISABLED', 'Google login is not configured', 400);
        }
        $preset = OidcClient::googlePreset();

        return $this->oidcClient->exchangeCode([
            'clientId' => $google['clientId'],
            'clientSecret' => $google['clientSecret'],
            'scopes' => 'openid email profile',
            'claimEmail' => 'email',
            'claimSub' => 'sub',
            'authorizationEndpoint' => $preset['authorizationEndpoint'],
            'tokenEndpoint' => $preset['tokenEndpoint'],
            'userinfoEndpoint' => $preset['userinfoEndpoint'],
            'issuer' => $preset['issuer'],
        ], $code, $this->googleRedirectUri());
    }

    /**
     * @return array{id: string, email: string, emailVerified: bool, name: string}
     */
    public function exchangeOidcCode(string $code): array
    {
        $oidc = $this->settings->oidc();
        if (!$oidc['enabled'] || $oidc['clientId'] === '' || $oidc['clientSecret'] === '') {
            throw new OAuthException('PROVIDER_DISABLED', 'OIDC login is not configured', 400);
        }
        $endpoints = $this->oidcClient->resolveEndpoints([
            'issuer' => $oidc['issuer'],
            'authorizationEndpoint' => $oidc['authorizationEndpoint'],
            'tokenEndpoint' => $oidc['tokenEndpoint'],
            'userinfoEndpoint' => $oidc['userinfoEndpoint'],
        ]);

        return $this->oidcClient->exchangeCode([
            'clientId' => $oidc['clientId'],
            'clientSecret' => $oidc['clientSecret'],
            'scopes' => $oidc['scopes'],
            'claimEmail' => $oidc['claimEmail'],
            'claimSub' => $oidc['claimSub'],
            'authorizationEndpoint' => $endpoints['authorizationEndpoint'],
            'tokenEndpoint' => $endpoints['tokenEndpoint'],
            'userinfoEndpoint' => $endpoints['userinfoEndpoint'],
            'issuer' => $endpoints['issuer'],
        ], $code, $this->oidcRedirectUri());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function userForGoogle(string $googleId, string $email, bool $emailVerified): ?array
    {
        return $this->userForEmailProvider('google', $googleId, $email, $emailVerified);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function userForOidc(string $subject, string $email, bool $emailVerified): ?array
    {
        return $this->userForEmailProvider('oidc', $subject, $email, $emailVerified);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function userForEmailProvider(
        string $provider,
        string $providerUserId,
        string $email,
        bool $emailVerified,
    ): ?array {
        $identity = $this->identities->findByProvider($provider, $providerUserId);
        $identityUser = null;
        if ($identity !== null) {
            $identityUser = $this->tokens->userById((int) $identity['user_id']);
        }
        $emailUser = $this->tokens->userByEmail($email);

        return SocialIdentityPolicy::emailVerifiedLoginUser($identityUser, $emailUser, $emailVerified);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function userForTelegram(string $telegramId): ?array
    {
        $identity = $this->identities->findByProvider('telegram', $telegramId);
        if ($identity === null) {
            return SocialIdentityPolicy::telegramLoginUser(null);
        }

        return SocialIdentityPolicy::telegramLoginUser($this->tokens->userById((int) $identity['user_id']));
    }

    public function linkIdentity(int $userId, string $provider, string $providerUserId, ?string $email): void
    {
        $taken = $this->identities->findByProvider($provider, $providerUserId);
        SocialIdentityPolicy::assertCanLink($taken, $userId);
        $existing = $this->identities->findByUserAndProvider($userId, $provider);
        if ($existing !== null) {
            if ((string) $existing['provider_user_id'] === $providerUserId) {
                return;
            }

            throw new OAuthException('ALREADY_LINKED', 'A different account is already linked', 409);
        }
        $this->identities->create($userId, $provider, $providerUserId, $email);
    }

    /**
     * Auto-link provider id when login matched by email.
     */
    public function ensureProviderIdentity(int $userId, string $provider, string $providerUserId, string $email): void
    {
        $existing = $this->identities->findByUserAndProvider($userId, $provider);
        if ($existing !== null) {
            return;
        }
        $taken = $this->identities->findByProvider($provider, $providerUserId);
        if ($taken !== null) {
            return;
        }
        $this->identities->create($userId, $provider, $providerUserId, $email);
    }

    /**
     * @deprecated use ensureProviderIdentity(..., 'google', ...)
     */
    public function ensureGoogleIdentity(int $userId, string $googleId, string $email): void
    {
        $this->ensureProviderIdentity($userId, 'google', $googleId, $email);
    }

    /**
     * @return list<array{provider: string, linked: bool, label: string|null}>
     */
    public function identitiesForUser(int $userId): array
    {
        $rows = $this->identities->listForUser($userId);
        $byProvider = [];
        foreach ($rows as $row) {
            $provider = (string) $row['provider'];
            $byProvider[$provider] = \is_string($row['email'] ?? null)
                ? (string) $row['email']
                : (string) $row['provider_user_id'];
        }

        return [
            [
                'provider' => 'google',
                'linked' => isset($byProvider['google']),
                'label' => $byProvider['google'] ?? null,
            ],
            [
                'provider' => 'telegram',
                'linked' => isset($byProvider['telegram']),
                'label' => $byProvider['telegram'] ?? null,
            ],
            [
                'provider' => 'oidc',
                'linked' => isset($byProvider['oidc']),
                'label' => $byProvider['oidc'] ?? null,
            ],
        ];
    }

    public function unlink(int $userId, string $provider): void
    {
        if (!\in_array($provider, ['google', 'telegram', 'oidc'], true)) {
            throw new OAuthException('VALIDATION_ERROR', 'Unknown provider', 422);
        }
        $this->identities->deleteByUserAndProvider($userId, $provider);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{id: string, username: string|null, name: string}
     */
    public function verifyTelegramPayload(array $payload): array
    {
        $telegram = $this->settings->telegram();
        if (!$telegram['enabled'] || $telegram['botToken'] === '' || $telegram['botUsername'] === '') {
            throw new OAuthException('PROVIDER_DISABLED', 'Telegram login is not configured', 400);
        }
        if (!self::verifyTelegramAuth($payload, $telegram['botToken'])) {
            throw new OAuthException('UNAUTHORIZED', 'Invalid Telegram login payload', 401);
        }
        $id = isset($payload['id']) ? (string) $payload['id'] : '';
        if ($id === '') {
            throw new OAuthException('UNAUTHORIZED', 'Invalid Telegram login payload', 401);
        }
        $username = isset($payload['username']) && \is_string($payload['username']) && $payload['username'] !== ''
            ? $payload['username']
            : null;
        $first = isset($payload['first_name']) && \is_string($payload['first_name']) ? $payload['first_name'] : '';
        $last = isset($payload['last_name']) && \is_string($payload['last_name']) ? $payload['last_name'] : '';
        $name = trim($first . ' ' . $last);

        return [
            'id' => $id,
            'username' => $username,
            'name' => $name !== '' ? $name : ($username ?? $id),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function verifyTelegramAuth(array $payload, string $botToken): bool
    {
        $hash = isset($payload['hash']) && \is_string($payload['hash']) ? $payload['hash'] : '';
        if ($hash === '' || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return false;
        }
        $authDate = isset($payload['auth_date']) ? (int) $payload['auth_date'] : 0;
        if ($authDate < 1 || time() - $authDate > self::TELEGRAM_AUTH_TTL_SECONDS) {
            return false;
        }
        $expected = self::telegramHash($payload, $botToken);

        return hash_equals($expected, $hash);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function telegramHash(array $payload, string $botToken): string
    {
        $pairs = [];
        foreach ($payload as $key => $value) {
            if ($key === 'hash' || !\is_scalar($value)) {
                continue;
            }
            $pairs[] = $key . '=' . (string) $value;
        }
        sort($pairs, SORT_STRING);
        $secret = hash('sha256', $botToken, true);

        return hash_hmac('sha256', implode("\n", $pairs), $secret);
    }
}
