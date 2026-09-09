<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Database\Connection;
use RuntimeException;

final class UsersRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->select(
            'SELECT id, name, email, status, role, acl_enabled, totp_enabled, last_login_at, created_at, updated_at
             FROM cms_users
             ORDER BY id ASC',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT id, name, email, status, role, acl_enabled, totp_enabled, totp_secret, last_login_at, created_at, updated_at
             FROM cms_users WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->db->selectOne(
            'SELECT id, name, email, status, role, acl_enabled, password_hash, totp_enabled, totp_secret, last_login_at, created_at, updated_at
             FROM cms_users WHERE email = :email LIMIT 1',
            ['email' => $email],
        );
    }

    public function count(): int
    {
        $row = $this->db->selectOne('SELECT COUNT(*) AS c FROM cms_users');

        return $row === null ? 0 : (int) $row['c'];
    }

    /**
     * @param array{name: string, email: string, password_hash: string, status: string, role?: string} $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $role = RolePolicy::normalize($data['role'] ?? RolePolicy::ADMIN);
        $this->db->execute(
            'INSERT INTO cms_users (name, email, password_hash, status, role, created_at, updated_at)
             VALUES (:name, :email, :password_hash, :status, :role, :created_at, :updated_at)',
            [
                'name' => $data['name'],
                'email' => $data['email'],
                'password_hash' => $data['password_hash'],
                'status' => $data['status'],
                'role' => $role,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $row = $this->find((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to create user');
        }

        return $row;
    }

    /**
     * @param array{name?: string, email?: string, password_hash?: string, status?: string, role?: string} $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('User not found', 404);
        }

        $this->db->execute(
            'UPDATE cms_users
             SET name = :name, email = :email, password_hash = :password_hash, status = :status, role = :role, updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $id,
                'name' => $data['name'] ?? $existing['name'],
                'email' => $data['email'] ?? $existing['email'],
                'password_hash' => $data['password_hash'] ?? $this->passwordHash($id),
                'status' => $data['status'] ?? $existing['status'],
                'role' => isset($data['role'])
                    ? RolePolicy::normalize($data['role'])
                    : RolePolicy::normalize(isset($existing['role']) ? (string) $existing['role'] : null),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        );

        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('User not found after update', 404);
        }

        return $row;
    }

    public function delete(int $id): void
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('User not found', 404);
        }

        $this->db->execute('DELETE FROM cms_users WHERE id = :id', ['id' => $id]);
    }

    public function setPassword(int $id, string $hash): void
    {
        $this->db->execute(
            'UPDATE cms_users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id',
            [
                'id' => $id,
                'password_hash' => $hash,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        );
    }

    public function setAclEnabled(int $id, bool $enabled): void
    {
        $this->db->execute(
            'UPDATE cms_users SET acl_enabled = :acl_enabled, updated_at = :updated_at WHERE id = :id',
            [
                'id' => $id,
                'acl_enabled' => $enabled ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        );
    }

    public function setTotp(int $id, ?string $secret, bool $enabled): void
    {
        $this->db->execute(
            'UPDATE cms_users SET totp_secret = :secret, totp_enabled = :enabled, updated_at = :updated_at WHERE id = :id',
            [
                'id' => $id,
                'secret' => $secret,
                'enabled' => $enabled ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        );
    }

    private function passwordHash(int $id): string
    {
        $row = $this->db->selectOne(
            'SELECT password_hash FROM cms_users WHERE id = :id',
            ['id' => $id],
        );
        if ($row === null) {
            throw new RuntimeException('User not found', 404);
        }

        return (string) $row['password_hash'];
    }
}
