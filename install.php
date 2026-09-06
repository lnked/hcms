<?php

declare(strict_types=1);

$phpVersionId = defined('PHP_VERSION_ID') ? (int) PHP_VERSION_ID : 0;
if ($phpVersionId < 80300) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $current = defined('PHP_VERSION') ? PHP_VERSION : 'unknown';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"/>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"/>'
        . '<title>HCMS — PHP version</title><style>'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'font-family:system-ui,sans-serif;background:linear-gradient(160deg,#e8eef2 0%,#d5ebe6 45%,#e2e8f0 100%);'
        . 'color:#0f172a;padding:24px;box-sizing:border-box}'
        . '.box{max-width:420px;background:#fff;border:1px solid #cbd5e1;border-radius:16px;padding:28px 24px;'
        . 'box-shadow:0 12px 40px rgba(15,23,42,.08)}'
        . 'h1{font-size:28px;margin:0 0 4px;letter-spacing:-.02em}'
        . '.sub{color:#64748b;font-size:14px;margin:0 0 20px}'
        . 'p{margin:0 0 12px;line-height:1.5;font-size:15px}'
        . 'code{background:#f1f5f9;padding:2px 6px;border-radius:6px;font-size:13px}'
        . '.hint{color:#0d9488;font-size:13px;margin-top:16px}'
        . '</style></head><body><div class="box">'
        . '<h1>HCMS</h1><p class="sub">Installer</p>'
        . '<p><strong>HCMS requires PHP 8.3+</strong></p>'
        . '<p>This server is running <code>' . htmlspecialchars($current, ENT_QUOTES, 'UTF-8') . '</code>.</p>'
        . '<p class="hint">Update PHP in your hosting panel or switch the site to PHP 8.3+, then reload this page.</p>'
        . '</div></body></html>';
    exit(1);
}

const CMS_GITHUB_REPO = 'lnked/hcms';
const CMS_MIN_PHP = '8.3.0';

$root = __DIR__;
$lock = $root . '/storage/installed.lock';
$autoload = $root . '/vendor/autoload.php';
$action = $_GET['action'] ?? null;
/** @var array<string, mixed>|null $requestBody */
$requestBody = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $requestBody = $decoded;
        if (isset($decoded['action']) && is_string($decoded['action'])) {
            $action = $decoded['action'];
        }
    }
}

$wantsJson = $action !== null
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string) $_SERVER['HTTP_ACCEPT'], 'application/json') && $_SERVER['REQUEST_METHOD'] === 'POST');

if (is_file($lock) && $wantsJson && $action !== 'status') {
    cms_install_send(403, ['error' => ['code' => 'INSTALLED', 'message' => 'CMS is already installed']]);
}

if ($wantsJson) {
    cms_install_api($root, $autoload, $lock, is_string($action) ? $action : 'status', $requestBody);
}

if (is_file($lock)) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><body><p>CMS already installed. <a href="/admin">Open Admin Panel</a></p></body></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo cms_install_html();
exit;

/**
 * @param array<string, mixed> $payload
 *
 * @return never
 */
function cms_install_send(int $status, array $payload)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @param array<string, mixed>|null $requestBody
 *
 * @return never
 */
function cms_install_api(string $root, string $autoload, string $lock, string $action, ?array $requestBody = null)
{
    try {
        $force = is_array($requestBody) && !empty($requestBody['force']);

        if (is_file($autoload)) {
            require $autoload;
            $paths = new Cms\Core\Paths($root);
            $installer = new Cms\Install\Installer($paths);
            $downloader = new Cms\Install\ReleaseDownloader($paths);

            if ($action === 'status') {
                cms_install_send(200, $installer->status());
            }
            if ($action === 'latest') {
                cms_install_send(200, ['data' => $downloader->srcReady() ? ['skipped' => true] : $downloader->latestManifest()]);
            }
            if ($action === 'download') {
                cms_install_send(200, $downloader->download($force));
            }
            if ($action === 'test-connection') {
                $db = is_array($requestBody) && isset($requestBody['database']) && is_array($requestBody['database'])
                    ? $requestBody['database']
                    : [];
                $result = $installer->testConnection($db);
                cms_install_send($result['ok'] ? 200 : 400, $result);
            }
            if ($action === 'complete') {
                if (is_file($lock)) {
                    cms_install_send(403, ['error' => ['code' => 'INSTALLED', 'message' => 'CMS is already installed']]);
                }
                $installer->complete(is_array($requestBody) ? $requestBody : []);
                cms_install_send(200, ['ok' => true, 'adminUrl' => '/admin']);
            }

            cms_install_send(400, ['error' => ['code' => 'BAD_REQUEST', 'message' => 'Unknown action']]);
        }

        if ($action === 'status') {
            cms_install_send(200, [
                'installed' => is_file($lock),
                'srcReady' => is_file($root . '/src/bootstrap.php'),
                'version' => is_file($root . '/VERSION') ? trim((string) file_get_contents($root . '/VERSION')) : '0.0.0',
                'requirements' => cms_install_requirements($root),
            ]);
        }

        if ($action === 'download') {
            cms_install_inline_download($root, $force);
        }

        cms_install_send(409, [
            'error' => [
                'code' => 'FILES_MISSING',
                'message' => 'Download CMS files first or run composer install',
            ],
        ]);
    } catch (Throwable $e) {
        cms_install_send(400, ['error' => ['code' => 'INSTALL_ERROR', 'message' => $e->getMessage()]]);
    }
}

/**
 * @return array<string, mixed>
 */
function cms_install_requirements(string $root): array
{
    $checks = [
        'php' => version_compare(PHP_VERSION, CMS_MIN_PHP, '>='),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'json' => extension_loaded('json'),
        'mbstring' => extension_loaded('mbstring'),
        'zip' => class_exists(ZipArchive::class),
        'http' => function_exists('curl_init') || (bool) ini_get('allow_url_fopen'),
        'writable' => is_writable($root),
    ];

    return ['ok' => !in_array(false, $checks, true), 'phpVersion' => PHP_VERSION, 'checks' => $checks];
}

/** @return never */
function cms_install_inline_download(string $root, bool $force = false)
{
    if (!$force && is_file($root . '/src/bootstrap.php')) {
        cms_install_send(200, ['skipped' => true, 'reason' => 'src_present']);
    }

    $base = 'https://github.com/' . CMS_GITHUB_REPO . '/releases/latest/download/';
    $manifestRaw = cms_install_http($base . 'latest.json');
    $manifest = json_decode($manifestRaw, true);
    if (!is_array($manifest) || !isset($manifest['version']) || !is_string($manifest['version'])) {
        throw new RuntimeException('Invalid release manifest');
    }
    $version = $manifest['version'];
    $zipUrl = isset($manifest['zip']) && is_string($manifest['zip']) ? $manifest['zip'] : $base . 'cms-' . $version . '.zip';
    $expected = isset($manifest['sha256']) && is_string($manifest['sha256'])
        ? strtolower($manifest['sha256'])
        : strtolower(trim((string) preg_replace('/\s.*/', '', cms_install_http($base . 'cms-' . $version . '.zip.sha256'))));

    $storage = $root . '/storage';
    if (!is_dir($storage) && !mkdir($storage, 0775, true) && !is_dir($storage)) {
        throw new RuntimeException('Cannot create storage');
    }
    $tmp = $storage . '/cms-' . $version . '.zip';
    file_put_contents($tmp, cms_install_http($zipUrl));
    $actual = hash_file('sha256', $tmp);
    if ($actual === false || $expected === '' || !hash_equals($expected, $actual)) {
        @unlink($tmp);
        throw new RuntimeException('Release checksum mismatch');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Unable to open archive');
    }
    $zip->extractTo($root);
    $zip->close();
    @unlink($tmp);

    cms_install_send(200, [
        'skipped' => false,
        'version' => $version,
        'changelog' => $manifest['changelog'] ?? [],
    ]);
}

function cms_install_http(string $url): string
{
    $headers = ['User-Agent: hcms-installer'];
    $token = getenv('CMS_GITHUB_TOKEN') ?: getenv('GITHUB_TOKEN') ?: '';
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
        $headers[] = 'Accept: application/octet-stream';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $code >= 400) {
            throw new RuntimeException(
                'Download failed: ' . $url
                . ($code === 404
                    ? ' — repo private or release missing. Make lnked/hcms public, or set CMS_GITHUB_TOKEN.'
                    : ''),
            );
        }

        return $body;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'header' => implode("\r\n", $headers) . "\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if (!is_string($body)) {
        throw new RuntimeException('Download failed: ' . $url);
    }

    return $body;
}

function cms_install_html(): string
{
    return <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Install HCMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700&family=Source+Sans+3:wght@400;500;600&display=swap" rel="stylesheet"/>
  <style>
    :root {
      --ink: #0f172a;
      --ink-soft: #334155;
      --muted: #64748b;
      --line: #cbd5e1;
      --panel: rgba(255, 255, 255, 0.92);
      --teal: #0d9488;
      --teal-deep: #0f766e;
      --ok: #15803d;
      --err: #b91c1c;
      --fair: #d97706;
      --good: #0d9488;
      --strong: #0f766e;
      --weak: #dc2626;
      --radius: 16px;
      --font-ui: "Source Sans 3", system-ui, sans-serif;
      --font-display: Outfit, system-ui, sans-serif;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: var(--font-ui);
      color: var(--ink);
      background:
        radial-gradient(900px 480px at 12% -10%, rgba(13, 148, 136, 0.22), transparent 55%),
        radial-gradient(700px 420px at 92% 8%, rgba(51, 65, 85, 0.14), transparent 50%),
        linear-gradient(165deg, #e8eef2 0%, #d9ebe7 42%, #e2e8f0 100%);
      padding: 40px 16px 64px;
    }
    .wrap { max-width: 560px; margin: 0 auto; }
    .brand {
      margin-bottom: 28px;
      animation: fadeUp 0.45s ease both;
    }
    .brand h1 {
      font-family: var(--font-display);
      font-size: clamp(2.4rem, 8vw, 3.25rem);
      font-weight: 700;
      letter-spacing: -0.04em;
      line-height: 1;
      margin: 0;
    }
    .brand p {
      margin: 8px 0 0;
      color: var(--muted);
      font-size: 1.05rem;
    }
    .rail {
      display: flex;
      gap: 6px;
      margin-bottom: 18px;
      animation: fadeUp 0.5s ease 0.05s both;
    }
    .rail-item {
      flex: 1;
      text-align: center;
      padding: 10px 4px;
      border-radius: 12px;
      background: rgba(255,255,255,0.45);
      border: 1px solid transparent;
      color: var(--muted);
      font-size: 12px;
      font-weight: 600;
      transition: border-color 0.2s, color 0.2s, background 0.2s, box-shadow 0.2s;
    }
    .rail-item .n {
      display: block;
      font-family: var(--font-display);
      font-size: 13px;
      margin-bottom: 2px;
    }
    .rail-item.done { color: var(--teal-deep); background: rgba(13,148,136,0.1); }
    .rail-item.active {
      color: var(--ink);
      background: var(--panel);
      border-color: rgba(13,148,136,0.35);
      box-shadow: 0 0 0 3px rgba(13,148,136,0.12);
      animation: pulseSoft 1.8s ease infinite;
    }
    .panel {
      background: var(--panel);
      border: 1px solid rgba(148,163,184,0.45);
      border-radius: var(--radius);
      padding: 22px 20px 20px;
      backdrop-filter: blur(10px);
      box-shadow: 0 18px 50px rgba(15,23,42,0.08);
      animation: fadeUp 0.55s ease 0.1s both;
    }
    .step { display: none; }
    .step.active { display: block; animation: fadeIn 0.28s ease; }
    h2 {
      font-family: var(--font-display);
      font-size: 1.2rem;
      font-weight: 600;
      margin: 0 0 14px;
      letter-spacing: -0.02em;
    }
    .checks { display: grid; gap: 8px; margin-bottom: 18px; }
    .check {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 14px;
      color: var(--ink-soft);
    }
    .check .dot {
      width: 18px; height: 18px; border-radius: 50%;
      display: grid; place-items: center;
      font-size: 11px; font-weight: 700; color: #fff; flex-shrink: 0;
    }
    .check.ok .dot { background: var(--ok); }
    .check.fail .dot { background: var(--err); }
    .check.fail { color: var(--err); }
    label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      color: var(--ink-soft);
      margin: 12px 0 5px;
    }
    input {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid var(--line);
      border-radius: 10px;
      font: inherit;
      font-size: 15px;
      color: var(--ink);
      background: #fff;
      transition: border-color 0.15s, box-shadow 0.15s;
    }
    input:focus {
      outline: none;
      border-color: var(--teal);
      box-shadow: 0 0 0 3px rgba(13,148,136,0.18);
    }
    .row-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
    button {
      font: inherit;
      font-weight: 600;
      font-size: 14px;
      border: 0;
      border-radius: 10px;
      padding: 11px 16px;
      cursor: pointer;
      background: var(--ink);
      color: #fff;
      transition: transform 0.12s, opacity 0.15s, background 0.15s;
    }
    button:hover:not(:disabled) { background: #1e293b; }
    button:active:not(:disabled) { transform: translateY(1px); }
    button:disabled { opacity: 0.55; cursor: not-allowed; }
    button.secondary {
      background: #fff;
      color: var(--ink);
      border: 1px solid var(--line);
    }
    button.secondary:hover:not(:disabled) { background: #f8fafc; }
    button.ghost {
      background: transparent;
      color: var(--teal-deep);
      border: 1px solid rgba(13,148,136,0.35);
      padding: 8px 12px;
      font-size: 13px;
    }
    .field-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      margin-top: 12px;
    }
    .field-head label { margin: 0; }
    .field-actions { display: flex; gap: 6px; }
    .progress-wrap {
      display: none;
      margin-top: 16px;
    }
    .progress-wrap.on { display: block; }
    .progress-track {
      height: 8px;
      border-radius: 999px;
      background: #e2e8f0;
      overflow: hidden;
    }
    .progress-fill {
      height: 100%;
      width: 0%;
      border-radius: inherit;
      background: linear-gradient(90deg, var(--teal), var(--teal-deep));
      transition: width 0.35s ease;
    }
    .progress-fill.indeterminate {
      width: 40% !important;
      animation: indeterminate 1.1s ease-in-out infinite;
    }
    .progress-label {
      margin: 8px 0 0;
      font-size: 13px;
      color: var(--muted);
    }
    .status { margin-top: 12px; font-size: 14px; min-height: 1.2em; }
    .status.ok { color: var(--ok); }
    .status.err { color: var(--err); }
    .status.muted { color: var(--muted); }
    .strength { margin-top: 8px; }
    .strength-track {
      height: 6px;
      border-radius: 999px;
      background: #e2e8f0;
      overflow: hidden;
    }
    .strength-fill {
      height: 100%;
      width: 0%;
      border-radius: inherit;
      transition: width 0.25s ease, background 0.25s ease;
    }
    .strength-fill.weak { width: 25%; background: var(--weak); }
    .strength-fill.fair { width: 50%; background: var(--fair); }
    .strength-fill.good { width: 75%; background: var(--good); }
    .strength-fill.strong { width: 100%; background: var(--strong); }
    .strength-label {
      margin: 6px 0 0;
      font-size: 12px;
      font-weight: 600;
      color: var(--muted);
    }
    .strength-label.weak { color: var(--weak); }
    .strength-label.fair { color: var(--fair); }
    .strength-label.good { color: var(--good); }
    .strength-label.strong { color: var(--strong); }
    .toast {
      position: fixed;
      bottom: 24px;
      left: 50%;
      transform: translateX(-50%) translateY(12px);
      background: var(--ink);
      color: #fff;
      padding: 10px 16px;
      border-radius: 10px;
      font-size: 13px;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.2s, transform 0.2s;
      z-index: 20;
    }
    .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
    .done-panel { text-align: center; padding: 12px 0 4px; }
    .done-panel h2 { color: var(--ok); font-size: 1.45rem; }
    .done-panel a {
      display: inline-block;
      margin-top: 12px;
      color: #fff;
      background: var(--ink);
      text-decoration: none;
      padding: 11px 18px;
      border-radius: 10px;
      font-weight: 600;
    }
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: none; }
    }
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    @keyframes pulseSoft {
      0%, 100% { box-shadow: 0 0 0 3px rgba(13,148,136,0.12); }
      50% { box-shadow: 0 0 0 5px rgba(13,148,136,0.18); }
    }
    @keyframes indeterminate {
      0% { transform: translateX(-120%); }
      100% { transform: translateX(280%); }
    }
    @media (max-width: 480px) {
      .rail-item .lbl { display: none; }
      .rail-item { padding: 10px 2px; }
      body { padding-top: 28px; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <header class="brand">
      <h1>HCMS</h1>
      <p>Install</p>
    </header>
    <nav class="rail" id="rail" aria-label="Install steps">
      <div class="rail-item active" data-step="0"><span class="n">1</span><span class="lbl">Files</span></div>
      <div class="rail-item" data-step="1"><span class="n">2</span><span class="lbl">Database</span></div>
      <div class="rail-item" data-step="2"><span class="n">3</span><span class="lbl">App</span></div>
      <div class="rail-item" data-step="3"><span class="n">4</span><span class="lbl">Admin</span></div>
    </nav>
    <div class="panel">
      <div id="s0" class="step active">
        <h2>System check</h2>
        <div id="req" class="checks"></div>
        <div class="row-actions">
          <button type="button" id="btnDownload">Download latest / continue</button>
          <button type="button" id="btnForceDl" class="secondary" style="display:none">Re-download files</button>
        </div>
        <div id="dlProgress" class="progress-wrap">
          <div class="progress-track"><div id="dlFill" class="progress-fill"></div></div>
          <p id="dlLabel" class="progress-label"></p>
        </div>
        <p id="dlLog" class="status muted"></p>
        <div class="row-actions" id="dlRetryWrap" style="display:none">
          <button type="button" id="btnRetry" class="secondary">Retry download</button>
        </div>
      </div>
      <div id="s1" class="step">
        <h2>Database</h2>
        <label for="dbHost">Host</label><input id="dbHost" value="127.0.0.1"/>
        <label for="dbPort">Port</label><input id="dbPort" value="3306"/>
        <label for="dbName">Name</label><input id="dbName" value="hcms"/>
        <label for="dbUser">User</label><input id="dbUser" value="root"/>
        <label for="dbPass">Password</label><input id="dbPass" type="password"/>
        <label for="dbCharset">Charset</label><input id="dbCharset" value="utf8mb4"/>
        <div class="row-actions">
          <button type="button" id="btnTest" class="secondary">Test connection</button>
          <button type="button" id="btnDb">Continue</button>
        </div>
        <p id="dbMsg" class="status"></p>
      </div>
      <div id="s2" class="step">
        <h2>Application</h2>
        <label for="appName">Name</label><input id="appName" value="HCMS"/>
        <label for="appUrl">URL</label><input id="appUrl"/>
        <label for="appTz">Timezone</label><input id="appTz" value="UTC"/>
        <label for="appLang">Language</label>
        <select id="appLang">
          <option value="en" selected>English</option>
          <option value="ru">Русский</option>
        </select>
        <label for="appPublicDir">Web root folder</label>
        <input id="appPublicDir" list="publicDirList" value="public" placeholder="public"/>
        <datalist id="publicDirList">
          <option value="public"></option>
          <option value="public_html"></option>
          <option value="www"></option>
          <option value="htdocs"></option>
        </datalist>
        <p class="hint">Hosting document root must point to this folder. Admin URL = <code>/admin</code>.</p>
        <div class="row-actions">
          <button type="button" id="btnApp">Continue</button>
        </div>
      </div>
      <div id="s3" class="step">
        <h2>Administrator</h2>
        <label for="admName">Name</label><input id="admName"/>
        <label for="admEmail">Email</label><input id="admEmail" type="email"/>
        <div class="field-head">
          <label for="admPass">Password</label>
          <div class="field-actions">
            <button type="button" id="btnGenPass" class="ghost">Generate</button>
            <button type="button" id="btnCopyPass" class="ghost">Copy</button>
          </div>
        </div>
        <input id="admPass" type="password" autocomplete="new-password"/>
        <div class="strength" id="strengthBox" hidden>
          <div class="strength-track"><div id="strengthFill" class="strength-fill"></div></div>
          <p id="strengthLabel" class="strength-label"></p>
        </div>
        <label for="admPass2">Confirm</label><input id="admPass2" type="password" autocomplete="new-password"/>
        <div class="row-actions">
          <button type="button" id="btnInstall">Install</button>
        </div>
        <div id="instProgress" class="progress-wrap">
          <div class="progress-track"><div id="instFill" class="progress-fill"></div></div>
          <p id="instLabel" class="progress-label"></p>
        </div>
        <p id="instMsg" class="status"></p>
      </div>
      <div id="s4" class="step">
        <div class="done-panel">
          <h2>Installation completed</h2>
          <p class="status muted">CMS is ready.</p>
          <a href="/admin">Open Admin Panel</a>
        </div>
      </div>
    </div>
  </div>
  <div id="toast" class="toast" role="status">Copied</div>
  <script>
    const $ = (id) => document.getElementById(id);
    const reqLabels = {
      php: 'PHP 8.3+',
      pdo_mysql: 'PDO MySQL',
      json: 'JSON',
      mbstring: 'mbstring',
      zip: 'ZipArchive',
      http: 'HTTP downloads',
      writable: 'Writable directory'
    };
    let wizardStep = 0;
    let reqOk = false;
    let progressTimer = null;

    function setWizardStep(n) {
      wizardStep = n;
      document.querySelectorAll('.step').forEach((el, i) => {
        el.classList.toggle('active', i === n);
      });
      document.querySelectorAll('.rail-item').forEach((el) => {
        const s = Number(el.dataset.step);
        el.classList.toggle('active', s === Math.min(n, 3));
        el.classList.toggle('done', s < n || n === 4);
        if (n === 4) el.classList.remove('active');
      });
    }

    function toast(msg) {
      const t = $('toast');
      t.textContent = msg;
      t.classList.add('show');
      setTimeout(() => t.classList.remove('show'), 1600);
    }

    function setProgress(wrapId, fillId, labelId, pct, label, indeterminate) {
      const wrap = $(wrapId);
      const fill = $(fillId);
      wrap.classList.add('on');
      fill.classList.toggle('indeterminate', !!indeterminate);
      if (!indeterminate) fill.style.width = Math.max(0, Math.min(100, pct)) + '%';
      $(labelId).textContent = label || '';
    }

    function resetProgress(wrapId, fillId, labelId) {
      clearInterval(progressTimer);
      progressTimer = null;
      const wrap = $(wrapId);
      const fill = $(fillId);
      wrap.classList.remove('on');
      fill.classList.remove('indeterminate');
      fill.style.width = '0%';
      $(labelId).textContent = '';
    }

    function runPhasedProgress(wrapId, fillId, labelId, phases) {
      let i = 0;
      clearInterval(progressTimer);
      const tick = () => {
        const p = phases[Math.min(i, phases.length - 1)];
        setProgress(wrapId, fillId, labelId, p.pct, p.label, !!p.indeterminate);
        if (i < phases.length - 1) i += 1;
      };
      tick();
      progressTimer = setInterval(tick, 900);
    }

    async function api(action, body = {}) {
      const res = await fetch('install.php?action=' + encodeURIComponent(action), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ action, ...body })
      });
      let data = {};
      try { data = await res.json(); } catch (_) {}
      if (!res.ok) {
        const msg = data.error?.message || data.error || 'Request failed';
        throw new Error(typeof msg === 'string' ? msg : 'Request failed');
      }
      return data;
    }

    function renderRequirements(checks) {
      const order = ['php', 'pdo_mysql', 'json', 'mbstring', 'zip', 'http', 'writable'];
      $('req').innerHTML = order.map((k) => {
        const ok = !!(checks && checks[k]);
        return `<div class="check ${ok ? 'ok' : 'fail'}"><span class="dot">${ok ? '✓' : '!'}</span><span>${reqLabels[k] || k}</span></div>`;
      }).join('');
      reqOk = order.every((k) => !!(checks && checks[k]));
      $('btnDownload').disabled = !reqOk;
      if (!reqOk) {
        $('dlLog').className = 'status err';
        $('dlLog').textContent = 'Fix failed requirements before continuing.';
      }
    }

    function friendlyDownloadError(msg) {
      if (/latest\.json|404|release missing|private/i.test(msg)) {
        return 'Could not fetch the latest release. Publish a GitHub Release with latest.json, or check network access.';
      }
      return msg;
    }

    async function doDownload(force = false) {
      $('dlLog').textContent = '';
      $('dlLog').className = 'status muted';
      $('dlRetryWrap').style.display = 'none';
      $('btnDownload').disabled = true;
      $('btnForceDl').disabled = true;
      $('btnRetry').disabled = true;
      try {
        runPhasedProgress('dlProgress', 'dlFill', 'dlLabel', [
          { pct: 8, label: 'Checking…' },
          { pct: 18, label: 'Fetching release…' },
          { pct: 45, label: 'Downloading…', indeterminate: true },
          { pct: 78, label: 'Verifying…', indeterminate: true },
          { pct: 90, label: 'Extracting…', indeterminate: true }
        ]);
        if (!force) {
          const status = await api('status');
          if (status.srcReady) {
            clearInterval(progressTimer);
            setProgress('dlProgress', 'dlFill', 'dlLabel', 100, 'Files already present');
            $('dlLog').className = 'status ok';
            $('dlLog').textContent = 'Files already present — continue, or re-download to refresh.';
            $('btnForceDl').style.display = '';
            $('btnDownload').disabled = !reqOk;
            $('btnForceDl').disabled = !reqOk;
            setTimeout(() => setWizardStep(1), 350);
            return;
          }
        }
        try {
          await api('latest');
          setProgress('dlProgress', 'dlFill', 'dlLabel', 22, 'Fetching release…');
        } catch (_) { /* optional when vendor missing */ }
        const r = await api('download', { force: !!force });
        clearInterval(progressTimer);
        setProgress('dlProgress', 'dlFill', 'dlLabel', 100, 'Done');
        $('dlLog').className = 'status ok';
        $('dlLog').textContent = r.skipped ? 'Files already present' : ('Downloaded ' + r.version);
        $('btnForceDl').style.display = '';
        setTimeout(() => setWizardStep(1), 350);
      } catch (e) {
        clearInterval(progressTimer);
        resetProgress('dlProgress', 'dlFill', 'dlLabel');
        $('dlLog').className = 'status err';
        $('dlLog').textContent = friendlyDownloadError(e.message || 'Download failed');
        $('dlRetryWrap').style.display = 'flex';
        $('btnDownload').disabled = !reqOk;
        $('btnForceDl').disabled = !reqOk;
        $('btnRetry').disabled = false;
      }
    }

    function passwordStrength(pw) {
      if (!pw) return { level: '', label: '' };
      const len = pw.length;
      const lower = /[a-z]/.test(pw);
      const upper = /[A-Z]/.test(pw);
      const digit = /\d/.test(pw);
      const symbol = /[^A-Za-z0-9]/.test(pw);
      const classes = [lower, upper, digit, symbol].filter(Boolean).length;
      let score = classes;
      if (len >= 8) score += 1;
      if (len >= 12) score += 1;
      if (len >= 16) score += 1;
      if (/(.)\1{2,}/.test(pw)) score -= 1;
      if (/012|123|234|345|456|567|678|789|abc|bcd|cde/i.test(pw)) score -= 1;
      if (len < 8) return { level: 'weak', label: 'Weak' };
      if (score <= 3 || (len < 12 && classes < 3)) return { level: 'fair', label: 'Fair' };
      if (score <= 5 || classes < 4 || len < 16) return { level: 'good', label: 'Good' };
      return { level: 'strong', label: 'Strong' };
    }

    function updateStrength() {
      const pw = $('admPass').value;
      const box = $('strengthBox');
      const fill = $('strengthFill');
      const label = $('strengthLabel');
      if (!pw) {
        box.hidden = true;
        return;
      }
      const s = passwordStrength(pw);
      box.hidden = false;
      fill.className = 'strength-fill ' + s.level;
      label.className = 'strength-label ' + s.level;
      label.textContent = s.label;
    }

    function generatePassword() {
      const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*-_=+';
      const bytes = new Uint8Array(20);
      crypto.getRandomValues(bytes);
      let out = '';
      for (let i = 0; i < bytes.length; i++) out += alphabet[bytes[i] % alphabet.length];
      $('admPass').type = 'text';
      $('admPass2').type = 'text';
      $('admPass').value = out;
      $('admPass2').value = out;
      updateStrength();
    }

    async function copyPassword() {
      const pw = $('admPass').value;
      if (!pw) return;
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(pw);
        } else {
          const ta = document.createElement('textarea');
          ta.value = pw;
          document.body.appendChild(ta);
          ta.select();
          document.execCommand('copy');
          ta.remove();
        }
        toast('Copied');
      } catch (_) {
        toast('Copy failed');
      }
    }

    $('appUrl').value = location.origin;

    api('status').then((s) => {
      renderRequirements(s.requirements?.checks || {});
      if (s.installed) { location.href = '/admin'; return; }
      if (s.srcReady) {
        $('dlLog').className = 'status ok';
        $('dlLog').textContent = 'Files already present — continue, or re-download to refresh.';
        $('btnForceDl').style.display = '';
        $('btnDownload').textContent = 'Continue';
      }
    }).catch((e) => {
      $('req').innerHTML = `<div class="check fail"><span class="dot">!</span><span>${e.message}</span></div>`;
      $('btnDownload').disabled = true;
    });

    $('btnDownload').onclick = () => doDownload(false);
    $('btnForceDl').onclick = () => doDownload(true);
    $('btnRetry').onclick = () => doDownload(true);

    const db = () => ({
      host: $('dbHost').value, port: Number($('dbPort').value), name: $('dbName').value,
      user: $('dbUser').value, password: $('dbPass').value, charset: $('dbCharset').value
    });

    $('btnTest').onclick = async () => {
      try {
        const r = await api('test-connection', { database: db() });
        $('dbMsg').className = r.ok ? 'status ok' : 'status err';
        $('dbMsg').textContent = r.ok ? 'Connection successful' : (r.error || 'Failed');
      } catch (e) {
        $('dbMsg').className = 'status err';
        $('dbMsg').textContent = e.message;
      }
    };
    $('btnDb').onclick = () => setWizardStep(2);
    $('btnApp').onclick = () => setWizardStep(3);

    $('btnGenPass').onclick = () => generatePassword();
    $('btnCopyPass').onclick = () => copyPassword();
    $('admPass').addEventListener('input', updateStrength);

    $('btnInstall').onclick = async () => {
      $('instMsg').textContent = '';
      $('instMsg').className = 'status';
      $('btnInstall').disabled = true;
      try {
        runPhasedProgress('instProgress', 'instFill', 'instLabel', [
          { pct: 15, label: 'Validating…' },
          { pct: 40, label: 'Writing config…' },
          { pct: 70, label: 'Migrating…', indeterminate: true },
          { pct: 90, label: 'Creating admin…', indeterminate: true }
        ]);
        await api('complete', {
          database: db(),
          application: {
            name: $('appName').value,
            url: $('appUrl').value,
            timezone: $('appTz').value,
            language: $('appLang').value,
            publicDir: $('appPublicDir').value || 'public'
          },
          administrator: {
            name: $('admName').value,
            email: $('admEmail').value,
            password: $('admPass').value,
            passwordConfirm: $('admPass2').value
          }
        });
        clearInterval(progressTimer);
        setProgress('instProgress', 'instFill', 'instLabel', 100, 'Done');
        setWizardStep(4);
      } catch (e) {
        clearInterval(progressTimer);
        resetProgress('instProgress', 'instFill', 'instLabel');
        $('instMsg').className = 'status err';
        $('instMsg').textContent = e.message;
        $('btnInstall').disabled = false;
      }
    };
  </script>
</body>
</html>
HTML;
}
