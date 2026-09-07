<?php

declare(strict_types=1);

namespace Cms\System;

use Cms\Core\Version;

/**
 * Turns changelog entries into "what arrives with this update".
 *
 * Kept free of I/O so the update preview and its breaking-change gate can be
 * tested directly: the entries come either from the release manifest (the only
 * source that knows about versions the site does not have yet) or from the
 * local changelog.json.
 */
final class ReleaseNotes
{
    /**
     * @param array<string, mixed>|null $manifest
     *
     * @return list<array<string, mixed>> Newest first; empty when the manifest carries no notes.
     */
    public static function fromManifest(?array $manifest): array
    {
        $entries = is_array($manifest) && is_array($manifest['changelog'] ?? null) ? $manifest['changelog'] : [];

        $releases = [];
        foreach ($entries as $release) {
            if (is_array($release) && isset($release['version']) && is_string($release['version'])) {
                $releases[] = $release;
            }
        }

        usort(
            $releases,
            static fn (array $a, array $b): int => Version::compare((string) $b['version'], (string) $a['version']),
        );

        return $releases;
    }

    /**
     * @param list<array<string, mixed>> $releases
     *
     * @return array{changes: list<array<string, mixed>>, hasBreaking: bool, migrationNotes: list<string>}
     */
    public static function delta(array $releases, string $from, string $to): array
    {
        $changes = [];
        $hasBreaking = false;
        $migrationNotes = [];

        foreach ($releases as $release) {
            $version = (string) ($release['version'] ?? '');
            if ($version === '' || !Version::isGreater($version, $from)) {
                continue;
            }
            if ($to !== '' && Version::compare($version, $to) > 0) {
                continue;
            }

            $items = is_array($release['changes'] ?? null) ? $release['changes'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $changes[] = [
                    'version' => $version,
                    'type' => $item['type'] ?? 'changed',
                    'area' => $item['area'] ?? null,
                    'text' => $item['text'] ?? '',
                    'migration' => $item['migration'] ?? null,
                ];
                if (($item['type'] ?? '') !== 'breaking') {
                    continue;
                }
                $hasBreaking = true;
                if (isset($item['migration']) && is_string($item['migration']) && $item['migration'] !== '') {
                    $migrationNotes[] = $item['migration'];
                }
            }
        }

        return [
            'changes' => $changes,
            'hasBreaking' => $hasBreaking,
            'migrationNotes' => $migrationNotes,
        ];
    }
}
