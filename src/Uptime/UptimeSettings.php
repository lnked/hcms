<?php

declare(strict_types=1);

namespace Cms\Uptime;

use Cms\Core\Settings;

/**
 * Reads uptime config from cms_settings key "uptime".
 */
final class UptimeSettings
{
    public const SETTINGS_KEY = 'uptime';

    public const DEFAULT_RETENTION_DAYS = 30;
    public const DEFAULT_HEARTBEAT_STALE_SECONDS = 300;
    public const DEFAULT_INTERVAL_SECONDS = 60;
    public const DEFAULT_SOFT_CRON_MIN_INTERVAL_SECONDS = 30;
    public const MAX_TARGETS = 100;
    public const MIN_INTERVAL_SECONDS = 30;
    public const MAX_TIMEOUT_MS = 15000;
    public const DEFAULT_TIMEOUT_MS = 5000;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function retentionDays(): int
    {
        return $this->int('retentionDays', self::DEFAULT_RETENTION_DAYS, 1, 365);
    }

    public function heartbeatStaleSeconds(): int
    {
        return $this->int('heartbeatStaleSeconds', self::DEFAULT_HEARTBEAT_STALE_SECONDS, 60, 86400);
    }

    public function defaultIntervalSeconds(): int
    {
        return $this->int('defaultIntervalSeconds', self::DEFAULT_INTERVAL_SECONDS, self::MIN_INTERVAL_SECONDS, 86400);
    }

    public function softCronEnabled(): bool
    {
        $raw = $this->settings->get(self::SETTINGS_KEY);
        if (!is_array($raw) || !array_key_exists('softCronEnabled', $raw)) {
            return true;
        }

        return (bool) $raw['softCronEnabled'];
    }

    public function softCronMinIntervalSeconds(): int
    {
        return $this->int(
            'softCronMinIntervalSeconds',
            self::DEFAULT_SOFT_CRON_MIN_INTERVAL_SECONDS,
            15,
            3600,
        );
    }

    private function int(string $key, int $default, int $min, int $max): int
    {
        $raw = $this->settings->get(self::SETTINGS_KEY);
        if (!is_array($raw) || !isset($raw[$key]) || !is_numeric($raw[$key])) {
            return $default;
        }
        $value = (int) $raw[$key];

        return max($min, min($max, $value));
    }
}
