<?php

declare(strict_types=1);

/**
 * Builds the release manifest (latest.json) that installs and update checks read.
 *
 *   php scripts/latest-json.php --version=1.2.3 --zip=URL --sha256=HEX --out=PATH
 *
 * The manifest carries the newest changelog entries, not just the version: an
 * installed site only has the changelog up to its own version, so without them
 * the update screen cannot say what is about to arrive and the breaking-change
 * gate has nothing to trigger on.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("latest-json.php is a CLI tool\n");
}

/** How many past releases to ship; enough for anyone updating from far behind. */
const CMS_MANIFEST_RELEASES = 25;

/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $options[$key] = $value;
}

foreach (['version', 'zip', 'sha256', 'out'] as $required) {
    if (($options[$required] ?? '') === '') {
        fwrite(STDERR, 'latest-json.php: missing --' . $required . "\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$version = $options['version'];
$releases = [];

$decoded = json_decode((string) @file_get_contents($root . '/changelog.json'), true);
if (is_array($decoded) && is_array($decoded['releases'] ?? null)) {
    foreach ($decoded['releases'] as $release) {
        if (!is_array($release) || !is_string($release['version'] ?? null)) {
            continue;
        }
        $releases[] = [
            'version' => $release['version'],
            'date' => $release['date'] ?? null,
            'channel' => $release['channel'] ?? 'stable',
            'title' => $release['title'] ?? '',
            'changes' => is_array($release['changes'] ?? null) ? $release['changes'] : [],
        ];
    }
    usort($releases, static fn (array $a, array $b): int => version_compare((string) $b['version'], (string) $a['version']));
    $releases = array_slice($releases, 0, CMS_MANIFEST_RELEASES);
}

if ($releases === [] || $releases[0]['version'] !== $version) {
    fwrite(STDERR, 'latest-json.php: changelog.json has no entry for ' . $version . "\n");
    exit(1);
}

$manifest = [
    'version' => $version,
    'channel' => $releases[0]['channel'],
    'releasedAt' => gmdate('Y-m-d\TH:i:s\Z'),
    'zip' => $options['zip'],
    'sha256' => $options['sha256'],
    'changelog' => $releases,
];

if (file_put_contents(
    $options['out'],
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
) === false) {
    fwrite(STDERR, 'latest-json.php: cannot write ' . $options['out'] . "\n");
    exit(1);
}

fwrite(STDOUT, 'latest.json: ' . $version . ' with ' . count($releases) . " changelog entries\n");
