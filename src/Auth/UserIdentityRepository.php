<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Database\Connection;

final class UserIdentityRepository implements UserIdentityStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findByProvider(string $provider, string $providerUserId): ?array
    {
        return $this->db->selectOne(
            'SELECT id, user_id, provider, provider_user_id, email, created_at
             FROM cms_user_identities
             WHERE provider = :provider AND provider_user_id = :uid
             LIMIT 1',
            ['provider' => $provider, 'uid' => $providerUserId],
        );
    }

    public function findByUserAndProvider(int $userId, string $provider): ?array
    {
        return $this->db->selectOne(
            'SELECT id, user_id, provider, provider_user_id, email, created_at
             FROM cms_user_identities
             WHERE user_id = :user_id AND provider = :provider
             LIMIT 1',
            ['user_id' => $userId, 'provider' => $provider],
        );
    }

    public function listForUser(int $userId): array
    {
        return $this->db->select(
            'SELECT id, user_id, provider, provider_user_id, email, created_at
             FROM cms_user_identities
             WHERE user_id = :user_id
             ORDER BY provider ASC',
            ['user_id' => $userId],
        );
    }

    public function create(int $userId, string $provider, string $providerUserId, ?string $email): void
    {
        $this->db->execute(
            'INSERT INTO cms_user_identities (user_id, provider, provider_user_id, email, created_at)
             VALUES (:user_id, :provider, :uid, :email, :created_at)',
            [
                'user_id' => $userId,
                'provider' => $provider,
                'uid' => $providerUserId,
                'email' => $email,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        );
    }

    public function deleteByUserAndProvider(int $userId, string $provider): int
    {
        return $this->db->execute(
            'DELETE FROM cms_user_identities WHERE user_id = :user_id AND provider = :provider',
            ['user_id' => $userId, 'provider' => $provider],
        );
    }
}
