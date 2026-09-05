<?php

declare(strict_types=1);

namespace Cms\Auth;

final class LoginGuard
{
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function canAttempt(string $ip, string $email): bool
    {
        foreach ($this->buckets($ip, $email) as $bucket) {
            if (!$this->limiter->allow($bucket)) {
                return false;
            }
        }

        return true;
    }

    public function fail(string $ip, string $email): void
    {
        foreach ($this->buckets($ip, $email) as $bucket) {
            $this->limiter->hit($bucket);
        }
    }

    public function retryAfter(): int
    {
        return $this->limiter->retryAfter();
    }

    /**
     * @return list<string>
     */
    private function buckets(string $ip, string $email): array
    {
        $normalized = strtolower(trim($email));

        return [
            'login:ip:' . $ip,
            'login:email:' . hash('sha256', $normalized),
        ];
    }
}
