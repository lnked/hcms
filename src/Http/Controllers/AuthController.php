<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Auth\AuthContext;
use Cms\Auth\Password;
use Cms\Auth\TokenService;
use Cms\Http\Request;
use Cms\Http\Response;

final class AuthController
{
    public function __construct(private readonly TokenService $tokens)
    {
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

        $user = $this->tokens->userByEmail($email);
        if ($user === null || !Password::verify($password, (string) $user['password_hash'])) {
            return Response::error('UNAUTHORIZED', 'Invalid credentials', 401);
        }

        if (($user['status'] ?? '') !== 'active') {
            return Response::error('FORBIDDEN', 'Account is disabled', 403);
        }

        $issued = $this->tokens->issue('admin', (int) $user['id'], 'admin-session');

        return Response::data([
            'token' => $issued['token'],
            'user' => $this->publicUser($user),
        ]);
    }

    public function logout(Request $request, AuthContext $auth): Response
    {
        unset($request);
        $this->tokens->revoke($auth->tokenId());

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
