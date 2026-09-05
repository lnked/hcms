<?php

declare(strict_types=1);

const CMS_GITHUB_REPO = 'lnked/hcms';

$root = __DIR__;
$lock = $root . '/storage/installed.lock';
$autoload = $root . '/vendor/autoload.php';
$action = $_GET['action'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($input) && isset($input['action']) && is_string($input['action'])) {
        $action = $input['action'];
    }
}

$wantsJson = $action !== null
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string) $_SERVER['HTTP_ACCEPT'], 'application/json') && $_SERVER['REQUEST_METHOD'] === 'POST');

if (is_file($lock) && $wantsJson && $action !== 'status') {
    cms_install_send(403, ['error' => ['code' => 'INSTALLED', 'message' => 'CMS is already installed']]);
}

if ($wantsJson) {
    cms_install_api($root, $autoload, $lock, is_string($action) ? $action : 'status');
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
 */
function cms_install_send(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function cms_install_api(string $root, string $autoload, string $lock, string $action): never
{
    try {
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
                cms_install_send(200, $downloader->download());
            }
            if ($action === 'test-connection') {
                $input = json_decode((string) file_get_contents('php://input'), true);
                $db = is_array($input) && isset($input['database']) && is_array($input['database']) ? $input['database'] : [];
                $result = $installer->testConnection($db);
                cms_install_send($result['ok'] ? 200 : 400, $result);
            }
            if ($action === 'complete') {
                if (is_file($lock)) {
                    cms_install_send(403, ['error' => ['code' => 'INSTALLED', 'message' => 'CMS is already installed']]);
                }
                $input = json_decode((string) file_get_contents('php://input'), true);
                $installer->complete(is_array($input) ? $input : []);
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
            cms_install_inline_download($root);
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
        'php' => version_compare(PHP_VERSION, '8.3.0', '>='),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'json' => extension_loaded('json'),
        'mbstring' => extension_loaded('mbstring'),
        'zip' => class_exists(ZipArchive::class),
        'http' => function_exists('curl_init') || (bool) ini_get('allow_url_fopen'),
        'writable' => is_writable($root),
    ];

    return ['ok' => !in_array(false, $checks, true), 'phpVersion' => PHP_VERSION, 'checks' => $checks];
}

function cms_install_inline_download(string $root): never
{
    if (is_file($root . '/src/bootstrap.php')) {
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
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'hcms-installer',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $code >= 400) {
            throw new RuntimeException('Download failed: ' . $url);
        }

        return $body;
    }

    $body = @file_get_contents($url);
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
  <style>
    :root { font-family: ui-sans-serif, system-ui, sans-serif; color: #18181b; background: #fafafa; }
    body { max-width: 560px; margin: 48px auto; padding: 0 16px; }
    h1 { font-size: 22px; margin-bottom: 8px; }
    .card { background: #fff; border: 1px solid #e4e4e7; border-radius: 12px; padding: 20px; }
    label { display: block; font-size: 13px; margin: 12px 0 4px; }
    input { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #d4d4d8; border-radius: 8px; }
    button { margin-top: 16px; background: #18181b; color: #fff; border: 0; border-radius: 8px; padding: 10px 14px; cursor: pointer; }
    button.secondary { background: #fff; color: #18181b; border: 1px solid #d4d4d8; }
    .muted { color: #71717a; font-size: 13px; }
    .ok { color: #15803d; }
    .err { color: #b91c1c; }
    .step { display: none; }
    .step.active { display: block; }
  </style>
</head>
<body>
  <h1>Install HCMS</h1>
  <p class="muted">One-file bootstrap → latest release → database → admin.</p>
  <div class="card">
    <div id="s0" class="step active">
      <p id="req"></p>
      <button type="button" id="btnDownload">Download latest / continue</button>
      <pre id="dlLog" class="muted"></pre>
    </div>
    <div id="s1" class="step">
      <h2>Database</h2>
      <label>Host</label><input id="dbHost" value="127.0.0.1"/>
      <label>Port</label><input id="dbPort" value="3306"/>
      <label>Name</label><input id="dbName" value="hcms"/>
      <label>User</label><input id="dbUser" value="root"/>
      <label>Password</label><input id="dbPass" type="password"/>
      <label>Charset</label><input id="dbCharset" value="utf8mb4"/>
      <button type="button" id="btnTest" class="secondary">Test connection</button>
      <button type="button" id="btnDb">Continue</button>
      <p id="dbMsg"></p>
    </div>
    <div id="s2" class="step">
      <h2>Application</h2>
      <label>Name</label><input id="appName" value="HCMS"/>
      <label>URL</label><input id="appUrl"/>
      <label>Timezone</label><input id="appTz" value="UTC"/>
      <label>Language</label><input id="appLang" value="en"/>
      <button type="button" id="btnApp">Continue</button>
    </div>
    <div id="s3" class="step">
      <h2>Administrator</h2>
      <label>Name</label><input id="admName"/>
      <label>Email</label><input id="admEmail" type="email"/>
      <label>Password</label><input id="admPass" type="password"/>
      <label>Confirm</label><input id="admPass2" type="password"/>
      <button type="button" id="btnInstall">Install</button>
      <p id="instMsg"></p>
    </div>
    <div id="s4" class="step">
      <h2 class="ok">Installation completed</h2>
      <p><a href="/admin">Open Admin Panel</a></p>
    </div>
  </div>
  <script>
    const $ = (id) => document.getElementById(id);
    const show = (id) => document.querySelectorAll('.step').forEach((el) => el.classList.toggle('active', el.id === id));
    $('appUrl').value = location.origin;
    async function api(action, body = {}) {
      const res = await fetch('install.php?action=' + encodeURIComponent(action), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ action, ...body })
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error?.message || data.error || 'Request failed');
      return data;
    }
    api('status').then((s) => {
      const c = s.requirements?.checks || {};
      $('req').innerHTML = Object.entries(c).map(([k,v]) => `<div class="${v?'ok':'err'}">${k}: ${v?'ok':'fail'}</div>`).join('');
      if (s.installed) { location.href = '/admin'; }
    }).catch((e) => { $('req').textContent = e.message; });
    $('btnDownload').onclick = async () => {
      $('dlLog').textContent = 'Working…';
      try {
        const r = await api('download');
        $('dlLog').textContent = r.skipped ? 'Files already present' : ('Downloaded ' + r.version);
        show('s1');
      } catch (e) { $('dlLog').textContent = e.message; }
    };
    const db = () => ({
      host: $('dbHost').value, port: Number($('dbPort').value), name: $('dbName').value,
      user: $('dbUser').value, password: $('dbPass').value, charset: $('dbCharset').value
    });
    $('btnTest').onclick = async () => {
      try {
        const r = await api('test-connection', { database: db() });
        $('dbMsg').className = r.ok ? 'ok' : 'err';
        $('dbMsg').textContent = r.ok ? 'Connection successful' : (r.error || 'Failed');
      } catch (e) { $('dbMsg').className = 'err'; $('dbMsg').textContent = e.message; }
    };
    $('btnDb').onclick = () => show('s2');
    $('btnApp').onclick = () => show('s3');
    $('btnInstall').onclick = async () => {
      $('instMsg').textContent = 'Installing…';
      try {
        await api('complete', {
          database: db(),
          application: { name: $('appName').value, url: $('appUrl').value, timezone: $('appTz').value, language: $('appLang').value },
          administrator: { name: $('admName').value, email: $('admEmail').value, password: $('admPass').value, passwordConfirm: $('admPass2').value }
        });
        show('s4');
      } catch (e) { $('instMsg').className = 'err'; $('instMsg').textContent = e.message; }
    };
  </script>
</body>
</html>
HTML;
}
