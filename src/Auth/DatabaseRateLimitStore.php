<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Database\Connection;
use DateTimeImmutable;

final class DatabaseRateLimitStore implements RateLimitStore
{
    /**
     * A row is dead once its window is over, but the longest window in use is the
     * login one (`security.login_window_seconds`), so keep a day of slack.
     */
    private const RETENTION_SECONDS = 86400;

    /** Sweep runs on roughly one increment out of this many — no cron to rely on. */
    private const SWEEP_EVERY = 500;

    /** Cap per sweep so a neglected table is drained gradually instead of in one long lock. */
    private const SWEEP_LIMIT = 1000;

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
        $hits = $this->hits($bucket, $windowStart);
        $this->sweepExpired();

        return $hits;
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

    private function sweepExpired(): void
    {
        if (random_int(1, self::SWEEP_EVERY) !== 1) {
            return;
        }

        $this->db->execute(
            'DELETE FROM cms_rate_limits WHERE window_start < :before LIMIT ' . self::SWEEP_LIMIT,
            ['before' => date('Y-m-d H:i:s', time() - self::RETENTION_SECONDS)],
        );
    }
}
