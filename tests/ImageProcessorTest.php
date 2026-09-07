<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Media\ImageProcessor;
use Cms\Media\MediaValue;
use PHPUnit\Framework\TestCase;

final class ImageProcessorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/hcms_img_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    public function testCropCoverCenterAndAnchors(): void
    {
        $path = $this->makeJpeg(400, 200);
        $processor = new ImageProcessor();

        $center = $processor->transform($path, 0, 'crop', 100, 100, 'c');
        self::assertSame(100, $center['width']);
        self::assertSame(100, $center['height']);

        $nw = $processor->transform($path, 0, 'crop', 100, 100, 'nw');
        self::assertSame(100, $nw['width']);
        self::assertSame(100, $nw['height']);
    }

    public function testResizeFitKeepsAspect(): void
    {
        $path = $this->makeJpeg(400, 200);
        $processor = new ImageProcessor();
        $out = $processor->transform($path, 0, 'resize', 100, 100, 'c');
        self::assertSame(100, $out['width']);
        self::assertSame(50, $out['height']);
    }

    public function testRotate90SwapsDimensions(): void
    {
        $path = $this->makeJpeg(400, 200);
        $processor = new ImageProcessor();
        // Fit into huge box so only rotation (and no downscale) matters for aspect swap check.
        // After 90° CW, 400×200 → 200×400; fit scale into 1000×1000 = 2.5 → 500×1000.
        $out = $processor->transform($path, 90, 'resize', 1000, 1000, 'c');
        self::assertSame(500, $out['width']);
        self::assertSame(1000, $out['height']);

        $cropped = $processor->transform($path, 90, 'crop', 100, 100, 'c');
        self::assertSame(100, $cropped['width']);
        self::assertSame(100, $cropped['height']);
    }

    public function testMediaValueLegacyId(): void
    {
        $item = MediaValue::normalize(42, false);
        self::assertIsArray($item);
        self::assertSame(42, $item['id']);
        self::assertSame(0, $item['rotation']);
    }

    public function testMediaValueCollectAndRemap(): void
    {
        $value = [
            'id' => 10,
            'rotation' => 90,
            'positions' => ['thumb' => 'nw'],
            'variants' => ['thumb' => 11],
        ];
        self::assertSame([10, 11], MediaValue::collectIds($value));
        $remapped = MediaValue::remapIds($value, [10 => 100, 11 => 110]);
        self::assertSame(100, $remapped['id']);
        self::assertSame(110, $remapped['variants']['thumb']);
    }

    private function makeJpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        self::assertNotFalse($img);
        $red = imagecolorallocate($img, 255, 0, 0);
        $blue = imagecolorallocate($img, 0, 0, 255);
        imagefilledrectangle($img, 0, 0, (int) ($w / 2), $h, $red);
        imagefilledrectangle($img, (int) ($w / 2), 0, $w, $h, $blue);
        $path = $this->tmpDir . '/src.jpg';
        imagejpeg($img, $path, 90);
        imagedestroy($img);

        return $path;
    }
}
