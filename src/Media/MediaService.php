<?php

declare(strict_types=1);

namespace Cms\Media;

use Cms\Core\Paths;
use Cms\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

final class MediaService
{
    private const MAX_BYTES = 10_485_760; // 10 MB

    /** @var list<string> */
    private const DEFAULT_ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'application/pdf',
        'text/plain',
        'text/csv',
        'video/mp4',
        'video/webm',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
    ];

    /** MIME → disk extension (never trust client filename for storage). */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/zip' => 'zip',
    ];

    /**
     * Script / markup sources — accepted, but always stored as text/plain + .txt.
     *
     * @var list<string>
     */
    private const SOURCE_EXTENSIONS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps',
        'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp', 'jspx', 'shtml', 'shtm',
        'sh', 'bash', 'ps1', 'vbs',
        'html', 'htm', 'xhtml', 'js', 'mjs', 'css',
        'htaccess', 'htpasswd', 'ini', 'env',
    ];

    /**
     * @var list<string>
     */
    private const SOURCE_MIMES = [
        'text/x-php',
        'application/x-php',
        'application/x-httpd-php',
        'text/x-python',
        'text/x-script.python',
        'application/x-python',
        'application/x-python-code',
        'text/javascript',
        'application/javascript',
        'application/x-javascript',
        'text/css',
        'text/html',
        'application/xhtml+xml',
        'application/x-sh',
        'application/x-shellscript',
        'text/x-shellscript',
        'text/x-c',
        'text/x-c++',
        'text/x-java-source',
        'text/x-ruby',
        'text/x-perl',
        'application/x-perl',
    ];

    /** Always reject — never store as source text. */
    private const BINARY_BLOCKED_EXTENSIONS = [
        'exe', 'dll', 'so', 'msi', 'com', 'bat', 'cmd',
    ];

    private const UPLOADS_HTACCESS = <<<'HTACCESS'
# Prevent script execution if this directory is ever web-reachable.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Deny from all
</IfModule>

Options -Indexes -ExecCGI
RemoveHandler .php .phtml .phar .php3 .php4 .php5 .php7 .php8 .phps .cgi .pl .py
RemoveType .php .phtml .phar .php3 .php4 .php5 .php7 .php8 .phps
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>

HTACCESS;

    /** @var list<string>|null */
    private readonly ?array $allowedMimes;

    /**
     * @param list<string>|null $allowedMimes null = default allowlist
     */
    public function __construct(
        private readonly Connection $db,
        private readonly Paths $paths,
        ?array $allowedMimes = null,
    ) {
        $this->allowedMimes = $allowedMimes;
    }

    /**
     * @return list<string>
     */
    public function allowedMimes(): array
    {
        return $this->allowedMimes ?? self::DEFAULT_ALLOWED_MIMES;
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function page(int $page = 1, int $limit = 40): array
    {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $count = $this->db->selectOne('SELECT COUNT(*) AS c FROM cms_media');
        $total = $count === null ? 0 : (int) $count['c'];
        $offset = ($page - 1) * $limit;
        $rows = $this->db->select(
            'SELECT * FROM cms_media ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
        );

        return [
            'data' => array_map(fn (array $row): array => $this->serialize($row), $rows),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) max(1, (int) ceil($total / $limit)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        $row = $this->findRow($id);
        if ($row === null) {
            throw new RuntimeException('Media not found', 404);
        }

        return $this->serialize($row);
    }

    /**
     * @param array<string, mixed> $file from $_FILES['file']
     * @return array<string, mixed>
     */
    public function upload(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Upload failed with error code ' . $error);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['name'] ?? 'file');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('Invalid upload');
        }
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('File too large (max 10MB)');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: 'application/octet-stream';
        $bytes = file_get_contents($tmp);
        if ($bytes === false) {
            throw new RuntimeException('Failed to read upload');
        }

        return $this->storeFromBytes($bytes, $name, $mime);
    }

    /**
     * Store raw bytes as a media item (used by resource package import).
     *
     * @return array<string, mixed>
     */
    public function storeFromBytes(string $bytes, string $originalName, string $mime): array
    {
        $size = strlen($bytes);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('File too large (max 10MB)');
        }

        $normalized = $this->normalizeUpload($originalName, $mime);
        $mime = $normalized['mime'];
        $originalName = $normalized['originalName'];
        $ext = $normalized['ext'];

        $this->ensureUploadsProtected();
        $relative = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $absolute = $this->paths->media() . '/' . $relative;
        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create media directory');
        }
        if (file_put_contents($absolute, $bytes) === false) {
            throw new RuntimeException('Failed to store media file');
        }
        // Drop execute bits — uploaded files must never be executable.
        @chmod($absolute, 0644);

        $width = null;
        $height = null;
        if (str_starts_with($mime, 'image/')) {
            $info = @getimagesize($absolute);
            if (is_array($info)) {
                $width = $info[0];
                $height = $info[1];
            }
        }

        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_media (disk_path, original_name, mime, size, width, height, created_at)
             VALUES (:disk_path, :original_name, :mime, :size, :width, :height, :created_at)',
            [
                'disk_path' => $relative,
                'original_name' => substr($originalName, 0, 255),
                'mime' => substr($mime, 0, 128),
                'size' => $size,
                'width' => $width,
                'height' => $height,
                'created_at' => $now,
            ],
        );

        return $this->get((int) $this->db->lastInsertId());
    }

    /**
     * @return array{id: int, originalName: string, mime: string, width: ?int, height: ?int, contentBase64: string}|null
     */
    public function exportForPackage(int $id): ?array
    {
        $row = $this->findRow($id);
        if ($row === null) {
            return null;
        }
        $absolute = $this->paths->media() . '/' . $row['disk_path'];
        if (!is_file($absolute)) {
            return null;
        }
        $bytes = file_get_contents($absolute);
        if ($bytes === false) {
            return null;
        }

        return [
            'id' => $id,
            'originalName' => (string) $row['original_name'],
            'mime' => (string) $row['mime'],
            'width' => $row['width'] === null ? null : (int) $row['width'],
            'height' => $row['height'] === null ? null : (int) $row['height'],
            'contentBase64' => base64_encode($bytes),
        ];
    }

    public function delete(int $id): void
    {
        $row = $this->findRow($id);
        if ($row === null) {
            throw new RuntimeException('Media not found', 404);
        }
        $absolute = $this->paths->media() . '/' . $row['disk_path'];
        $this->db->execute('DELETE FROM cms_media WHERE id = :id', ['id' => $id]);
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    /**
     * @return array{path: string, mime: string, name: string}|null
     */
    public function absoluteFile(int $id): ?array
    {
        $row = $this->findRow($id);
        if ($row === null) {
            return null;
        }
        $absolute = $this->paths->media() . '/' . $row['disk_path'];
        if (!is_file($absolute)) {
            return null;
        }

        return [
            'path' => $absolute,
            'mime' => (string) $row['mime'],
            'name' => (string) $row['original_name'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRow(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM cms_media WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array{mime: string, originalName: string, ext: string}
     */
    private function normalizeUpload(string $originalName, string $mime): array
    {
        if ($originalName === '' || str_contains($originalName, "\0")) {
            throw new InvalidArgumentException('Invalid filename');
        }

        $mime = strtolower(trim(explode(';', trim($mime) !== '' ? $mime : 'application/octet-stream')[0]));
        $this->assertNotBinaryExecutable($originalName);

        if ($this->isSourceAsText($originalName, $mime)) {
            // Never keep a script MIME or .php (etc.) on disk — plain text only.
            return [
                'mime' => 'text/plain',
                'originalName' => $this->sourceDisplayName($originalName),
                'ext' => 'txt',
            ];
        }

        $this->assertMimeAllowed($mime);
        $this->assertFilenameSafe($originalName);

        return [
            'mime' => $mime,
            'originalName' => $this->basenameOnly($originalName),
            'ext' => $this->extensionForMime($mime),
        ];
    }

    private function isSourceAsText(string $originalName, string $mime): bool
    {
        if (in_array($mime, self::SOURCE_MIMES, true)) {
            return true;
        }

        $hasSourceExt = $this->filenameHasAnyExtension($originalName, self::SOURCE_EXTENSIONS);
        if (!$hasSourceExt) {
            return false;
        }

        // Script-looking name with a binary/image MIME → reject (polyglot / rename attack).
        if (
            str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'video/')
            || str_starts_with($mime, 'audio/')
            || $mime === 'application/pdf'
            || $mime === 'application/zip'
            || str_contains($mime, 'officedocument')
            || $mime === 'application/msword'
            || $mime === 'application/vnd.ms-excel'
        ) {
            throw new InvalidArgumentException('Executable or script file type is not allowed');
        }

        return str_starts_with($mime, 'text/')
            || $mime === 'application/octet-stream'
            || $mime === 'application/json'
            || $mime === 'application/xml'
            || $mime === 'text/xml';
    }

    private function sourceDisplayName(string $originalName): string
    {
        $base = $this->basenameOnly($originalName);
        if (!str_ends_with(strtolower($base), '.txt')) {
            $base .= '.txt';
        }

        return $base;
    }

    private function basenameOnly(string $originalName): string
    {
        $base = basename(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $originalName));

        return $base !== '' ? $base : 'file';
    }

    private function assertMimeAllowed(string $mime): void
    {
        $allowed = $this->allowedMimes();
        $normalized = strtolower(trim(explode(';', $mime)[0]));
        foreach ($allowed as $entry) {
            if (strcasecmp($entry, $normalized) === 0) {
                return;
            }
        }

        throw new InvalidArgumentException('MIME type not allowed: ' . $normalized);
    }

    private function assertFilenameSafe(string $originalName): void
    {
        $blocked = [...self::SOURCE_EXTENSIONS, ...self::BINARY_BLOCKED_EXTENSIONS];
        if ($this->filenameHasAnyExtension($originalName, $blocked)) {
            throw new InvalidArgumentException('Executable or script file type is not allowed');
        }
    }

    private function assertNotBinaryExecutable(string $originalName): void
    {
        if ($this->filenameHasAnyExtension($originalName, self::BINARY_BLOCKED_EXTENSIONS)) {
            throw new InvalidArgumentException('Executable or script file type is not allowed');
        }
    }

    /**
     * @param list<string> $extensions
     */
    private function filenameHasAnyExtension(string $originalName, array $extensions): bool
    {
        $base = strtolower($this->basenameOnly($originalName));
        $parts = preg_split('/\./', $base) ?: [];
        foreach ($parts as $part) {
            $clean = preg_replace('/[^a-z0-9]/i', '', $part);
            if (is_string($clean) && $clean !== '' && in_array($clean, $extensions, true)) {
                return true;
            }
        }

        return false;
    }

    private function extensionForMime(string $mime): string
    {
        $normalized = strtolower(trim(explode(';', $mime)[0]));

        return self::MIME_EXTENSIONS[$normalized] ?? 'bin';
    }

    private function ensureUploadsProtected(): void
    {
        $media = $this->paths->media();
        if (!is_dir($media) && !mkdir($media, 0755, true) && !is_dir($media)) {
            throw new RuntimeException('Cannot create media directory');
        }

        $htaccess = $media . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, self::UPLOADS_HTACCESS);
        }

        $storageDeny = $this->paths->storage() . '/.htaccess';
        if (!is_file($storageDeny)) {
            @file_put_contents(
                $storageDeny,
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n",
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        $id = (int) $row['id'];

        return [
            'id' => $id,
            'originalName' => $row['original_name'],
            'mime' => $row['mime'],
            'size' => (int) $row['size'],
            'width' => $row['width'] === null ? null : (int) $row['width'],
            'height' => $row['height'] === null ? null : (int) $row['height'],
            'url' => '/media/' . $id,
            'createdAt' => $row['created_at'],
        ];
    }
}
