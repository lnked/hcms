<?php

declare(strict_types=1);

namespace Cms\Auth;

interface UserIdentityStore
{
    /**
     * @return array<string, mixed>|null
     */
    public function findByProvider(string $provider, string $providerUserId): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findByUserAndProvider(int $userId, string $provider): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array;

    public function create(int $userId, string $provider, string $providerUserId, ?string $email): void;

    public function deleteByUserAndProvider(int $userId, string $provider): int;
}
