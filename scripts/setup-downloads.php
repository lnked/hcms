<?php

declare(strict_types=1);

/**
 * One-shot patch: creates the `downloads` resource behind the landing counter
 * (schema, public flags, spam settings, CORS origin) via the Admin API.
 *
 * Web (shared hosting): upload next to index.php, open
 *   https://api.2js.ru/setup-downloads.php
 * CLI:
 *   php scripts/setup-downloads.php --url=https://api.2js.ru --email=… --password=…
 *
 * Idempotent: re-running repairs an existing resource instead of duplicating it.
 * Delete the file when done — it is not part of the CMS.
 *
 * See docs/landing-downloads.md
 */

final class DownloadsPatch
{
    public const SLUG = 'downloads';
    public const LABEL = 'Downloads';
    public const ORIGIN = '2js.ru';

    /** @var list<string> */
    private array $log = [];

    private string $token = '';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $origin,
        private readonly bool $insecure = false,
    ) {
    }

    /**
     * @return list<string>
     */
    public function log(): array
    {
        return $this->log;
    }

    public function run(string $email, string $password): void
    {
        $this->login($email, $password);
        $id = $this->resourceId();
        $this->applySettings($id);
        $this->applySchema($id);
        $this->publish($id);
        $this->allowOrigin();
        $this->smokeTest();
    }

    private function login(string $email, string $password): void
    {
        $res = $this->send('POST', '/admin/api/auth/login', [
            'email' => $email,
            'password' => $password,
        ], false);
        $token = $res['data']['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Login failed: no token in response');
        }
        $this->token = $token;
        $this->say('Logged in as ' . $email);
    }

    private function resourceId(): int
    {
        $listed = $this->send('GET', '/admin/api/resources');
        $rows = is_array($listed['data'] ?? null) ? $listed['data'] : [];
        foreach ($rows as $row) {
            if (is_array($row) && ($row['slug'] ?? null) === self::SLUG) {
                $id = (int) ($row['id'] ?? 0);
                $this->say('Resource ' . self::SLUG . ' already exists (#' . $id . ') — repairing');

                return $id;
            }
        }

        $created = $this->send('POST', '/admin/api/resources', [
            'label' => self::LABEL,
            'name' => self::SLUG,
            'slug' => self::SLUG,
            'description' => 'Landing download counter (public create, public read)',
        ]);
        $id = (int) ($created['data']['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('Resource create returned no id');
        }
        $this->say('Created resource ' . self::SLUG . ' (#' . $id . ')');

        return $id;
    }

    private function applySettings(int $id): void
    {
        $this->send('PATCH', '/admin/api/resources/' . $id, [
            'settings' => [
                'apiEnabled' => true,
                'public' => [
                    'read' => true,
                    'create' => true,
                    'update' => false,
                    'delete' => false,
                ],
                'pagination' => true,
                // The public endpoint is a counter, not a query tool.
                'search' => false,
                'sorting' => false,
                'filtering' => false,
                'deleteStrategy' => 'hard',
                'spam' => [
                    // On by default, and it silently drops repeat downloads
                    // from one IP for 10 minutes. Rate limit guards instead.
                    'rejectDuplicates' => false,
                    'rateLimitPerMinute' => 20,
                    'requireCaptcha' => false,
                    'honeypotField' => '',
                    'minSubmitMs' => 0,
                    'maxLinks' => 0,
                    'blocklist' => [],
                ],
            ],
        ]);
        $this->say('Settings: public read + create, spam rejectDuplicates off, 20 req/min');
    }

    private function applySchema(int $id): void
    {
        $this->send('PUT', '/admin/api/resources/' . $id . '/fields', [
            'fields' => [
                $this->field('asset', 'Asset', 64, true),
                $this->field('version', 'Version', 32, true, true),
                $this->field('source', 'Source', 32, true),
                $this->field('referrer', 'Referrer', 190),
            ],
        ]);
        $this->say('Schema: asset, version, source, referrer');
    }

    /**
     * Every field is nullable on purpose: a malformed payload must not 422 and
     * cost a click.
     *
     * @return array<string, mixed>
     */
    private function field(string $name, string $label, int $maxLength, bool $filterable = false, bool $sortable = false): array
    {
        return [
            'name' => $name,
            'type' => 'string',
            'label' => $label,
            'required' => false,
            'nullable' => true,
            'unique' => false,
            'indexed' => $filterable,
            'searchable' => false,
            'sortable' => $sortable,
            'filterable' => $filterable,
            'readable' => true,
            'writable' => true,
            'config' => ['maxLength' => $maxLength],
        ];
    }

    private function publish(int $id): void
    {
        $this->send('POST', '/admin/api/resources/' . $id . '/publish', ['confirmDestructive' => false]);
        $this->say('Published + migrated → /api/' . self::SLUG);
    }

    private function allowOrigin(): void
    {
        $access = $this->send('GET', '/admin/api/settings/api-access');
        $data = is_array($access['data'] ?? null) ? $access['data'] : [];
        if (($data['unrestricted'] ?? false) === true) {
            $this->say('CORS: unrestricted, nothing to add');

            return;
        }

        $origins = [];
        foreach (is_array($data['allowedOrigins'] ?? null) ? $data['allowedOrigins'] : [] as $item) {
            if (is_string($item)) {
                $origins[] = $item;
            }
        }
        if (in_array($this->origin, $origins, true)) {
            $this->say('CORS: ' . $this->origin . ' already allowed');

            return;
        }

        $origins[] = $this->origin;
        $this->send('PATCH', '/admin/api/settings', [
            'apiAccess' => ['unrestricted' => false, 'allowedOrigins' => $origins],
        ]);
        $this->say('CORS: added ' . $this->origin);
    }

    private function smokeTest(): void
    {
        $res = $this->send('GET', '/api/' . self::SLUG . '?limit=1', null, false);
        $total = $res['meta']['total'] ?? null;
        if (!is_int($total)) {
            throw new RuntimeException('Public GET /api/' . self::SLUG . ' returned no meta.total');
        }
        $this->say('Public GET works, meta.total = ' . $total);
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $json = null, bool $auth = true): array
    {
        $body = $json === null
            ? null
            : (string) json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = ['Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($auth && $this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        [$status, $raw] = $this->transport($method, $this->baseUrl . $path, $body, $headers);
        if ($status === 204) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(
                $method . ' ' . $path . ' → HTTP ' . $status . ', not JSON: ' . substr(trim($raw), 0, 200),
            );
        }
        if ($status >= 400) {
            $message = $decoded['error']['message'] ?? $decoded['message'] ?? ('HTTP ' . $status);
            throw new RuntimeException($method . ' ' . $path . ' → ' . (is_string($message) ? $message : 'HTTP ' . $status));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: string}
     */
    private function transport(string $method, string $url, ?string $body, array $headers): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('curl_init failed');
            }
            $opts = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => $headers,
            ];
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = $body;
            }
            if ($this->insecure) {
                $opts[CURLOPT_SSL_VERIFYPEER] = false;
                $opts[CURLOPT_SSL_VERIFYHOST] = 0;
            }
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            if ($raw === false) {
                throw new RuntimeException('Request failed: ' . curl_error($ch));
            }

            return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), (string) $raw];
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'timeout' => 60,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => !$this->insecure,
                'verify_peer_name' => !$this->insecure,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new RuntimeException('Request failed: ' . $method . ' ' . $url . ' (no curl, allow_url_fopen blocked?)');
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, $raw];
    }

    private function say(string $message): void
    {
        $this->log[] = $message;
    }
}

if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
    exit(dl_cli());
}

dl_web();

function dl_cli(): int
{
    $opts = getopt('', ['url::', 'email::', 'password::', 'origin::', 'insecure', 'help']);
    if (isset($opts['help'])) {
        fwrite(STDOUT, <<<TXT
        Create the `downloads` resource for the landing counter.

          --url=URL          CMS base URL (e.g. https://api.2js.ru)
          --email=EMAIL      Admin email
          --password=PASS    Admin password
          --origin=HOST      Landing origin for CORS (default: 2js.ru)
          --insecure         Skip TLS verification

        TXT);

        return 0;
    }

    $url = rtrim((string) ($opts['url'] ?? ''), '/');
    $email = (string) ($opts['email'] ?? '');
    $password = (string) ($opts['password'] ?? '');
    if ($url === '' || $email === '' || $password === '') {
        fwrite(STDERR, "Error: --url, --email and --password are required (--help for usage).\n");

        return 1;
    }

    $patch = new DownloadsPatch($url, (string) ($opts['origin'] ?? DownloadsPatch::ORIGIN), isset($opts['insecure']));
    try {
        $patch->run($email, $password);
    } catch (Throwable $e) {
        foreach ($patch->log() as $line) {
            fwrite(STDOUT, '  ' . $line . "\n");
        }
        fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");

        return 1;
    }

    foreach ($patch->log() as $line) {
        fwrite(STDOUT, '  ' . $line . "\n");
    }
    fwrite(STDOUT, "Done. Delete this file from the server.\n");

    return 0;
}

function dl_web(): void
{
    $self = basename(__FILE__);

    if (($_POST['action'] ?? '') === 'remove') {
        @unlink(__FILE__);
        dl_page('<p class="ok">File deleted.</p>', null);

        return;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        dl_page('', dl_form(dl_baseUrl(), DownloadsPatch::ORIGIN, false));

        return;
    }

    $url = rtrim(trim((string) ($_POST['url'] ?? '')), '/');
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $origin = trim((string) ($_POST['origin'] ?? DownloadsPatch::ORIGIN));
    $insecure = isset($_POST['insecure']);

    if ($url === '' || $email === '' || $password === '') {
        dl_page('<p class="err">URL, email and password are required.</p>', dl_form($url, $origin, $insecure));

        return;
    }

    $patch = new DownloadsPatch($url, $origin, $insecure);
    $error = null;
    try {
        $patch->run($email, $password);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $html = '<ul class="log">';
    foreach ($patch->log() as $line) {
        $html .= '<li>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $html .= '</ul>';

    if ($error !== null) {
        $html .= '<p class="err">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>';

        dl_page($html, dl_form($url, $origin, $insecure));

        return;
    }

    $html .= '<p class="ok">Done. Remove ' . htmlspecialchars($self, ENT_QUOTES, 'UTF-8') . ' from the server.</p>';
    $html .= '<form method="post"><input type="hidden" name="action" value="remove"/>'
        . '<button type="submit">Delete this file</button></form>';

    dl_page($html, null);
}

function dl_baseUrl(): string
{
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return ($https ? 'https://' : 'http://') . $host;
}

function dl_form(string $url, string $origin, bool $insecure): string
{
    $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    return '<form method="post">'
        . '<label>CMS URL<input name="url" value="' . $esc($url) . '" required/></label>'
        . '<label>Admin email<input name="email" type="email" autocomplete="username" required/></label>'
        . '<label>Admin password<input name="password" type="password" autocomplete="current-password" required/></label>'
        . '<label>Landing origin<input name="origin" value="' . $esc($origin) . '"/></label>'
        . '<label class="check"><input type="checkbox" name="insecure" value="1"'
        . ($insecure ? ' checked' : '') . '/> Skip TLS verification (certificate does not cover this host)</label>'
        . '<button type="submit">Create resource</button>'
        . '</form>';
}

function dl_page(string $body, ?string $form): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"/>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"/>'
        . '<title>HCMS — downloads resource</title><style>'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'font-family:system-ui,sans-serif;background:linear-gradient(160deg,#e8eef2 0%,#d5ebe6 45%,#e2e8f0 100%);'
        . 'color:#0f172a;padding:24px;box-sizing:border-box}'
        . '.box{width:100%;max-width:460px;background:#fff;border:1px solid #cbd5e1;border-radius:16px;'
        . 'padding:28px 24px;box-shadow:0 12px 40px rgba(15,23,42,.08)}'
        . 'h1{font-size:26px;margin:0 0 4px;letter-spacing:-.02em}'
        . '.sub{color:#64748b;font-size:14px;margin:0 0 20px}'
        . 'label{display:block;font-size:13px;font-weight:600;margin-bottom:12px}'
        . 'input{display:block;width:100%;margin-top:4px;padding:9px 10px;border:1px solid #cbd5e1;'
        . 'border-radius:8px;font:inherit;box-sizing:border-box}'
        . '.check{display:flex;gap:8px;align-items:center;font-weight:500;color:#475569}'
        . '.check input{display:inline-block;width:auto;margin:0;padding:0}'
        . 'button{margin-top:8px;padding:10px 16px;border:0;border-radius:8px;background:#0d9488;color:#fff;'
        . 'font:inherit;font-weight:600;cursor:pointer}'
        . '.log{margin:0 0 12px;padding-left:18px;font-size:14px;line-height:1.6}'
        . '.ok{color:#0d9488;font-size:14px;font-weight:600}'
        . '.err{color:#b91c1c;font-size:14px}'
        . '</style></head><body><div class="box">'
        . '<h1>HCMS</h1><p class="sub">Downloads resource for the landing counter</p>'
        . $body
        . ($form ?? '')
        . '</div></body></html>';
}
