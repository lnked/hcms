<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Auth\LoginGuard;
use Cms\Auth\Password;
use Cms\Auth\TokenService;
use Cms\Auth\UsersRepository;
use Cms\Core\Settings;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Security\CaptchaVerifier;
use Cms\Security\IpBlockRepository;
use Cms\Security\Totp;
use DateTimeImmutable;

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
    ) {
    }

    public function login(Request $request): Response
    {
        $payload = $request->json();
        $email = isset($payload['email']) && is_string($payload['email']) ? trim($payload['email']) : '';
        $password = isset($payload['password']) && is_string($payload['password']) ? $payload['password'] : '';
        $totpCode = isset($payload['totpCode']) && is_string($payload['totpCode']) ? trim($payload['totpCode']) : '';
        $captchaToken = isset($payload['captchaToken']) && is_string($payload['captchaToken'])
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

            return Response::tooManyRequests($this->loginGuard->retryAfter());
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

        if ((bool) ($user['totp_enabled'] ?? false)) {
            $secret = is_string($user['totp_secret'] ?? null) ? (string) $user['totp_secret'] : '';
            if ($totpCode === '') {
                return Response::error('TOTP_REQUIRED', 'Two-factor code required', 401, [
                    'totp' => ['required'],
                ]);
            }
            if ($secret === '' || !Totp::verify($secret, $totpCode)) {
                $this->loginGuard->fail($request->ip, $email);
                $this->audit->log($request, 'auth.login_failed', (int) $user['id'], 'user', (string) $user['id'], [
                    'reason' => 'totp',
                ]);

                return Response::error('UNAUTHORIZED', 'Invalid two-factor code', 401);
            }
        }

        $expiresAt = (new DateTimeImmutable(sprintf('+%d hours', $this->adminTtlHours)))->format('Y-m-d H:i:s');
        $issued = $this->tokens->issue('admin', (int) $user['id'], 'admin-session', $expiresAt);
        $this->tokens->touchLogin((int) $user['id']);
        $this->audit->log($request, 'auth.login', (int) $user['id'], 'user', (string) $user['id']);

        return Response::data([
            'token' => $issued['token'],
            'expiresAt' => $expiresAt,
            'user' => $this->publicUser($user),
        ]);
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
        $code = isset($payload['totpCode']) && is_string($payload['totpCode']) ? trim($payload['totpCode']) : '';
        $user = $this->users->find($auth->userId());
        if ($user === null) {
            return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
        }
        $secret = is_string($user['totp_secret'] ?? null) ? (string) $user['totp_secret'] : '';
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
        $password = isset($payload['password']) && is_string($payload['password']) ? $payload['password'] : '';
        $user = $this->tokens->userByEmail((string) ($auth->user['email'] ?? ''));
        if ($user === null || !Password::verify($password, (string) $user['password_hash'])) {
            return Response::error('UNAUTHORIZED', 'Invalid password', 401);
        }
        $this->users->setTotp($auth->userId(), null, false);
        $this->audit->log($request, 'auth.totp_disabled', $auth->userId(), 'user', (string) $auth->userId());

        return Response::data(['totpEnabled' => false]);
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
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'totpEnabled' => (bool) ($user['totp_enabled'] ?? false),
            'changelogSeenVersion' => $user['changelog_seen_version'] ?? null,
        ];
    }
}
