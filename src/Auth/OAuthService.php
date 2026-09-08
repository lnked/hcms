<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Http\HttpClient;

final class OAuthService
{
    private const STATE_TTL_SECONDS = 600;
    private const TELEGRAM_AUTH_TTL_SECONDS = 86400;
    private const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const GOOGLE_USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function __construct(
        private readonly OAuthSettings $settings,
        private readonly UserIdentityStore $identities,
        private readonly TokenService $tokens,
        private readonly HttpClient $http,
        private readonly string $appUrl,
        private readonly string $appSecret,
    ) {
    }

    public function redirectUri(): string
    {
        return rtrim($this->appUrl, '/') . '/admin/api/auth/google/callback';
    }

    public function completeUrl(): string
    {
        return rtrim($this->appUrl, '/') . '/admin/oauth/complete';
    }

    /**
     * @return array{google: array{enabled: bool, clientId: string}, telegram: array{enabled: bool, botUsername: string}}
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

        $state = self::signState([
            'intent' => $intent,
            'userId' => $userId,
        ], $this->appSecret);

        return self::GOOGLE_AUTH_URL . '?' . http_build_query([
            'client_id' => $google['clientId'],
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeState(string $state): array
    {
        return self::parseState($state, $this->appSecret);
    }

    /**
     * @param array{intent?: mixed, userId?: mixed} $payload
     */
    public static function signState(array $payload, string $appSecret): string
    {
        $data = [
            'intent' => $payload['intent'] ?? 'login',
            'userId' => $payload['userId'] ?? null,
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
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new OAuthException('INVALID_STATE', 'Invalid OAuth state', 400);
        }
        [$payload, $sig] = $parts;
        $expected = hash_hmac('sha256', $payload, $appSecret);
        if (!hash_equals($expected, $sig)) {
            throw new OAuthException('INVALID_STATE', 'Invalid OAuth state', 400);
        }
        $b64 = strtr($payload, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $json = base64_decode($b64, true);
        if ($json === false) {
            throw new OAuthException('INVALID_STATE', 'Invalid OAuth state', 400);
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new OAuthException('INVALID_STATE', 'Invalid OAuth state', 400);
        }
        $ts = isset($data['ts']) && is_int($data['ts']) ? $data['ts'] : 0;
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

        $tokenResponse = $this->http->request(
            'POST',
            self::GOOGLE_TOKEN_URL,
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            http_build_query([
                'code' => $code,
                'client_id' => $google['clientId'],
                'client_secret' => $google['clientSecret'],
                'redirect_uri' => $this->redirectUri(),
                'grant_type' => 'authorization_code',
            ]),
        );
        $tokenJson = $this->decodeJson($tokenResponse['body']);
        $accessToken = isset($tokenJson['access_token']) && is_string($tokenJson['access_token'])
            ? $tokenJson['access_token']
            : '';
        if ($tokenResponse['status'] >= 400 || $accessToken === '') {
            throw new OAuthException('PROVIDER_ERROR', 'Google token exchange failed', 502);
        }

        $infoResponse = $this->http->request(
            'GET',
            self::GOOGLE_USERINFO_URL,
            ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json'],
        );
        $info = $this->decodeJson($infoResponse['body']);
        if ($infoResponse['status'] >= 400) {
            throw new OAuthException('PROVIDER_ERROR', 'Google userinfo failed', 502);
        }

        $id = isset($info['sub']) && is_string($info['sub']) ? $info['sub'] : '';
        $email = isset($info['email']) && is_string($info['email']) ? strtolower(trim($info['email'])) : '';
        $verified = (bool) ($info['email_verified'] ?? false);
        $name = isset($info['name']) && is_string($info['name']) ? $info['name'] : $email;
        if ($id === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new OAuthException('PROVIDER_ERROR', 'Google account has no verified email', 400);
        }

        return [
            'id' => $id,
            'email' => $email,
            'emailVerified' => $verified,
            'name' => $name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function userForGoogle(string $googleId, string $email, bool $emailVerified): ?array
    {
        $identity = $this->identities->findByProvider('google', $googleId);
        $identityUser = null;
        if ($identity !== null) {
            $identityUser = $this->tokens->userById((int) $identity['user_id']);
        }
        $emailUser = $this->tokens->userByEmail($email);

        return SocialIdentityPolicy::googleLoginUser($identityUser, $emailUser, $emailVerified);
    }

    /**
     * @return array<string, mixed>
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
     * Auto-link Google id when login matched by email.
     */
    public function ensureGoogleIdentity(int $userId, string $googleId, string $email): void
    {
        $existing = $this->identities->findByUserAndProvider($userId, 'google');
        if ($existing !== null) {
            return;
        }
        $taken = $this->identities->findByProvider('google', $googleId);
        if ($taken !== null) {
            return;
        }
        $this->identities->create($userId, 'google', $googleId, $email);
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
            $byProvider[$provider] = is_string($row['email'] ?? null) ? (string) $row['email'] : (string) $row['provider_user_id'];
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
        ];
    }

    public function unlink(int $userId, string $provider): void
    {
        if (!in_array($provider, ['google', 'telegram'], true)) {
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
        $username = isset($payload['username']) && is_string($payload['username']) && $payload['username'] !== ''
            ? $payload['username']
            : null;
        $first = isset($payload['first_name']) && is_string($payload['first_name']) ? $payload['first_name'] : '';
        $last = isset($payload['last_name']) && is_string($payload['last_name']) ? $payload['last_name'] : '';
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
        $hash = isset($payload['hash']) && is_string($payload['hash']) ? $payload['hash'] : '';
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
            if ($key === 'hash' || !is_scalar($value)) {
                continue;
            }
            $pairs[] = $key . '=' . (string) $value;
        }
        sort($pairs, SORT_STRING);
        $secret = hash('sha256', $botToken, true);

        return hash_hmac('sha256', implode("\n", $pairs), $secret);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
