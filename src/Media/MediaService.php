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

    public function __construct(
        private readonly Connection $db,
        private readonly Paths $paths,
    ) {
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
        $extRaw = pathinfo($name, PATHINFO_EXTENSION);
        $safeExt = preg_replace('/[^a-z0-9]/i', '', strtolower($extRaw));
        $ext = is_string($safeExt) && $safeExt !== '' ? $safeExt : 'bin';

        $relative = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $absolute = $this->paths->media() . '/' . $relative;
        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create media directory');
        }
        if (!move_uploaded_file($tmp, $absolute)) {
            throw new RuntimeException('Failed to store upload');
        }

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
                'original_name' => substr($name, 0, 255),
                'mime' => substr($mime, 0, 128),
                'size' => $size,
                'width' => $width,
                'height' => $height,
                'created_at' => $now,
            ],
        );

        return $this->get((int) $this->db->lastInsertId());
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
