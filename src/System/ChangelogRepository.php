<?php

declare(strict_types=1);

namespace Cms\System;

use Cms\Core\Paths;
use Cms\Core\Version;

final class ChangelogRepository
{
    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $path = $this->paths->changelogFile();
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !isset($decoded['releases']) || !is_array($decoded['releases'])) {
            return [];
        }

        /** @var list<array<string, mixed>> $releases */
        $releases = [];
        foreach ($decoded['releases'] as $release) {
            if (is_array($release) && isset($release['version']) && is_string($release['version'])) {
                $releases[] = $release;
            }
        }

        usort($releases, static function (array $a, array $b): int {
            return Version::compare((string) $b['version'], (string) $a['version']);
        });

        return $releases;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function since(?string $since, ?string $channel = null): array
    {
        $releases = $this->all();
        if ($channel !== null && $channel !== '') {
            $releases = array_values(array_filter(
                $releases,
                static fn (array $r): bool => ($r['channel'] ?? 'stable') === $channel,
            ));
        }

        if ($since === null || $since === '') {
            return $releases;
        }

        return array_values(array_filter(
            $releases,
            static fn (array $r): bool => Version::isGreater((string) $r['version'], $since),
        ));
    }
}
