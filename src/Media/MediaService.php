<?php

declare(strict_types=1);

namespace Cms\Media;

use Cms\Core\Exception\ForbiddenException;
use Cms\Core\Exception\NotFoundException;
use Cms\Core\Paths;
use Cms\Database\Connection;
use Cms\Fields\Types\MediaFieldConfig;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class MediaService
{
    private const MAX_BYTES = 10_485_760; // 10 MB

    /** @var array<string, string> extension → mime */
    private const EXT_TO_MIME = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip' => 'application/zip',
    ];

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

    /** Public warm-cache: allow GET, never execute scripts. */
    private const PUBLIC_MEDIA_HTACCESS = <<<'HTACCESS'
# Static copies of /media/{id}/{filename}. Source of truth remains storage/uploads.
Options -Indexes -ExecCGI
RemoveHandler .php .phtml .phar .php3 .php4 .php5 .php7 .php8 .phps .cgi .pl .py .html .htm .shtml .js
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
<IfModule mod_headers.c>
    Header set Cache-Control "public, max-age=31536000, immutable"
    Header set X-Content-Type-Options "nosniff"
</IfModule>

HTACCESS;

    /** @var list<string>|null */
    private readonly ?array $allowedMimes;

    private readonly MediaRefService $mediaRefs;

    /**
     * @param list<string>|null $allowedMimes null = default allowlist
     */
    public function __construct(
        private readonly Connection $db,
        private readonly Paths $paths,
        ?array $allowedMimes = null,
        private readonly ImageProcessor $images = new ImageProcessor(),
        ?MediaRefService $refs = null,
        private readonly string $appUrl = 'http://localhost',
    ) {
        $this->allowedMimes = $allowedMimes;
        $this->mediaRefs = $refs ?? new MediaRefService($db);
    }

    /**
     * Public relative path: /media/{id}/{safeName.ext}. Filename is cosmetic; lookup is by id.
     */
    public static function publicPath(int $id, ?string $originalName = null, ?string $mime = null): string
    {
        return '/media/' . $id . '/' . self::safePublicFilename($originalName, $mime);
    }

    public static function publicFullUrl(
        string $appUrl,
        int $id,
        ?string $originalName = null,
        ?string $mime = null,
    ): string {
        return rtrim($appUrl, '/') . self::publicPath($id, $originalName, $mime);
    }

    /**
     * @return array{url: string, fullUrl: string}
     */
    public static function publicUrls(
        string $appUrl,
        int $id,
        ?string $originalName = null,
        ?string $mime = null,
    ): array {
        $url = self::publicPath($id, $originalName, $mime);

        return [
            'url' => $url,
            'fullUrl' => rtrim($appUrl, '/') . $url,
        ];
    }

    public static function safePublicFilename(?string $originalName, ?string $mime = null): string
    {
        $base = basename(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, (string) $originalName));
        $base = str_replace("\0", '', $base);

        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        $name = pathinfo($base, PATHINFO_FILENAME);

        if ($ext === '' && is_string($mime) && $mime !== '') {
            $mimeKey = strtolower(trim(explode(';', $mime)[0]));
            $ext = self::MIME_EXTENSIONS[$mimeKey] ?? 'bin';
        }
        if ($ext === '') {
            $ext = 'bin';
        }

        $name = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $name) ?? '';
        $name = trim($name, '.-_');
        if ($name === '') {
            $name = 'file';
        }

        $ext = preg_replace('/[^a-zA-Z0-9]+/', '', $ext) ?? 'bin';
        if ($ext === '') {
            $ext = 'bin';
        }

        return $name . '.' . $ext;
    }

    private function refs(): MediaRefService
    {
        return $this->mediaRefs;
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
    public function page(int $page = 1, int $limit = 40, ?MediaAclScope $scope = null): array
    {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $scope ??= MediaAclScope::unrestricted();
        [$where, $params] = $this->aclWhere($scope);
        $count = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM cms_media WHERE ' . $where,
            $params,
        );
        $total = $count === null ? 0 : (int) $count['c'];
        $offset = ($page - 1) * $limit;
        $rows = $this->db->select(
            'SELECT * FROM cms_media WHERE ' . $where
            . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
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
    public function get(int $id, ?MediaAclScope $scope = null): array
    {
        $row = $this->findRow($id);
        if ($row === null) {
            throw new NotFoundException('Media not found');
        }
        $scope ??= MediaAclScope::unrestricted();
        if (!$this->refs()->isVisible($id, $scope)) {
            throw new NotFoundException('Media not found');
        }

        return $this->serialize($row);
    }

    /**
     * @param array<string, mixed> $file from $_FILES['file']
     * @param list<string>|null $allowedFormats field-level extensions (empty/null = no extra filter)
     * @return array<string, mixed>
     */
    public function upload(array $file, ?array $allowedFormats = null, ?int $uploadedBy = null): array
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

        $this->assertFormatsAllowed($mime, $name, $allowedFormats);

        return $this->storeFromBytes($bytes, $name, $mime, null, null, null, $uploadedBy);
    }

    /**
     * Upload original + generate image variants. Returns MediaValue shape.
     *
     * The original is already stored when variants are cut, so a GD failure must not
     * fail the response: the caller would lose the media id and the field would stay
     * empty while the file sits in the library. Variants degrade to a warning instead.
     *
     * @param array<string, mixed> $file
     * @param list<array{prefix: string, width: int, height: int, mode: string, position: string}> $sizes
     * @param array<string, string> $positions user overrides keyed by prefix
     * @param list<string>|null $allowedFormats
     * @param 'webp'|'jpeg'|'png'|null $encodeFormat
     * @return array{id: int, rotation: int, positions: array<string, string>, variants: array<string, int>, warning: string|null, media: array<string, mixed>}
     */
    public function uploadWithTransforms(
        array $file,
        array $sizes,
        int $rotation = 0,
        array $positions = [],
        ?array $allowedFormats = null,
        ?int $uploadedBy = null,
        ?string $encodeFormat = null,
    ): array {
        $original = $this->upload($file, $allowedFormats, $uploadedBy);
        $id = (int) $original['id'];

        $warning = null;
        try {
            $this->maybeReencode($id, $encodeFormat);
        } catch (Throwable $e) {
            $warning = $e->getMessage();
        }

        if ($sizes === []) {
            return [
                'id' => $id,
                'rotation' => $rotation,
                'positions' => $positions,
                'variants' => [],
                'warning' => $warning,
                'media' => $this->get($id),
            ];
        }

        // GD work grows with the number of sizes; a slow disk should not abort mid-run.
        @set_time_limit(0);

        $variants = [];
        try {
            $row = $this->findRow($id);
            if ($row === null) {
                throw new RuntimeException('Media not found');
            }
            $this->assertRaster($row);
            $variants = $this->generateVariants(
                $id,
                $sizes,
                $rotation,
                $positions,
                [],
                MediaFieldConfig::encodeFormatToMime($encodeFormat),
            );
        } catch (Throwable $e) {
            $warning = $warning ?? $e->getMessage();
        }

        return [
            'id' => $id,
            'rotation' => $rotation,
            'positions' => $positions,
            'variants' => $variants,
            'warning' => $warning,
            'media' => $this->get($id),
        ];
    }

    /**
     * Regenerate variants for an existing original or baked master.
     *
     * @param list<array{prefix: string, width: int, height: int, mode: string, position: string}> $sizes
     * @param array<string, string> $positions
     * @param array<string, array{crop: array{x: float, y: float, w: float, h: float}}> $overrides
     * @param 'webp'|'jpeg'|'png'|null $encodeFormat
     * @return array{id: int, rotation: int, positions: array<string, string>, variants: array<string, int>, media: array<string, mixed>}
     */
    public function regenerateVariants(
        int $mediaId,
        array $sizes,
        int $rotation = 0,
        array $positions = [],
        array $overrides = [],
        ?MediaAclScope $scope = null,
        ?string $encodeFormat = null,
    ): array {
        $this->assertMutable($mediaId, $scope);
        $row = $this->findRow($mediaId);
        if ($row === null) {
            throw new NotFoundException('Media not found');
        }
        if ($row['parent_id'] !== null) {
            throw new InvalidArgumentException('Cannot regenerate a variant; pass the original media id');
        }
        $this->assertRaster($row);
        $this->maybeReencode($mediaId, $encodeFormat);

        $this->deleteChildren($mediaId);
        $variants = $sizes === []
            ? []
            : $this->generateVariants(
                $mediaId,
                $sizes,
                $rotation,
                $positions,
                $overrides,
                MediaFieldConfig::encodeFormatToMime($encodeFormat),
            );

        return [
            'id' => $mediaId,
            'rotation' => $rotation,
            'positions' => $positions,
            'variants' => $variants,
            'media' => $this->get($mediaId),
        ];
    }

    /**
     * Bake a base edit into a new master image and regenerate every variant from it.
     *
     * The source original is never touched, so re-opening the editor always starts
     * from full quality instead of re-cropping an already cropped file.
     *
     * @param array{rotation: int, flipH: bool, flipV: bool, crop: array{x: float, y: float, w: float, h: float}|null}|null $edit
     * @param list<array{prefix: string, width: int, height: int, mode: string, position: string}> $sizes
     * @param array<string, string> $positions
     * @param array<string, array{crop: array{x: float, y: float, w: float, h: float}}> $overrides
     * @param 'webp'|'jpeg'|'png'|null $encodeFormat
     * @return array{id: int, sourceId: int|null, edit: array<string, mixed>|null, rotation: int, positions: array<string, string>, overrides: array<string, mixed>, variants: array<string, int>, media: array<string, mixed>}
     */
    public function applyEdit(
        int $mediaId,
        ?array $edit,
        array $sizes,
        array $positions = [],
        array $overrides = [],
        ?MediaAclScope $scope = null,
        ?string $encodeFormat = null,
    ): array {
        $this->assertMutable($mediaId, $scope);
        $row = $this->findRow($mediaId);
        if ($row === null) {
            throw new NotFoundException('Media not found');
        }
        if ($row['parent_id'] !== null) {
            throw new InvalidArgumentException('Cannot edit a variant; pass the original media id');
        }
        $this->assertRaster($row);

        // Always re-edit from the untouched origin, even when a baked master was passed in.
        $sourceId = ($row['source_id'] ?? null) === null ? $mediaId : (int) $row['source_id'];
        if ($sourceId !== $mediaId) {
            $row = $this->findRow($sourceId);
            if ($row === null) {
                throw new NotFoundException('Source media not found');
            }
            $this->assertRaster($row);
        }

        // GD work grows with the number of sizes; a slow disk should not abort mid-run.
        @set_time_limit(0);

        if ($edit === null) {
            $overrides = array_intersect_key($overrides, array_flip(array_column($sizes, 'prefix')));
            $result = $this->regenerateVariants(
                $sourceId,
                $sizes,
                0,
                $positions,
                $overrides,
                $scope,
                $encodeFormat,
            );

            return [
                'id' => $sourceId,
                'sourceId' => null,
                'edit' => null,
                'rotation' => 0,
                'positions' => $positions,
                'overrides' => $overrides,
                'variants' => $result['variants'],
                'media' => $result['media'],
            ];
        }

        $outputMime = MediaFieldConfig::encodeFormatToMime($encodeFormat) ?? (string) $row['mime'];
        if ($outputMime === 'image/webp' && !function_exists('imagewebp')) {
            throw new InvalidArgumentException('WebP encoding is not available on this server');
        }

        $absolute = $this->absolutePath($row);
        $baked = $this->images->bake($absolute, $edit, $outputMime);
        $master = $this->storeFromBytes(
            $baked['bytes'],
            $this->editedName((string) $row['original_name']),
            $baked['mime'],
            null,
            null,
            $sourceId,
        );
        $masterId = (int) $master['id'];

        $overrides = array_intersect_key($overrides, array_flip(array_column($sizes, 'prefix')));
        $variants = $sizes === []
            ? []
            : $this->generateVariants($masterId, $sizes, 0, $positions, $overrides, $outputMime);

        return [
            'id' => $masterId,
            'sourceId' => $sourceId,
            'edit' => $edit,
            'rotation' => 0,
            'positions' => $positions,
            'overrides' => $overrides,
            'variants' => $variants,
            'media' => $this->get($masterId),
        ];
    }

    /**
     * Re-encode a master (and optionally its variants) to reduce file size.
     *
     * @param array{
     *   quality: int,
     *   format?: string,
     *   maxWidth?: int|null,
     *   maxHeight?: int|null,
     *   applyToVariants?: bool
     * } $opts
     * @return array{
     *   media: array<string, mixed>,
     *   before: array{size: int, mime: string},
     *   after: array{size: int, mime: string},
     *   savedBytes: int,
     *   savedPercent: float,
     *   variantsOptimized: int
     * }
     */
    public function optimize(int $mediaId, array $opts, ?MediaAclScope $scope = null): array
    {
        $this->assertMutable($mediaId, $scope);
        $row = $this->findRow($mediaId);
        if ($row === null) {
            throw new NotFoundException('Media not found');
        }
        if ($row['parent_id'] !== null) {
            throw new InvalidArgumentException('Cannot optimize a variant; pass the original media id');
        }
        $this->assertRaster($row);

        $mime = strtolower(trim(explode(';', (string) $row['mime'])[0]));
        if ($mime === 'image/gif' || $mime === 'image/svg+xml') {
            throw new InvalidArgumentException('GIF and SVG cannot be optimized');
        }

        $quality = isset($opts['quality']) && is_numeric($opts['quality']) ? (int) $opts['quality'] : 0;
        if ($quality < 1 || $quality > 100) {
            throw new InvalidArgumentException('quality must be between 1 and 100');
        }

        $format = isset($opts['format']) && is_string($opts['format'])
            ? strtolower(trim($opts['format']))
            : 'keep';
        if (!in_array($format, ['keep', 'webp', 'jpeg', 'png'], true)) {
            throw new InvalidArgumentException('format must be keep, webp, jpeg, or png');
        }

        $outputMime = match ($format) {
            'webp' => 'image/webp',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => $mime,
        };
        if ($outputMime === 'image/webp' && !function_exists('imagewebp')) {
            throw new InvalidArgumentException('WebP encoding is not available on this server');
        }

        $maxWidth = isset($opts['maxWidth']) && is_numeric($opts['maxWidth']) && (int) $opts['maxWidth'] > 0
            ? (int) $opts['maxWidth']
            : null;
        $maxHeight = isset($opts['maxHeight']) && is_numeric($opts['maxHeight']) && (int) $opts['maxHeight'] > 0
            ? (int) $opts['maxHeight']
            : null;
        $applyToVariants = !array_key_exists('applyToVariants', $opts) || (bool) $opts['applyToVariants'];

        @set_time_limit(0);

        $beforeSize = (int) $row['size'];
        $beforeMime = $mime;
        $absolute = $this->absolutePath($row);
        $optimized = $this->images->optimize($absolute, $quality, $outputMime, $maxWidth, $maxHeight);
        $this->replaceFileContents($mediaId, $row, $optimized);

        $variantsOptimized = 0;
        if ($applyToVariants) {
            $children = $this->db->select(
                'SELECT * FROM cms_media WHERE parent_id = :parent_id',
                ['parent_id' => $mediaId],
            );
            foreach ($children as $child) {
                try {
                    $this->assertRaster($child);
                    $childPath = $this->absolutePath($child);
                    $childOut = $this->images->optimize($childPath, $quality, $outputMime, null, null);
                    $this->replaceFileContents((int) $child['id'], $child, $childOut);
                    ++$variantsOptimized;
                } catch (Throwable) {
                    // Skip non-raster / missing children; master was already optimized.
                }
            }
        }

        $media = $this->get($mediaId);
        $afterSize = (int) ($media['size'] ?? 0);
        $savedBytes = $beforeSize - $afterSize;
        $savedPercent = $beforeSize > 0
            ? round(($savedBytes / $beforeSize) * 100, 1)
            : 0.0;

        return [
            'media' => $media,
            'before' => ['size' => $beforeSize, 'mime' => $beforeMime],
            'after' => ['size' => $afterSize, 'mime' => (string) ($media['mime'] ?? $outputMime)],
            'savedBytes' => $savedBytes,
            'savedPercent' => $savedPercent,
            'variantsOptimized' => $variantsOptimized,
        ];
    }

    /**
     * @param list<int> $ids
     * @param array{
     *   quality: int,
     *   format?: string,
     *   maxWidth?: int|null,
     *   maxHeight?: int|null,
     *   applyToVariants?: bool
     * } $opts
     * @return array{
     *   results: list<array{id: int, ok: bool, error?: string, data?: array<string, mixed>}>,
     *   optimized: int,
     *   failed: int,
     *   savedBytes: int
     * }
     */
    public function bulkOptimize(array $ids, array $opts, ?MediaAclScope $scope = null): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            throw new InvalidArgumentException('ids array is required');
        }

        $results = [];
        $optimized = 0;
        $failed = 0;
        $savedBytes = 0;
        foreach ($ids as $id) {
            try {
                $data = $this->optimize($id, $opts, $scope);
                $results[] = ['id' => $id, 'ok' => true, 'data' => $data];
                ++$optimized;
                $savedBytes += (int) $data['savedBytes'];
            } catch (NotFoundException | ForbiddenException | InvalidArgumentException | RuntimeException $e) {
                $results[] = ['id' => $id, 'ok' => false, 'error' => $e->getMessage()];
                ++$failed;
            }
        }

        return [
            'results' => $results,
            'optimized' => $optimized,
            'failed' => $failed,
            'savedBytes' => $savedBytes,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array{bytes: string, mime: string, width: int, height: int, ext: string} $encoded
     */
    private function replaceFileContents(int $id, array $row, array $encoded): void
    {
        $size = strlen($encoded['bytes']);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('Optimized file too large (max 10MB)');
        }

        $oldAbsolute = $this->paths->media() . '/' . $row['disk_path'];
        $oldExt = strtolower(pathinfo((string) $row['disk_path'], PATHINFO_EXTENSION));
        $newExt = $encoded['ext'];
        $relative = (string) $row['disk_path'];

        if ($oldExt !== $newExt) {
            $dir = dirname($relative);
            $base = pathinfo($relative, PATHINFO_FILENAME);
            $relative = ($dir !== '.' ? $dir . '/' : '') . $base . '.' . $newExt;
        }

        $absolute = $this->paths->media() . '/' . $relative;
        $dirPath = dirname($absolute);
        if (!is_dir($dirPath) && !mkdir($dirPath, 0755, true) && !is_dir($dirPath)) {
            throw new RuntimeException('Cannot create media directory');
        }
        if (file_put_contents($absolute, $encoded['bytes']) === false) {
            throw new RuntimeException('Failed to store optimized media file');
        }
        @chmod($absolute, 0644);

        if ($relative !== (string) $row['disk_path'] && is_file($oldAbsolute) && $oldAbsolute !== $absolute) {
            @unlink($oldAbsolute);
        }

        $originalName = (string) $row['original_name'];
        if ($oldExt !== $newExt) {
            $nameBase = pathinfo($this->basenameOnly($originalName), PATHINFO_FILENAME);
            $originalName = ($nameBase !== '' ? $nameBase : 'file') . '.' . $newExt;
        }

        $this->db->execute(
            'UPDATE cms_media SET disk_path = :disk_path, original_name = :original_name, mime = :mime,
             size = :size, width = :width, height = :height WHERE id = :id',
            [
                'disk_path' => $relative,
                'original_name' => substr($originalName, 0, 255),
                'mime' => substr($encoded['mime'], 0, 128),
                'size' => $size,
                'width' => $encoded['width'],
                'height' => $encoded['height'],
                'id' => $id,
            ],
        );
        $this->invalidatePublicCache($id);
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function getMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $key = 'id' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $rows = $this->db->select(
            'SELECT * FROM cms_media WHERE id IN (' . implode(', ', $placeholders) . ')',
            $params,
        );
        $out = [];
        foreach ($rows as $row) {
            $serialized = $this->serialize($row);
            $out[(int) $serialized['id']] = $serialized;
        }

        return $out;
    }

    /**
     * Store raw bytes as a media item (used by resource package import).
     *
     * @return array<string, mixed>
     */
    public function storeFromBytes(
        string $bytes,
        string $originalName,
        string $mime,
        ?int $parentId = null,
        ?string $variantKey = null,
        ?int $sourceId = null,
        ?int $uploadedBy = null,
    ): array {
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

        $isLibraryRoot = $parentId === null && $sourceId === null;
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_media (parent_id, source_id, variant_key, disk_path, original_name, mime, size, width, height, created_at, uploaded_by)
             VALUES (:parent_id, :source_id, :variant_key, :disk_path, :original_name, :mime, :size, :width, :height, :created_at, :uploaded_by)',
            [
                'parent_id' => $parentId,
                'source_id' => $sourceId,
                'variant_key' => $variantKey,
                'disk_path' => $relative,
                'original_name' => substr($originalName, 0, 255),
                'mime' => substr($mime, 0, 128),
                'size' => $size,
                'width' => $width,
                'height' => $height,
                'created_at' => $now,
                'uploaded_by' => $isLibraryRoot ? $uploadedBy : null,
            ],
        );

        return $this->getUnscoped((int) $this->db->lastInsertId());
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

    public function delete(int $id, ?MediaAclScope $scope = null): void
    {
        $row = $this->findRow($id);
        if ($row === null) {
            throw new NotFoundException('Media not found');
        }
        $scope ??= MediaAclScope::unrestricted();
        if (!$this->refs()->isVisible($id, $scope)) {
            throw new NotFoundException('Media not found');
        }
        if (!$this->refs()->canMutate($id, $scope)) {
            throw new ForbiddenException('Media is used by a resource you cannot access');
        }
        $this->deleteChildren($id);
        $absolute = $this->paths->media() . '/' . $row['disk_path'];
        $this->db->execute('DELETE FROM cms_media WHERE id = :id', ['id' => $id]);
        if (is_file($absolute)) {
            @unlink($absolute);
        }
        $this->invalidatePublicCache($id);
    }

    private function deleteChildren(int $parentId): void
    {
        $children = $this->db->select(
            'SELECT id, disk_path FROM cms_media WHERE parent_id = :parent_id',
            ['parent_id' => $parentId],
        );
        foreach ($children as $child) {
            $childId = (int) $child['id'];
            $absolute = $this->paths->media() . '/' . $child['disk_path'];
            $this->db->execute('DELETE FROM cms_media WHERE id = :id', ['id' => $childId]);
            if (is_file($absolute)) {
                @unlink($absolute);
            }
            $this->invalidatePublicCache($childId);
        }
    }

    /**
     * @param list<array{prefix: string, width: int, height: int, mode: string, position: string}> $sizes
     * @param array<string, string> $positions
     * @param array<string, array{crop: array{x: float, y: float, w: float, h: float}}> $overrides
     * @return array<string, int>
     */
    private function generateVariants(
        int $parentId,
        array $sizes,
        int $rotation,
        array $positions,
        array $overrides = [],
        ?string $outputMime = null,
    ): array {
        $row = $this->findRow($parentId);
        if ($row === null) {
            throw new RuntimeException('Media not found', 404);
        }
        $absolute = $this->absolutePath($row);
        $mime = $outputMime ?? (string) $row['mime'];

        $variants = [];
        foreach ($sizes as $size) {
            $prefix = $size['prefix'];
            $position = $positions[$prefix] ?? $size['position'];
            $transformed = $this->images->transform(
                $absolute,
                $rotation,
                $size['mode'],
                $size['width'],
                $size['height'],
                $position,
                $mime,
                $overrides[$prefix]['crop'] ?? null,
            );
            $variantName = $prefix . '_' . (string) $row['original_name'];
            $stored = $this->storeFromBytes(
                $transformed['bytes'],
                $variantName,
                $transformed['mime'],
                $parentId,
                $prefix,
            );
            $variants[$prefix] = (int) $stored['id'];
        }

        return $variants;
    }

    /**
     * Re-encode the master in place when the field asks for a storage format.
     *
     * @param 'webp'|'jpeg'|'png'|null $encodeFormat
     */
    private function maybeReencode(int $mediaId, ?string $encodeFormat): void
    {
        $outputMime = MediaFieldConfig::encodeFormatToMime($encodeFormat);
        if ($outputMime === null) {
            return;
        }
        if ($outputMime === 'image/webp' && !function_exists('imagewebp')) {
            throw new InvalidArgumentException('WebP encoding is not available on this server');
        }

        $row = $this->findRow($mediaId);
        if ($row === null) {
            throw new NotFoundException('Media not found');
        }
        $this->assertRaster($row);

        $currentMime = strtolower(trim(explode(';', (string) $row['mime'])[0]));
        if ($currentMime === 'image/gif' || $currentMime === 'image/svg+xml') {
            throw new InvalidArgumentException('GIF and SVG cannot be converted');
        }
        if ($currentMime === $outputMime) {
            return;
        }

        $optimized = $this->images->optimize($this->absolutePath($row), 85, $outputMime);
        $this->replaceFileContents($mediaId, $row, $optimized);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertRaster(array $row): void
    {
        $mime = (string) $row['mime'];
        if (!str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            throw new InvalidArgumentException('Image transforms require a raster image');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function absolutePath(array $row): string
    {
        $absolute = $this->paths->media() . '/' . $row['disk_path'];
        if (!is_file($absolute)) {
            throw new RuntimeException('Original media file missing');
        }

        return $absolute;
    }

    private function editedName(string $originalName): string
    {
        $base = $this->basenameOnly($originalName);

        return str_starts_with($base, 'edited_') ? $base : 'edited_' . $base;
    }

    /**
     * @param list<string>|null $allowedFormats
     */
    private function assertFormatsAllowed(string $mime, string $originalName, ?array $allowedFormats): void
    {
        if ($allowedFormats === null || $allowedFormats === []) {
            return;
        }
        $normalized = [];
        foreach ($allowedFormats as $ext) {
            $ext = strtolower(ltrim(trim((string) $ext), '.'));
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
            if ($ext !== '') {
                $normalized[$ext] = true;
            }
        }
        if ($normalized === []) {
            return;
        }

        $mime = strtolower(trim(explode(';', $mime)[0]));
        $extFromMime = null;
        foreach (self::EXT_TO_MIME as $ext => $mapped) {
            if ($mapped === $mime) {
                $extFromMime = $ext === 'jpeg' ? 'jpg' : $ext;
                break;
            }
        }
        $base = strtolower($this->basenameOnly($originalName));
        $nameExt = pathinfo($base, PATHINFO_EXTENSION);
        if ($nameExt === 'jpeg') {
            $nameExt = 'jpg';
        }

        $ok = ($extFromMime !== null && isset($normalized[$extFromMime]))
            || ($nameExt !== '' && isset($normalized[$nameExt]));
        if (!$ok) {
            throw new InvalidArgumentException(
                'File format not allowed for this field (allowed: ' . implode(', ', array_keys($normalized)) . ')',
            );
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
     * First PHP hit copies/hardlinks into public/media/{id}/{safeName} so Apache/nginx
     * can serve subsequent requests as static files (see public/.htaccess -f shortcut).
     * Skips SVG and other non-inline-safe types (must keep Content-Disposition via PHP).
     */
    public function warmPublicCache(int $id): void
    {
        $file = $this->absoluteFile($id);
        if ($file === null) {
            return;
        }

        $mime = strtolower(trim(explode(';', $file['mime'])[0]));
        if (!self::isPublicCacheableMime($mime)) {
            return;
        }

        $safeName = self::safePublicFilename($file['name'], $mime);
        $destDir = $this->paths->publicMedia() . '/' . $id;
        $dest = $destDir . '/' . $safeName;

        if (is_file($dest)) {
            return;
        }

        $this->ensurePublicMediaProtected();
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            return;
        }

        $src = $file['path'];
        if (@link($src, $dest) || @copy($src, $dest)) {
            @chmod($dest, 0644);
        }
    }

    public function invalidatePublicCache(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $dir = $this->paths->publicMedia() . '/' . $id;
        if (!is_dir($dir)) {
            return;
        }
        $this->removeDirectory($dir);
    }

    public static function isPublicCacheableMime(string $mime): bool
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        return match (true) {
            str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml' => true,
            $mime === 'application/pdf' => true,
            str_starts_with($mime, 'video/') => true,
            default => false,
        };
    }

    private function ensurePublicMediaProtected(): void
    {
        $root = $this->paths->publicMedia();
        if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
            return;
        }

        $htaccess = $root . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, self::PUBLIC_MEDIA_HTACCESS);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
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
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function aclWhere(MediaAclScope $scope): array
    {
        $base = 'parent_id IS NULL AND source_id IS NULL';
        if (!$scope->isRestricted()) {
            return [$base, []];
        }

        $allowed = $scope->allowedResourceIds ?? [];
        $params = [];
        $parts = [];

        if ($allowed !== []) {
            $placeholders = [];
            foreach ($allowed as $i => $resourceId) {
                $key = 'acl_r' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $resourceId;
            }
            $parts[] = 'EXISTS (
                SELECT 1 FROM cms_media_refs r
                WHERE r.media_id = cms_media.id
                  AND r.resource_id IN (' . implode(', ', $placeholders) . ')
            )';
        }

        if ($scope->userId !== null) {
            $params['acl_uid'] = $scope->userId;
            $parts[] = '(
                uploaded_by = :acl_uid
                AND NOT EXISTS (SELECT 1 FROM cms_media_refs r2 WHERE r2.media_id = cms_media.id)
            )';
        }

        if ($parts === []) {
            return [$base . ' AND 1 = 0', []];
        }

        return [$base . ' AND (' . implode(' OR ', $parts) . ')', $params];
    }

    private function assertMutable(int $mediaId, ?MediaAclScope $scope): void
    {
        $scope ??= MediaAclScope::unrestricted();
        if ($this->findRow($mediaId) === null) {
            throw new NotFoundException('Media not found');
        }
        if (!$this->refs()->isVisible($mediaId, $scope)) {
            throw new NotFoundException('Media not found');
        }
        if (!$this->refs()->canMutate($mediaId, $scope)) {
            throw new ForbiddenException('Media is used by a resource you cannot access');
        }
    }

    /**
     * Internal read without ACL (variants / post-insert).
     *
     * @return array<string, mixed>
     */
    private function getUnscoped(int $id): array
    {
        $row = $this->findRow($id);
        if ($row === null) {
            throw new NotFoundException('Media not found');
        }

        return $this->serialize($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        $id = (int) $row['id'];
        $originalName = is_string($row['original_name'] ?? null) ? (string) $row['original_name'] : null;
        $mime = is_string($row['mime'] ?? null) ? (string) $row['mime'] : null;
        $urls = self::publicUrls($this->appUrl, $id, $originalName, $mime);

        return [
            'id' => $id,
            'parentId' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
            'sourceId' => ($row['source_id'] ?? null) === null ? null : (int) $row['source_id'],
            'variantKey' => $row['variant_key'] ?? null,
            'originalName' => $row['original_name'],
            'mime' => $row['mime'],
            'size' => (int) $row['size'],
            'width' => $row['width'] === null ? null : (int) $row['width'],
            'height' => $row['height'] === null ? null : (int) $row['height'],
            'url' => $urls['url'],
            'fullUrl' => $urls['fullUrl'],
            'createdAt' => $row['created_at'],
            'uploadedBy' => ($row['uploaded_by'] ?? null) === null ? null : (int) $row['uploaded_by'],
        ];
    }
}
