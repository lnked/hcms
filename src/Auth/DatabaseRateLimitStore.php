<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Database\Connection;
use DateTimeImmutable;

final class DatabaseRateLimitStore implements RateLimitStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function increment(string $bucket, DateTimeImmutable $windowStart): int
    {
        $window = $windowStart->format('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_rate_limits (bucket, window_start, hits) VALUES (:bucket, :window, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            ['bucket' => $bucket, 'window' => $window],
        );

        return $this->hits($bucket, $windowStart);
    }

    public function hits(string $bucket, DateTimeImmutable $windowStart): int
    {
        $row = $this->db->selectOne(
            'SELECT hits FROM cms_rate_limits WHERE bucket = :bucket AND window_start = :window',
            [
                'bucket' => $bucket,
                'window' => $windowStart->format('Y-m-d H:i:s'),
            ],
        );

        return $row === null ? 0 : (int) $row['hits'];
    }
}
