<?php

declare(strict_types=1);

namespace Cms\Media;

use InvalidArgumentException;
use RuntimeException;

/**
 * GD-based image rotate / flip / crop-rect / resize-fit / crop-cover with 9-cell anchor.
 *
 * Edit pipeline is always rotate -> flip -> crop rect; normalized crop rects are
 * therefore expressed in the coordinate space of the rotated + flipped image.
 */
final class ImageProcessor
{
    /**
     * @param array{x: float, y: float, w: float, h: float}|null $crop normalized 0..1, applied after rotation
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
        ?array $crop = null,
        ?int $quality = null,
    ): array {
        $src = $this->edit($this->load($sourcePath), $rotation, false, false, $crop);

        $src = $mode === 'resize'
            ? $this->resizeFit($src, $targetWidth, $targetHeight)
            : $this->cropCover($src, $targetWidth, $targetHeight, $position);

        return $this->finish(
            $src,
            $outputMime ?? $this->detectMime($sourcePath) ?? 'image/jpeg',
            $quality,
        );
    }

    /**
     * Bake a base edit (rotation + flips + crop) into a new master image.
     *
     * @param array{rotation?: int, flipH?: bool, flipV?: bool, crop?: array{x: float, y: float, w: float, h: float}|null} $edit
     * @return array{bytes: string, mime: string, width: int, height: int, ext: string}
     */
    public function bake(string $sourcePath, array $edit, ?string $outputMime = null, ?int $quality = null): array
    {
        $src = $this->edit(
            $this->load($sourcePath),
            (int) ($edit['rotation'] ?? 0),
            (bool) ($edit['flipH'] ?? false),
            (bool) ($edit['flipV'] ?? false),
            $edit['crop'] ?? null,
        );

        return $this->finish(
            $src,
            $outputMime ?? $this->detectMime($sourcePath) ?? 'image/jpeg',
            $quality,
        );
    }

    /**
     * Re-encode (and optionally downscale) an image for size reduction.
     *
     * @return array{bytes: string, mime: string, width: int, height: int, ext: string}
     */
    public function optimize(
        string $sourcePath,
        int $quality,
        ?string $outputMime = null,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
    ): array {
        $quality = max(1, min(100, $quality));
        $src = $this->load($sourcePath);
        $sw = imagesx($src);
        $sh = imagesy($src);
        $tw = $maxWidth !== null && $maxWidth > 0 ? $maxWidth : $sw;
        $th = $maxHeight !== null && $maxHeight > 0 ? $maxHeight : $sh;
        if ($sw > $tw || $sh > $th) {
            $src = $this->resizeFit($src, $tw, $th);
        }
        $mime = $outputMime ?? $this->detectMime($sourcePath) ?? 'image/jpeg';

        return $this->finish($src, $mime, $quality);
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
     * @param \GdImage $src
     * @param array{x: float, y: float, w: float, h: float}|null $crop
     * @return \GdImage
     */
    private function edit(\GdImage $src, int $rotation, bool $flipH, bool $flipV, ?array $crop): \GdImage
    {
        if ($rotation !== 0) {
            $src = $this->rotate($src, $rotation);
        }
        if ($flipH || $flipV) {
            $src = $this->flip($src, $flipH, $flipV);
        }
        if ($crop !== null) {
            $src = $this->cropRect($src, $crop);
        }

        return $src;
    }

    /**
     * @param \GdImage $img
     * @return array{bytes: string, mime: string, width: int, height: int, ext: string}
     */
    private function finish(\GdImage $img, string $mime, ?int $quality = null): array
    {
        $encoded = $this->encode($img, $mime, $quality);

        return [
            'bytes' => $encoded['bytes'],
            'mime' => $encoded['mime'],
            'width' => imagesx($img),
            'height' => imagesy($img),
            'ext' => $encoded['ext'],
        ];
    }

    /**
     * @return \GdImage
     */
    private function load(string $path): \GdImage
    {
        // Without GD every call below would die with "undefined function"; say why instead.
        if (!extension_loaded('gd')) {
            throw new RuntimeException('PHP extension gd is required for image transforms');
        }
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
        $this->keepAlpha($img);

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
            90 => 270,
            180 => 180,
            270 => 90,
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
        $this->keepAlpha($rotated);

        return $rotated;
    }

    /**
     * @param \GdImage $src
     * @return \GdImage
     */
    private function flip(\GdImage $src, bool $horizontal, bool $vertical): \GdImage
    {
        $mode = match (true) {
            $horizontal && $vertical => IMG_FLIP_BOTH,
            $horizontal => IMG_FLIP_HORIZONTAL,
            default => IMG_FLIP_VERTICAL,
        };
        if (!imageflip($src, $mode)) {
            throw new RuntimeException('Failed to flip image');
        }

        return $src;
    }

    /**
     * Crop a normalized 0..1 rect out of the image.
     *
     * @param \GdImage $src
     * @param array{x: float, y: float, w: float, h: float} $crop
     * @return \GdImage
     */
    private function cropRect(\GdImage $src, array $crop): \GdImage
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $x = (int) round($this->clamp01($crop['x']) * $sw);
        $y = (int) round($this->clamp01($crop['y']) * $sh);
        $w = (int) round($this->clamp01($crop['w']) * $sw);
        $h = (int) round($this->clamp01($crop['h']) * $sh);
        $w = max(1, min($w, $sw - $x));
        $h = max(1, min($h, $sh - $y));
        if ($x === 0 && $y === 0 && $w === $sw && $h === $sh) {
            return $src;
        }

        $out = imagecreatetruecolor($w, $h);
        if ($out === false) {
            throw new RuntimeException('Failed to allocate crop canvas');
        }
        $this->preserveAlpha($out);
        imagecopy($out, $src, 0, 0, $x, $y, $w, $h);

        return $out;
    }

    private function clamp01(mixed $value): float
    {
        $float = is_numeric($value) ? (float) $value : 0.0;

        return max(0.0, min(1.0, $float));
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
     * Prepare a freshly allocated canvas: transparent background, alpha copied verbatim.
     *
     * @param \GdImage $img
     */
    private function preserveAlpha(\GdImage $img): void
    {
        $this->keepAlpha($img);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefilledrectangle($img, 0, 0, imagesx($img) - 1, imagesy($img) - 1, $transparent);
        }
    }

    /**
     * Keep the alpha channel of an image that already holds pixels — never wipes it.
     *
     * @param \GdImage $img
     */
    private function keepAlpha(\GdImage $img): void
    {
        imagealphablending($img, false);
        imagesavealpha($img, true);
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
    private function encode(\GdImage $img, string $mime, ?int $quality = null): array
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        $q = $quality !== null ? max(1, min(100, $quality)) : null;
        $jpegQ = $q ?? 88;
        $webpQ = $q ?? 85;
        // PNG compression level 0 (none) … 9 (max); map quality so 100 → lightest file pressure.
        $pngLevel = $q !== null ? (int) round((100 - $q) / 100 * 9) : 6;

        ob_start();
        $ok = match ($mime) {
            'image/png' => imagepng($img, null, $pngLevel),
            'image/gif' => imagegif($img),
            'image/webp' => function_exists('imagewebp')
                ? imagewebp($img, null, $webpQ)
                : false,
            'image/jpeg', 'image/jpg' => imagejpeg($img, null, $jpegQ),
            default => false,
        };
        if ($ok === false) {
            $ok = imagejpeg($img, null, $jpegQ);
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
