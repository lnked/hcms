<?php

declare(strict_types=1);

namespace Cms\Media;

use InvalidArgumentException;
use RuntimeException;

/**
 * GD-based image rotate / resize-fit / crop-cover with 9-cell anchor.
 */
final class ImageProcessor
{
    /**
     * @return array{bytes: string, mime: string, width: int, height: int, ext: string}
     */
    public function transform(
        string $sourcePath,
        int $rotation,
        string $mode,
        int $targetWidth,
        int $targetHeight,
        string $position,
        ?string $outputMime = null,
    ): array {
        $src = $this->load($sourcePath);
        if ($rotation !== 0) {
            $src = $this->rotate($src, $rotation);
        }

        $src = $mode === 'resize'
            ? $this->resizeFit($src, $targetWidth, $targetHeight)
            : $this->cropCover($src, $targetWidth, $targetHeight, $position);

        $mime = $outputMime ?? $this->detectMime($sourcePath) ?? 'image/jpeg';
        $encoded = $this->encode($src, $mime);
        $width = imagesx($src);
        $height = imagesy($src);

        return [
            'bytes' => $encoded['bytes'],
            'mime' => $encoded['mime'],
            'width' => $width,
            'height' => $height,
            'ext' => $encoded['ext'],
        ];
    }

    /**
     * Rotate only (used when regenerating from original with rotation applied first).
     *
     * @return \GdImage
     */
    public function loadRotated(string $sourcePath, int $rotation): \GdImage
    {
        $src = $this->load($sourcePath);
        if ($rotation === 0) {
            return $src;
        }

        return $this->rotate($src, $rotation);
    }

    /**
     * @return \GdImage
     */
    private function load(string $path): \GdImage
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException('Image file not found');
        }
        $info = @getimagesize($path);
        if (!is_array($info)) {
            throw new InvalidArgumentException('Unsupported or corrupt image');
        }
        $type = $info[2];
        $img = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if ($img === false) {
            throw new InvalidArgumentException('Failed to decode image (SVG and some formats cannot be transformed)');
        }
        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($img);
        }
        @imagealphablending($img, true);
        @imagesavealpha($img, true);

        return $img;
    }

    /**
     * @param \GdImage $src
     * @return \GdImage
     */
    private function rotate(\GdImage $src, int $rotation): \GdImage
    {
        // imagerotate uses counter-clockwise; UI degrees are clockwise.
        $gdAngle = match ($rotation) {
            90 => -90,
            180 => 180,
            270 => -270,
            default => 0,
        };
        if ($gdAngle === 0) {
            $copy = imagecreatetruecolor(imagesx($src), imagesy($src));
            if ($copy === false) {
                throw new RuntimeException('Failed to allocate image');
            }
            $this->preserveAlpha($copy);
            imagecopy($copy, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));

            return $copy;
        }
        $bg = imagecolorallocatealpha($src, 0, 0, 0, 127);
        $rotated = imagerotate($src, $gdAngle, $bg !== false ? $bg : 0);
        if ($rotated === false) {
            throw new RuntimeException('Failed to rotate image');
        }
        $this->preserveAlpha($rotated);

        return $rotated;
    }

    /**
     * Fit inside box keeping aspect ratio (no letterbox).
     *
     * @param \GdImage $src
     * @return \GdImage
     */
    private function resizeFit(\GdImage $src, int $tw, int $th): \GdImage
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = min($tw / $sw, $th / $sh);
        $nw = max(1, (int) round($sw * $scale));
        $nh = max(1, (int) round($sh * $scale));

        return $this->resample($src, 0, 0, $sw, $sh, $nw, $nh);
    }

    /**
     * Scale cover then crop WxH using Photoshop-style 9-cell anchor.
     *
     * @param \GdImage $src
     * @return \GdImage
     */
    private function cropCover(\GdImage $src, int $tw, int $th, string $position): \GdImage
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = max($tw / $sw, $th / $sh);
        $rw = (int) ceil($sw * $scale);
        $rh = (int) ceil($sh * $scale);
        $scaled = $this->resample($src, 0, 0, $sw, $sh, $rw, $rh);

        [$ox, $oy] = $this->anchorOffset($rw, $rh, $tw, $th, $position);
        $out = imagecreatetruecolor($tw, $th);
        if ($out === false) {
            throw new RuntimeException('Failed to allocate crop canvas');
        }
        $this->preserveAlpha($out);
        imagecopy($out, $scaled, 0, 0, $ox, $oy, $tw, $th);

        return $out;
    }

    /**
     * @return array{0: int, 1: int} crop origin x,y on scaled image
     */
    private function anchorOffset(int $rw, int $rh, int $tw, int $th, string $position): array
    {
        $maxX = max(0, $rw - $tw);
        $maxY = max(0, $rh - $th);
        $x = match ($position) {
            'nw', 'w', 'sw' => 0,
            'ne', 'e', 'se' => $maxX,
            default => (int) floor($maxX / 2),
        };
        $y = match ($position) {
            'nw', 'n', 'ne' => 0,
            'sw', 's', 'se' => $maxY,
            default => (int) floor($maxY / 2),
        };

        return [$x, $y];
    }

    /**
     * @param \GdImage $src
     * @return \GdImage
     */
    private function resample(\GdImage $src, int $sx, int $sy, int $sw, int $sh, int $dw, int $dh): \GdImage
    {
        $dst = imagecreatetruecolor($dw, $dh);
        if ($dst === false) {
            throw new RuntimeException('Failed to allocate resample canvas');
        }
        $this->preserveAlpha($dst);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh);

        return $dst;
    }

    /**
     * @param \GdImage $img
     */
    private function preserveAlpha(\GdImage $img): void
    {
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefilledrectangle($img, 0, 0, imagesx($img), imagesy($img), $transparent);
        }
        imagealphablending($img, true);
    }

    private function detectMime(string $path): ?string
    {
        $info = @getimagesize($path);
        if (!is_array($info)) {
            return null;
        }

        return match ($info[2]) {
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG => 'image/png',
            IMAGETYPE_GIF => 'image/gif',
            IMAGETYPE_WEBP => 'image/webp',
            default => null,
        };
    }

    /**
     * @param \GdImage $img
     * @return array{bytes: string, mime: string, ext: string}
     */
    private function encode(\GdImage $img, string $mime): array
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        ob_start();
        $ok = match ($mime) {
            'image/png' => imagepng($img, null, 6),
            'image/gif' => imagegif($img),
            'image/webp' => function_exists('imagewebp')
                ? imagewebp($img, null, 85)
                : false,
            default => false,
        };
        if ($ok === false) {
            $ok = imagejpeg($img, null, 88);
            $mime = 'image/jpeg';
        }
        $bytes = ob_get_clean();
        if ($ok === false || $bytes === false || $bytes === '') {
            throw new RuntimeException('Failed to encode image');
        }
        $ext = match ($mime) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        return ['bytes' => $bytes, 'mime' => $mime, 'ext' => $ext];
    }
}
