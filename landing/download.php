<?php

declare(strict_types=1);

/**
 * GET /download?source=hero — counts a real download, then 302s to GitHub.
 *
 * Counting server-side is the whole point: the row is built here from
 * REMOTE_ADDR, the Referer header and server time, so forging a click costs an
 * actual download instead of one line of curl against a public create endpoint.
 *
 * See docs/landing-downloads.md
 */

require __DIR__ . '/counter.php';

$config = counter_config();
$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
$source = counter_source(isset($_GET['source']) ? (string) $_GET['source'] : '');

if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

header('Location: ' . counter_download_url(), true, 302);
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('Content-Length: 0');
header('Connection: close');

counter_close_response();

if ($method !== 'GET' || counter_is_prefetch() || counter_is_bot()) {
    exit;
}

if (!counter_throttle('click:' . counter_client_ip(), (int) $config['clicksPerIpPerMinute'], 60)) {
    exit;
}

$release = counter_release();

counter_api('POST', '/api/' . $config['slug'], [
    'asset' => 'install.php',
    'version' => $release['version'],
    'source' => $source,
    'referrer' => counter_referrer_host(),
    'date' => date('Y-m-d H:i:s'),
]);
