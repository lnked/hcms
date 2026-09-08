<?php

declare(strict_types=1);

/**
 * GET /api/downloads — the counter value, and nothing else.
 *
 * The landing and the CMS are two vhosts of one shared-hosting account running
 * different PHP versions, so the CMS kernel cannot be booted in-process here.
 * Reading through this shim keeps the API token on the server and keeps the
 * stored rows — attacker-controllable text, in the old public-create design —
 * off the landing origin entirely. Writes live in download.php.
 */

require __DIR__ . '/counter.php';

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

header('Content-Type: application/json; charset=utf-8');

if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo '{"error":"METHOD_NOT_ALLOWED"}';
    exit;
}

$total = counter_total();
if ($total === null) {
    http_response_code(503);
    header('Cache-Control: no-store');
    echo '{"error":"UPSTREAM_UNAVAILABLE"}';
    exit;
}

header('Cache-Control: public, max-age=60');
echo (string) json_encode(['total' => $total]);
