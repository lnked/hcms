<?php

declare(strict_types=1);

/**
 * Shared server side of the landing download counter.
 *
 * The browser never writes to the API: /download counts a real click and 302s
 * to GitHub, /api/downloads only reads back a number. Both talk to the CMS with
 * a token that stays on this host, so the `downloads` resource keeps every
 * public flag off and nothing user-controlled ends up in a stored row.
 *
 * PHP 7.2 target: the landing vhost runs an older interpreter than the CMS.
 */

if (PHP_SAPI !== 'cli' && basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

/**
 * @return array<string, mixed>
 */
function counter_config(): array
{
    static $config;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'api' => 'http://api.2js.ru',
        'slug' => 'downloads',
        'token' => '',
        'repo' => 'lnked/hcms',
        'cacheDir' => sys_get_temp_dir() . '/hcms-downloads',
        'totalTtl' => 60,
        'releaseTtl' => 900,
        'clicksPerIpPerMinute' => 10,
        'timeout' => 5,
    ];

    $file = __DIR__ . '/counter-config.php';
    if (is_file($file)) {
        $local = require $file;
        if (is_array($local)) {
            $config = array_merge($config, $local);
        }
    }

    foreach (['HCMS_API_BASE' => 'api', 'HCMS_DOWNLOADS_TOKEN' => 'token'] as $env => $key) {
        $value = getenv($env);
        if (is_string($value) && $value !== '') {
            $config[$key] = $value;
        }
    }
    $config['api'] = rtrim((string) $config['api'], '/');

    return $config;
}

/**
 * Whitelist, not passthrough: `source` is the only field a visitor influences.
 */
function counter_source(string $raw): string
{
    $allowed = ['nav', 'hero', 'cta', 'curl', 'button'];

    return in_array($raw, $allowed, true) ? $raw : 'button';
}

/**
 * REMOTE_ADDR only — a client-supplied header must not pick the rate bucket.
 */
function counter_client_ip(): string
{
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
}

function counter_referrer_host(): string
{
    $raw = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
    if ($raw === '') {
        return '';
    }
    $host = parse_url($raw, PHP_URL_HOST);

    return is_string($host) ? substr(strtolower($host), 0, 190) : '';
}

function counter_is_prefetch(): bool
{
    foreach (['HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ', 'HTTP_SEC_PURPOSE'] as $key) {
        $value = isset($_SERVER[$key]) ? strtolower((string) $_SERVER[$key]) : '';
        if ($value === '') {
            continue;
        }
        if (strpos($value, 'prefetch') !== false || strpos($value, 'prerender') !== false || strpos($value, 'preview') !== false) {
            return true;
        }
    }

    return false;
}

/**
 * curl and wget are real installs and must count; link unfurlers and monitors
 * are not.
 */
function counter_is_bot(): bool
{
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    if (trim($ua) === '') {
        return true;
    }

    return preg_match('#(bot|crawl|spider|slurp|facebookexternalhit|embedly|preview|monitor|uptime|pingdom|headless|scanner)#i', $ua) === 1;
}

function counter_cache_dir(): string
{
    $config = counter_config();
    $dir = (string) $config['cacheDir'];
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    return $dir;
}

/**
 * @return mixed|null
 */
function counter_cache_get(string $key, int $ttl)
{
    $path = counter_cache_dir() . '/' . sha1($key) . '.json';
    if (!is_file($path) || (int) @filemtime($path) + $ttl < time()) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    return json_decode($raw, true);
}

/**
 * @param mixed $value
 */
function counter_cache_put(string $key, $value): void
{
    $path = counter_cache_dir() . '/' . sha1($key) . '.json';
    @file_put_contents($path, (string) json_encode($value), LOCK_EX);
}

/**
 * Fixed-window bucket on local disk: the CMS-side limiter cannot see the real
 * visitor IP through this proxy, so throttling has to happen where REMOTE_ADDR
 * is still true. Fails open — a broken cache dir must not block downloads.
 */
function counter_throttle(string $key, int $limit, int $window): bool
{
    if ($limit < 1) {
        return true;
    }

    $path = counter_cache_dir() . '/rl-' . sha1($key) . '.txt';
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return true;
    }

    $allowed = true;
    if (flock($handle, LOCK_EX)) {
        $slot = (int) floor(time() / $window);
        $parts = explode(':', (string) stream_get_contents($handle));
        $hits = (count($parts) === 2 && (int) $parts[0] === $slot) ? (int) $parts[1] : 0;
        $allowed = $hits < $limit;
        if ($allowed) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $slot . ':' . ($hits + 1));
        }
        flock($handle, LOCK_UN);
    }
    fclose($handle);

    return $allowed;
}

/**
 * @param list<string> $headers
 * @return array{0: int, 1: string}
 */
function counter_http(string $method, string $url, ?string $body, array $headers, int $timeout): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return [0, ''];
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$status, is_string($raw) ? $raw : ''];
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body === null ? '' : $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    // Read the magic local indirectly: naming it outright is deprecated on 8.4,
    // and its replacement does not exist on the 7.2 this vhost runs.
    $locals = get_defined_vars();
    $responseHeaders = isset($locals['http_response_header']) && is_array($locals['http_response_header'])
        ? $locals['http_response_header']
        : [];
    foreach ($responseHeaders as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
            $status = (int) $m[1];
        }
    }

    return [$status, is_string($raw) ? $raw : ''];
}

/**
 * @param array<string, mixed>|null $payload
 * @return array<string, mixed>|null
 */
function counter_api(string $method, string $path, ?array $payload = null): ?array
{
    $config = counter_config();
    if ((string) $config['token'] === '') {
        return null;
    }

    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $config['token']];
    $body = null;
    if ($payload !== null) {
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    }
    // Only useful once the CMS trusts this host via security.trusted_proxies.
    $ip = counter_client_ip();
    if ($ip !== '') {
        $headers[] = 'X-Forwarded-For: ' . $ip;
    }

    [$status, $raw] = counter_http($method, $config['api'] . $path, $body, $headers, (int) $config['timeout']);
    if ($status < 200 || $status >= 300) {
        return null;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @return array{version: string, asset: bool}
 */
function counter_release(): array
{
    $config = counter_config();
    $cached = counter_cache_get('release', (int) $config['releaseTtl']);
    if (is_array($cached) && isset($cached['version'])) {
        return ['version' => (string) $cached['version'], 'asset' => !empty($cached['asset'])];
    }

    $headers = ['Accept: application/vnd.github+json', 'User-Agent: hcms-landing'];
    $url = 'https://api.github.com/repos/' . $config['repo'] . '/releases/latest';
    [$status, $raw] = counter_http('GET', $url, null, $headers, (int) $config['timeout']);
    if ($status !== 200) {
        return ['version' => '', 'asset' => true];
    }

    $data = json_decode($raw, true);
    $tag = is_array($data) && isset($data['tag_name']) && is_string($data['tag_name']) ? $data['tag_name'] : '';
    $assets = is_array($data) && isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : [];
    $hasInstall = false;
    foreach ($assets as $asset) {
        if (is_array($asset) && isset($asset['name']) && $asset['name'] === 'install.php') {
            $hasInstall = true;
            break;
        }
    }

    $release = ['version' => ltrim($tag, 'v'), 'asset' => $hasInstall];
    counter_cache_put('release', $release);

    return $release;
}

function counter_download_url(): string
{
    $config = counter_config();
    $cached = counter_cache_get('release', (int) $config['releaseTtl']);
    $hasAsset = is_array($cached) ? !empty($cached['asset']) : true;

    return $hasAsset
        ? 'https://github.com/' . $config['repo'] . '/releases/latest/download/install.php'
        : 'https://github.com/' . $config['repo'] . '/raw/main/install.php';
}

/**
 * Only the number leaves this host: the rows themselves stay behind the token,
 * so a stored string can never be served from the landing origin.
 */
function counter_total(): ?int
{
    $config = counter_config();
    $cached = counter_cache_get('total', (int) $config['totalTtl']);
    if (is_int($cached)) {
        return $cached;
    }

    $response = counter_api('GET', '/api/' . $config['slug'] . '?limit=1');
    $total = isset($response['meta']['total']) ? $response['meta']['total'] : null;
    if (!is_int($total)) {
        return null;
    }
    counter_cache_put('total', $total);

    return $total;
}

/**
 * Flush the response before talking to the CMS: the visitor is already on their
 * way to GitHub, counting must not sit in front of the redirect.
 */
function counter_close_response(): void
{
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();

        return;
    }

    ignore_user_abort(true);
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    flush();
}
