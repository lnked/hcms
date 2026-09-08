<?php

declare(strict_types=1);

// Same-origin bridge for the download counter. The landing and the CMS are two
// vhosts of one shared-hosting account running different PHP versions, so the
// CMS kernel cannot be booted in-process here — the request is forwarded to the
// API host instead. Only the counter endpoint is reachable this way; the rest of
// the API stays on its own host.
//
// The API host must trust this server's IP via security.trusted_proxies,
// otherwise every visitor shares one rate-limit bucket.

$upstream = 'http://api.2js.ru/api/downloads';
$maxBody = 16384;

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

$query = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
$url = $query === '' ? $upstream : $upstream . '?' . $query;

$body = '';
if ($method === 'POST') {
    $body = (string) file_get_contents('php://input', false, null, 0, $maxBody + 1);
    if (strlen($body) > $maxBody) {
        http_response_code(413);
        exit;
    }
}

$headers = ['Accept: application/json'];
if (!empty($_SERVER['CONTENT_TYPE'])) {
    $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
}
// Set from REMOTE_ADDR, never from a client-supplied header: the visitor must
// not be able to pick which bucket the rate limiter charges.
if (!empty($_SERVER['REMOTE_ADDR'])) {
    $headers[] = 'X-Forwarded-For: ' . $_SERVER['REMOTE_ADDR'];
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
]);
if ($method === 'POST') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

$response = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

header('Content-Type: ' . ($type !== '' ? $type : 'application/json'));

if ($response === false || $status === 0) {
    http_response_code(502);
    echo '{"error":{"code":"UPSTREAM_UNAVAILABLE","message":"CMS is not reachable"}}';
    exit;
}

http_response_code($status);
echo $response;
