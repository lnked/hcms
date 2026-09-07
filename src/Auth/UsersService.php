<?php

declare(strict_types=1);

namespace Cms\Auth;

use InvalidArgumentException;
use RuntimeException;

final class UsersService
{
    public function __construct(
        private readonly UsersRepository $users,
        private readonly ?TokenService $tokens = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return array_map([$this, 'serialize'], $this->users->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        $row = $this->users->find($id);
        if ($row === null) {
            throw new RuntimeException('User not found', 404);
        }

        return $this->serialize($row);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $validated = self::validateCreate($payload);
        if ($this->users->findByEmail($validated['email']) !== null) {
            throw new InvalidArgumentException('Email already exists');
        }

        $row = $this->users->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password_hash' => Password::hash($validated['password']),
            'status' => $validated['status'],
            'role' => $validated['role'],
        ]);

        return $this->serialize($row);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $existing = $this->users->find($id);
        if ($existing === null) {
            throw new RuntimeException('User not found', 404);
        }

        $validated = self::validateUpdate($payload);
        if (isset($validated['email']) && strcasecmp($validated['email'], (string) $existing['email']) !== 0) {
            $other = $this->users->findByEmail($validated['email']);
            if ($other !== null && (int) $other['id'] !== $id) {
                throw new InvalidArgumentException('Email already exists');
            }
        }

        $data = [];
        if (isset($validated['name'])) {
            $data['name'] = $validated['name'];
        }
        if (isset($validated['email'])) {
            $data['email'] = $validated['email'];
        }
        if (isset($validated['status'])) {
            $data['status'] = $validated['status'];
        }
        if (isset($validated['role'])) {
            $data['role'] = $validated['role'];
        }
        if (isset($validated['password'])) {
            $data['password_hash'] = Password::hash($validated['password']);
        }

        $updated = $this->serialize($this->users->update($id, $data));

        $becameDisabled = isset($validated['status'])
            && $validated['status'] === 'disabled'
            && ($existing['status'] ?? '') !== 'disabled';
        if ($becameDisabled && $this->tokens !== null) {
            $this->tokens->revokeAllForUser($id);
        }

        return $updated;
    }

    public function delete(int $id, int $actorId): void
    {
        if ($id === $actorId) {
            throw new InvalidArgumentException('Cannot delete yourself');
        }
        if ($this->users->find($id) === null) {
            throw new RuntimeException('User not found', 404);
        }

        if ($this->tokens !== null) {
            $this->tokens->revokeAllForUser($id);
        }
        $this->users->delete($id);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{name: string, email: string, password: string, status: string, role: string}
     */
    public static function validateCreate(array $payload): array
    {
        $name = isset($payload['name']) && is_string($payload['name']) ? trim($payload['name']) : '';
        $email = isset($payload['email']) && is_string($payload['email']) ? trim($payload['email']) : '';
        $password = isset($payload['password']) && is_string($payload['password']) ? $payload['password'] : '';
        $status = isset($payload['status']) && is_string($payload['status']) ? trim($payload['status']) : 'active';
        $role = isset($payload['role']) && is_string($payload['role']) ? trim($payload['role']) : RolePolicy::ADMIN;

        if ($name === '') {
            throw new InvalidArgumentException('Name is required');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Valid email is required');
        }
        if (!Password::meetsPolicy($password)) {
            throw new InvalidArgumentException(Password::policyMessage());
        }
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new InvalidArgumentException('Invalid status');
        }
        if (!in_array(RolePolicy::normalize($role), RolePolicy::ROLES, true)) {
            throw new InvalidArgumentException('Invalid role');
        }

        return [
            'name' => $name,
            'email' => strtolower($email),
            'password' => $password,
            'status' => $status,
            'role' => RolePolicy::normalize($role),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{name?: string, email?: string, password?: string, status?: string, role?: string}
     */
    public static function validateUpdate(array $payload): array
    {
        $out = [];
        if (array_key_exists('name', $payload)) {
            $name = is_string($payload['name']) ? trim($payload['name']) : '';
            if ($name === '') {
                throw new InvalidArgumentException('Name is required');
            }
            $out['name'] = $name;
        }
        if (array_key_exists('email', $payload)) {
            $email = is_string($payload['email']) ? trim($payload['email']) : '';
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Valid email is required');
            }
            $out['email'] = strtolower($email);
        }
        if (array_key_exists('password', $payload) && $payload['password'] !== null && $payload['password'] !== '') {
            $password = is_string($payload['password']) ? $payload['password'] : '';
            if (!Password::meetsPolicy($password)) {
                throw new InvalidArgumentException(Password::policyMessage());
            }
            $out['password'] = $password;
        }
        if (array_key_exists('status', $payload)) {
            $status = is_string($payload['status']) ? trim($payload['status']) : '';
            if (!in_array($status, ['active', 'disabled'], true)) {
                throw new InvalidArgumentException('Invalid status');
            }
            $out['status'] = $status;
        }
        if (array_key_exists('role', $payload)) {
            $role = is_string($payload['role']) ? trim($payload['role']) : '';
            if (!in_array(RolePolicy::normalize($role), RolePolicy::ROLES, true)) {
                throw new InvalidArgumentException('Invalid role');
            }
            $out['role'] = RolePolicy::normalize($role);
        }

        if ($out === []) {
            throw new InvalidArgumentException('No fields to update');
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'status' => $row['status'],
            'role' => RolePolicy::normalize(isset($row['role']) ? (string) $row['role'] : null),
            'totpEnabled' => (bool) ($row['totp_enabled'] ?? false),
            'lastLoginAt' => $row['last_login_at'] ?? null,
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }
}
