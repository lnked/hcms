<?php

declare(strict_types=1);

namespace Cms\Auth;

final class AuthContext
{
    /**
     * @param array<string, mixed> $token
     * @param array<string, mixed>|null $user
     */
    public function __construct(
        public readonly array $token,
        public readonly ?array $user,
    ) {
    }

    public function tokenId(): int
    {
        return (int) $this->token['id'];
    }

    public function userId(): ?int
    {
        if ($this->user === null) {
            return null;
        }

        return (int) $this->user['id'];
    }

    public function isAdmin(): bool
    {
        return ($this->token['type'] ?? '') === 'admin';
    }
}
