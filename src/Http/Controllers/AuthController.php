<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Auth\LoginGuard;
use Cms\Auth\Password;
use Cms\Auth\TokenService;
use Cms\Http\Request;
use Cms\Http\Response;
use DateTimeImmutable;

final class AuthController
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly LoginGuard $loginGuard,
        private readonly AuditLogger $audit,
        private readonly int $adminTtlHours = 12,
    ) {
    }

    public function login(Request $request): Response
    {
        $payload = $request->json();
        $email = isset($payload['email']) && is_string($payload['email']) ? trim($payload['email']) : '';
        $password = isset($payload['password']) && is_string($payload['password']) ? $payload['password'] : '';

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

            return Response::tooManyRequests($this->loginGuard->retryAfter());
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
            'changelogSeenVersion' => $user['changelog_seen_version'] ?? null,
        ];
    }
}
