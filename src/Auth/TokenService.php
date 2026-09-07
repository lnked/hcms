<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Database\Connection;
use DateTimeImmutable;

final class TokenService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{token: string, id: int}
     */
    public function issue(string $type, ?int $userId, string $name, ?string $expiresAt = null): array
    {
        $plain = bin2hex(random_bytes(32));
        $prefix = substr($plain, 0, 8);
        $hash = hash('sha256', $plain);

        $this->db->execute(
            'INSERT INTO cms_tokens (type, user_id, name, token_prefix, token_hash, expires_at, created_at)
             VALUES (:type, :user_id, :name, :prefix, :hash, :expires_at, :created_at)',
            [
                'type' => $type,
                'user_id' => $userId,
                'name' => $name,
                'prefix' => $prefix,
                'hash' => $hash,
                'expires_at' => $expiresAt,
                'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        return [
            'token' => $plain,
            'id' => (int) $this->db->lastInsertId(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(string $plain): ?array
    {
        if (strlen($plain) < 8) {
            return null;
        }

        $prefix = substr($plain, 0, 8);
        $row = $this->db->selectOne(
            'SELECT * FROM cms_tokens WHERE token_prefix = :prefix LIMIT 1',
            ['prefix' => $prefix],
        );
        if ($row === null) {
            return null;
        }

        if (!hash_equals((string) $row['token_hash'], hash('sha256', $plain))) {
            return null;
        }

        if ($row['revoked_at'] !== null) {
            return null;
        }

        if ($row['expires_at'] !== null && (string) $row['expires_at'] < date('Y-m-d H:i:s')) {
            return null;
        }

        $this->db->execute(
            'UPDATE cms_tokens SET last_used_at = :now WHERE id = :id',
            ['now' => date('Y-m-d H:i:s'), 'id' => $row['id']],
        );

        return $row;
    }

    public function touchLogin(int $userId): void
    {
        $this->db->execute(
            'UPDATE cms_users SET last_login_at = :now WHERE id = :id',
            ['now' => date('Y-m-d H:i:s'), 'id' => $userId],
        );
    }

    public function revoke(int $id): void
    {
        $this->db->execute(
            'UPDATE cms_tokens SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL',
            ['now' => date('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    public function restore(int $id): void
    {
        $this->db->execute(
            'UPDATE cms_tokens SET revoked_at = NULL WHERE id = :id AND revoked_at IS NOT NULL',
            ['id' => $id],
        );
    }

    public function revokeAllForUser(int $userId, ?string $type = null): int
    {
        $sql = 'UPDATE cms_tokens SET revoked_at = :now WHERE user_id = :user_id AND revoked_at IS NULL';
        $params = [
            'now' => date('Y-m-d H:i:s'),
            'user_id' => $userId,
        ];
        if ($type !== null) {
            $sql .= ' AND type = :type';
            $params['type'] = $type;
        }
        return $this->db->execute($sql, $params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function userById(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT id, name, email, status, role, totp_enabled, changelog_seen_version, created_at FROM cms_users WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function userByEmail(string $email): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_users WHERE email = :email LIMIT 1',
            ['email' => $email],
        );
    }
}
