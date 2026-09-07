<?php

declare(strict_types=1);

use Cms\System\TreeVerifier;

/**
 * Standalone "can this tree serve requests?" check.
 *
 *   php scripts/verify-tree.php [/path/to/tree]
 *
 * Exits 0 when the tree boots, 1 with reasons on stderr when it cannot. The
 * release build runs it against the staged zip contents so a tree that cannot
 * boot never ships.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("verify-tree.php is a CLI tool\n");
}

$root = rtrim(str_replace('\\', '/', $argv[1] ?? dirname(__DIR__)), '/');

if (!is_file($root . '/src/autoload.php')) {
    fwrite(STDERR, 'FAIL: missing src/autoload.php in ' . $root . "\n");
    exit(1);
}

require $root . '/src/autoload.php';

$problems = array_merge(TreeVerifier::problems($root), TreeVerifier::bootProblems($root));
foreach ($problems as $problem) {
    fwrite(STDERR, 'FAIL: ' . $problem . "\n");
}
if ($problems !== []) {
    exit(1);
}

fwrite(STDOUT, 'OK: ' . $root . ' boots (version ' . trim((string) @file_get_contents($root . '/VERSION')) . ")\n");
