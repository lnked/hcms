<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Auth\LoginGuard;
use Cms\Auth\OAuthException;
use Cms\Auth\OAuthService;
use Cms\Auth\Password;
use Cms\Auth\RolePolicy;
use Cms\Auth\TokenService;
use Cms\Auth\UsersRepository;
use Cms\Auth\UsersService;
use Cms\Core\Settings;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Security\CaptchaVerifier;
use Cms\Security\IpBlockRepository;
use Cms\Security\Totp;
use DateTimeImmutable;
use Throwable;

final class AuthController
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly LoginGuard $loginGuard,
        private readonly AuditLogger $audit,
        private readonly int $adminTtlHours = 12,
        private readonly ?CaptchaVerifier $captcha = null,
        private readonly ?Settings $settings = null,
        private readonly ?IpBlockRepository $ipBlocks = null,
        private readonly ?UsersRepository $users = null,
        private readonly ?OAuthService $oauth = null,
        private readonly ?UsersService $usersService = null,
    ) {
    }

    public function login(Request $request): Response
    {
        $payload = $request->json();
        $email = isset($payload['email']) && \is_string($payload['email']) ? trim($payload['email']) : '';
        $password = isset($payload['password']) && \is_string($payload['password']) ? $payload['password'] : '';
        $totpCode = isset($payload['totpCode']) && \is_string($payload['totpCode']) ? trim($payload['totpCode']) : '';
        $remember = $this->wantsRemember($payload);
        $captchaToken = isset($payload['captchaToken']) && \is_string($payload['captchaToken'])
            ? $payload['captchaToken']
            : ($request->header('x-captcha-token') ?? '');

        $fields = [];
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fields['email'] = ['Invalid email'];
        }
        if ($password === '') {
            $fields['password'] = ['Password is required'];
        }
        if ($fields !== []) {
            return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $fields);
        }

        if (!$this->loginGuard->canAttempt($request->ip, $email)) {
            $this->audit->log($request, 'auth.login_blocked', null, 'user', null, ['email' => $email]);
            $this->maybeAutoBlockIp($request);

            return Response::tooManyRequests($this->loginGuard->retryAfter($request->ip, $email));
        }

        $captchaAfter = $this->settings !== null
            ? max(0, $this->settings->int('security.login_captcha_after_failures', 2))
            : 2;
        $failures = $this->loginGuard->failureCount($request->ip, $email);
        if (
            $captchaAfter > 0
            && $failures >= $captchaAfter
            && $this->captcha !== null
            && $this->captcha->isConfigured()
        ) {
            if ($captchaToken === '' || !$this->captcha->verify($captchaToken, $request->ip)) {
                $this->loginGuard->fail($request->ip, $email);
                $this->audit->log($request, 'auth.login_failed', null, 'user', null, [
                    'email' => $email,
                    'reason' => 'captcha',
                ]);

                return Response::error('CAPTCHA_REQUIRED', 'Captcha verification required', 401, [
                    'captcha' => ['required'],
                ]);
            }
        }

        $user = $this->tokens->userByEmail($email);
        if ($user === null || !Password::verify($password, (string) $user['password_hash'])) {
            $this->loginGuard->fail($request->ip, $email);
            $this->audit->log($request, 'auth.login_failed', null, 'user', null, ['email' => $email]);

            return Response::error('UNAUTHORIZED', 'Invalid credentials', 401);
        }

        if (($user['status'] ?? '') !== 'active') {
            $this->loginGuard->fail($request->ip, $email);
            $this->audit->log($request, 'auth.login_denied', (int) $user['id'], 'user', (string) $user['id']);

            return Response::error('FORBIDDEN', 'Account is disabled', 403);
        }

        return $this->finishLogin($request, $user, $totpCode, $remember, 'password');
    }

    public function logout(Request $request, AuthContext $auth): Response
    {
        $this->tokens->revoke($auth->tokenId());
        $this->audit->log($request, 'auth.logout', $auth->userId(), 'token', (string) $auth->tokenId());

        return new Response(204, '');
    }

    public function me(Request $request, AuthContext $auth): Response
    {
        unset($request);
        if ($auth->user === null) {
            return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
        }

        return Response::data($this->publicUser($auth->user));
    }

    public function captchaConfig(Request $request): Response
    {
        unset($request);
        if ($this->captcha === null) {
            return Response::data(['enabled' => false, 'provider' => null, 'siteKey' => '']);
        }

        return Response::data($this->captcha->publicConfig());
    }

    public function totpSetup(Request $request, AuthContext $auth): Response
    {
        unset($request);
        if ($this->users === null || $auth->userId() === null) {
            return Response::error('SERVICE_UNAVAILABLE', 'Unavailable', 503);
        }
        $user = $this->users->find($auth->userId());
        if ($user === null) {
            return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
        }
        if ((bool) ($user['totp_enabled'] ?? false)) {
            return Response::error('VALIDATION_ERROR', '2FA is already enabled', 422);
        }

        $secret = Totp::generateSecret();
        $this->users->setTotp($auth->userId(), $secret, false);
        $issuer = $this->settings?->string('app.name', 'HCMS') ?? 'HCMS';

        return Response::data([
            'secret' => $secret,
            'otpauthUrl' => Totp::provisioningUri($secret, (string) $user['email'], $issuer),
        ]);
    }

    public function totpEnable(Request $request, AuthContext $auth): Response
    {
        if ($this->users === null || $auth->userId() === null) {
            return Response::error('SERVICE_UNAVAILABLE', 'Unavailable', 503);
        }
        $payload = $request->json();
        $code = isset($payload['totpCode']) && \is_string($payload['totpCode']) ? trim($payload['totpCode']) : '';
        $user = $this->users->find($auth->userId());
        if ($user === null) {
            return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
        }
        $secret = \is_string($user['totp_secret'] ?? null) ? (string) $user['totp_secret'] : '';
        if ($secret === '') {
            return Response::error('VALIDATION_ERROR', 'Call totp setup first', 422);
        }
        if (!Totp::verify($secret, $code)) {
            return Response::error('VALIDATION_ERROR', 'Invalid two-factor code', 422);
        }
        $this->users->setTotp($auth->userId(), $secret, true);
        $this->audit->log($request, 'auth.totp_enabled', $auth->userId(), 'user', (string) $auth->userId());

        return Response::data(['totpEnabled' => true]);
    }

    public function totpDisable(Request $request, AuthContext $auth): Response
    {
        if ($this->users === null || $auth->userId() === null) {
            return Response::error('SERVICE_UNAVAILABLE', 'Unavailable', 503);
        }
        $payload = $request->json();
        $password = isset($payload['password']) && \is_string($payload['password']) ? $payload['password'] : '';
        $user = $this->tokens->userByEmail((string) ($auth->user['email'] ?? ''));
        if ($user === null || !Password::verify($password, (string) $user['password_hash'])) {
            return Response::error('UNAUTHORIZED', 'Invalid password', 401);
        }
        $this->users->setTotp($auth->userId(), null, false);
        $this->audit->log($request, 'auth.totp_disabled', $auth->userId(), 'user', (string) $auth->userId());

        return Response::data(['totpEnabled' => false]);
    }

    public function changePassword(Request $request, AuthContext $auth): Response
    {
        $userId = $auth->userId();
        if ($this->users === null || $userId === null) {
            return Response::error('SERVICE_UNAVAILABLE', 'Unavailable', 503);
        }

        $payload = $request->json();
        $current = isset($payload['currentPassword']) && \is_string($payload['currentPassword'])
            ? $payload['currentPassword']
            : '';
        $new = isset($payload['newPassword']) && \is_string($payload['newPassword'])
            ? $payload['newPassword']
            : '';

        $fields = [];
        if ($current === '') {
            $fields['currentPassword'] = ['Current password is required'];
        }
        if ($new === '') {
            $fields['newPassword'] = ['New password is required'];
        }
        if ($fields !== []) {
            return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $fields);
        }

        $email = (string) ($auth->user['email'] ?? '');
        if (!$this->loginGuard->canAttempt($request->ip, $email)) {
            $this->audit->log($request, 'auth.password_change_blocked', $userId, 'user', (string) $userId);

            return Response::tooManyRequests($this->loginGuard->retryAfter($request->ip, $email));
        }

        $user = $this->tokens->userByEmail($email);
        $hash = $user === null ? '' : (string) $user['password_hash'];
        if ($user === null || !Password::verify($current, $hash)) {
            $this->loginGuard->fail($request->ip, $email);
            $this->audit->log($request, 'auth.password_change_failed', $userId, 'user', (string) $userId);

            return Response::error('VALIDATION_ERROR', 'Current password is incorrect', 422, [
                'currentPassword' => ['Current password is incorrect'],
            ]);
        }

        if (!Password::meetsPolicy($new)) {
            return Response::error('VALIDATION_ERROR', Password::policyMessage(), 422, [
                'newPassword' => [Password::policyMessage()],
            ]);
        }
        if (Password::verify($new, $hash)) {
            return Response::error('VALIDATION_ERROR', 'New password must differ from the current one', 422, [
                'newPassword' => ['New password must differ from the current one'],
            ]);
        }

        $this->users->setPassword($userId, Password::hash($new));
        $revoked = $this->tokens->revokeAllForUser($userId, 'admin', $auth->tokenId());
        $this->audit->log($request, 'auth.password_changed', $userId, 'user', (string) $userId);

        return Response::data(['ok' => true, 'revokedSessions' => $revoked]);
    }

    public function totpComplete(Request $request): Response
    {
        $payload = $request->json();
        $ticket = isset($payload['ticket']) && \is_string($payload['ticket']) ? $payload['ticket'] : '';
        $totpCode = isset($payload['totpCode']) && \is_string($payload['totpCode']) ? trim($payload['totpCode']) : '';
        $remember = $this->wantsRemember($payload);
        if ($ticket === '') {
            return Response::error('VALIDATION_ERROR', 'Ticket is required', 422, ['ticket' => ['required']]);
        }

        $token = $this->tokens->resolve($ticket);
        if ($token === null || ($token['type'] ?? '') !== 'oauth_pending') {
            return Response::error('UNAUTHORIZED', 'Invalid or expired ticket', 401);
        }
        $userId = isset($token['user_id']) ? (int) $token['user_id'] : 0;
        $user = $this->loadUser($userId);
        if ($user === null) {
            return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
        }
        $email = (string) ($user['email'] ?? '');
        $guardEmail = $email !== '' ? $email : 'oauth';
        if (!$this->loginGuard->canAttempt($request->ip, $guardEmail)) {
            $this->audit->log($request, 'auth.login_blocked', $userId, 'user', (string) $userId);

            return Response::tooManyRequests($this->loginGuard->retryAfter($request->ip, $guardEmail));
        }

        $result = $this->finishLogin($request, $user, $totpCode, $remember, 'oauth');
        if ($result->status < 400) {
            $this->tokens->revoke((int) $token['id']);
        }

        return $result;
    }

    public function providers(Request $request): Response
    {
        unset($request);
        if ($this->oauth === null) {
            return Response::data([
                'google' => ['enabled' => false, 'clientId' => ''],
                'telegram' => ['enabled' => false, 'botUsername' => ''],
            ]);
        }

        return Response::data($this->oauth->publicProviders());
    }

    public function googleStart(Request $request): Response
    {
        unset($request);
        if ($this->oauth === null) {
            return Response::error('SERVICE_UNAVAILABLE', 'CMS is not installed', 503);
        }
        try {
            return Response::redirect($this->oauth->googleAuthorizeUrl('login'));
        } catch (OAuthException $e) {
            return $this->oauthFragmentRedirect(['error' => $e->errorCode]);
        }
    }

    public function googleCallback(Request $request): Response
    {
        if ($this->oauth === null) {
            return Response::error('SERVICE_UNAVAILABLE', 'CMS is not installed', 503);
        }
        $error = $request->query('error');
        if ($error !== null && $error !== '') {
            return $this->oauthFragmentRedirect(['error' => 'PROVIDER_ERROR']);
        }
        $code = $request->query('code') ?? '';
        $state = $request->query('state') ?? '';
        if ($code === '' || $state === '') {
            return $this->oauthFragmentRedirect(['error' => 'INVALID_STATE']);
        }

        try {
            $decoded = $this->oauth->decodeState($state);
            $intent = isset($decoded['intent']) && \is_string($decoded['intent']) ? $decoded['intent'] : 'login';
            $linkUserId = isset($decoded['userId']) && \is_int($decoded['userId'])
                ? $decoded['userId']
                : (isset($decoded['userId']) && is_numeric($decoded['userId']) ? (int) $decoded['userId'] : null);
            $profile = $this->oauth->exchangeGoogleCode($code);

            if ($intent === 'link') {
                if ($linkUserId === null || $linkUserId < 1) {
                    return $this->oauthFragmentRedirect(['error' => 'UNAUTHORIZED']);
                }
                $this->oauth->linkIdentity($linkUserId, 'google', $profile['id'], $profile['email']);
                $this->audit->log($request, 'auth.identity_linked', $linkUserId, 'user', (string) $linkUserId, [
                    'provider' => 'google',
                ]);

                return $this->oauthFragmentRedirect(['linked' => 'google']);
            }

            $guardKey = $profile['email'];
            if (!$this->loginGuard->canAttempt($request->ip, $guardKey)) {
                $this->audit->log($request, 'auth.login_blocked', null, 'user', null, ['email' => $guardKey]);
                $this->maybeAutoBlockIp($request);

                return $this->oauthFragmentRedirect(['error' => 'TOO_MANY_REQUESTS']);
            }

            $user = $this->oauth->userForGoogle($profile['id'], $profile['email'], $profile['emailVerified']);
            if ($user === null) {
                $this->loginGuard->fail($request->ip, $guardKey);
                $this->audit->log($request, 'auth.login_failed', null, 'user', null, [
                    'email' => $profile['email'],
                    'reason' => 'google_not_found',
                ]);

                return $this->oauthFragmentRedirect(['error' => 'ACCOUNT_NOT_FOUND']);
            }
            $user = $this->loadUser((int) $user['id']) ?? $user;
            if (($user['status'] ?? '') !== 'active') {
                $this->loginGuard->fail($request->ip, $guardKey);
                $this->audit->log($request, 'auth.login_denied', (int) $user['id'], 'user', (string) $user['id']);

                return $this->oauthFragmentRedirect(['error' => 'ACCOUNT_DISABLED']);
            }

            $this->oauth->ensureGoogleIdentity((int) $user['id'], $profile['id'], $profile['email']);

            if ((bool) ($user['totp_enabled'] ?? false)) {
                $expiresAt = (new DateTimeImmutable('+5 minutes'))->format('Y-m-d H:i:s');
                $issued = $this->tokens->issue('oauth_pending', (int) $user['id'], 'oauth-totp', $expiresAt);

                return $this->oauthFragmentRedirect(['ticket' => $issued['token']]);
            }

            $session = $this->issueAdminToken($user, false);
            $this->tokens->touchLogin((int) $user['id']);
            $this->audit->log($request, 'auth.login', (int) $user['id'], 'user', (string) $user['id'], [
                'provider' => 'google',
            ]);

            return $this->oauthFragmentRedirect([
                'token' => $session['token'],
                'expiresAt' => $session['expiresAt'],
            ]);
        } catch (OAuthException $e) {
            return $this->oauthFragmentRedirect(['error' => $e->errorCode]);
        } catch (Throwable) {
            return $this->oauthFragmentRedirect(['error' => 'PROVIDER_ERROR']);
        }
    }

    public function telegramLogin(Request $request): Response
    {
        if ($this->oauth === null) {
            return Response::error('SERVICE_UNAVAILABLE', 'CMS is not installed', 503);
        }
        $payload = $request->json();
        $totpCode = isset($payload['totpCode']) && \is_string($payload['totpCode']) ? trim($payload['totpCode']) : '';
        $remember = $this->wantsRemember($payload);
        $guardKey = 'telegram';

        try {
            $profile = $this->oauth->verifyTelegramPayload($payload);
            $guardKey = 'telegram:' . $profile['id'];
            if (!$this->loginGuard->canAttempt($request->ip, $guardKey)) {
                $this->audit->log($request, 'auth.login_blocked', null, 'user', null, ['provider' => 'telegram']);
                $this->maybeAutoBlockIp($request);

                return Response::tooManyRequests($this->loginGuard->retryAfter($request->ip, $guardKey));
            }

            $user = $this->oauth->userForTelegram($profile['id']);
            if ($user === null) {
                $this->loginGuard->fail($request->ip, $guardKey);
                $this->audit->log($request, 'auth.login_failed', null, 'user', null, [
                    'reason' => 'telegram_not_linked',
                ]);

                return Response::error('ACCOUNT_NOT_LINKED', 'Telegram is not linked to an account', 403);
            }
            $user = $this->loadUser((int) $user['id']) ?? $user;
            if (($user['status'] ?? '') !== 'active') {
                $this->loginGuard->fail($request->ip, $guardKey);
                $this->audit->log($request, 'auth.login_denied', (int) $user['id'], 'user', (string) $user['id']);

                return Response::error('FORBIDDEN', 'Account is disabled', 403);
            }

            return $this->finishLogin($request, $user, $totpCode, $remember, 'telegram');
        } catch (OAuthException $e) {
            if ($e->errorCode === 'UNAUTHORIZED') {
                $this->loginGuard->fail($request->ip, $guardKey);
            }

            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function listIdentities(Request $request, AuthContext $auth): Response
    {
        unset($request);
        if ($this->oauth === null || $auth->userId() === null) {
            return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
        }

        return Response::data($this->oauth->identitiesForUser($auth->userId()));
    }

    public function googleLinkStart(Request $request, AuthContext $auth): Response
    {
        unset($request);
        if ($this->oauth === null || $auth->userId() === null) {
            return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
        }
        try {
            return Response::data(['url' => $this->oauth->googleAuthorizeUrl('link', $auth->userId())]);
        } catch (OAuthException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function telegramLink(Request $request, AuthContext $auth): Response
    {
        if ($this->oauth === null || $auth->userId() === null) {
            return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
        }
        try {
            $profile = $this->oauth->verifyTelegramPayload($request->json());
            $label = $profile['username'] !== null ? '@' . $profile['username'] : $profile['id'];
            $this->oauth->linkIdentity($auth->userId(), 'telegram', $profile['id'], $label);
            $this->audit->log($request, 'auth.identity_linked', $auth->userId(), 'user', (string) $auth->userId(), [
                'provider' => 'telegram',
            ]);

            return Response::data($this->oauth->identitiesForUser($auth->userId()));
        } catch (OAuthException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function unlinkIdentity(Request $request, AuthContext $auth, string $provider): Response
    {
        if ($this->oauth === null || $auth->userId() === null) {
            return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
        }
        try {
            $this->oauth->unlink($auth->userId(), $provider);
            $this->audit->log($request, 'auth.identity_unlinked', $auth->userId(), 'user', (string) $auth->userId(), [
                'provider' => $provider,
            ]);

            return Response::data($this->oauth->identitiesForUser($auth->userId()));
        } catch (OAuthException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function finishLogin(Request $request, array $user, string $totpCode, bool $remember, string $via): Response
    {
        $email = (string) ($user['email'] ?? '');
        if ((bool) ($user['totp_enabled'] ?? false)) {
            $secret = \is_string($user['totp_secret'] ?? null) ? (string) $user['totp_secret'] : '';
            if ($totpCode === '') {
                return Response::error('TOTP_REQUIRED', 'Two-factor code required', 401, [
                    'totp' => ['required'],
                ]);
            }
            if ($secret === '' || !Totp::verify($secret, $totpCode)) {
                $this->loginGuard->fail($request->ip, $email !== '' ? $email : 'oauth');
                $this->audit->log($request, 'auth.login_failed', (int) $user['id'], 'user', (string) $user['id'], [
                    'reason' => 'totp',
                    'via' => $via,
                ]);

                return Response::error('UNAUTHORIZED', 'Invalid two-factor code', 401);
            }
        }

        $session = $this->issueAdminToken($user, $remember);
        $this->tokens->touchLogin((int) $user['id']);
        $this->audit->log($request, 'auth.login', (int) $user['id'], 'user', (string) $user['id'], [
            'via' => $via,
        ]);

        return Response::data([
            'token' => $session['token'],
            'expiresAt' => $session['expiresAt'],
            'user' => $this->publicUser($user),
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @return array{token: string, expiresAt: string}
     */
    private function issueAdminToken(array $user, bool $remember): array
    {
        $ttlHours = $remember
            ? max(1, $this->settings?->int('auth.remember_token_ttl_hours', 720) ?? 720)
            : $this->adminTtlHours;
        $expiresAt = (new DateTimeImmutable(\sprintf('+%d hours', $ttlHours)))->format('Y-m-d H:i:s');
        $issued = $this->tokens->issue('admin', (int) $user['id'], 'admin-session', $expiresAt);

        return [
            'token' => $issued['token'],
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function wantsRemember(array $payload): bool
    {
        $value = $payload['remember'] ?? false;

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadUser(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        if ($this->users !== null) {
            return $this->users->find($id);
        }

        return $this->tokens->userById($id);
    }

    /**
     * @param array<string, string> $parts
     */
    private function oauthFragmentRedirect(array $parts): Response
    {
        $base = $this->oauth !== null
            ? $this->oauth->completeUrl()
            : '/admin/oauth/complete';
        $pairs = [];
        foreach ($parts as $key => $value) {
            $pairs[] = rawurlencode($key) . '=' . rawurlencode($value);
        }

        return Response::redirect($base . '#' . implode('&', $pairs));
    }

    private function maybeAutoBlockIp(Request $request): void
    {
        if ($this->ipBlocks === null || $this->settings === null) {
            return;
        }
        $threshold = max(0, $this->settings->int('security.ip_auto_block_after_login_blocks', 3));
        if ($threshold <= 0) {
            return;
        }
        $window = max(60, $this->settings->int('security.ip_auto_block_window_seconds', 3600));
        $count = $this->ipBlocks->countAuditActions($request->ip, 'auth.login_blocked', $window);
        // Current event already logged.
        if ($count < $threshold) {
            return;
        }
        $ttl = max(60, $this->settings->int('security.ip_auto_block_ttl_seconds', 3600));
        $expires = date('Y-m-d H:i:s', time() + $ttl);
        $this->ipBlocks->block($request->ip, 'auto:login_blocked', $expires, null);
        $this->audit->log($request, 'security.ip_blocked', null, 'ip', $request->ip, [
            'reason' => 'auto:login_blocked',
            'expiresAt' => $expires,
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function publicUser(array $user): array
    {
        $base = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => RolePolicy::normalize(isset($user['role']) ? (string) $user['role'] : null),
            'totpEnabled' => (bool) ($user['totp_enabled'] ?? false),
            'changelogSeenVersion' => $user['changelog_seen_version'] ?? null,
        ];
        $acl = $this->usersService !== null
            ? $this->usersService->aclSnapshot($user)
            : [
                'aclEnabled' => false,
                'sections' => [],
                'resourceGrants' => [],
            ];

        return array_merge($base, $acl);
    }
}
