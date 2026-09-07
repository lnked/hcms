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

    public function testTransformCropsRectBeforeResizing(): void
    {
        $path = $this->makeJpeg(400, 200);
        $processor = new ImageProcessor();

        // Right half is a 200×200 square, so a fit into 100×100 fills the box exactly.
        $out = $processor->transform($path, 0, 'resize', 100, 100, 'c', null, [
            'x' => 0.5,
            'y' => 0.0,
            'w' => 0.5,
            'h' => 1.0,
        ]);
        self::assertSame(100, $out['width']);
        self::assertSame(100, $out['height']);
    }

    public function testBakeAppliesRotationThenCrop(): void
    {
        $path = $this->makeJpeg(400, 200);
        $processor = new ImageProcessor();

        // 400×200 rotated 90° is 200×400; the top half of that is 200×200.
        $out = $processor->bake($path, [
            'rotation' => 90,
            'crop' => ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 0.5],
        ]);
        self::assertSame(200, $out['width']);
        self::assertSame(200, $out['height']);
    }

    public function testBakeRotatesClockwiseKeepingPixels(): void
    {
        $path = $this->makeJpeg(400, 200);
        $processor = new ImageProcessor();

        $out = $processor->bake($path, ['rotation' => 90]);
        self::assertSame(200, $out['width']);
        self::assertSame(400, $out['height']);

        $image = imagecreatefromstring($out['bytes']);
        self::assertNotFalse($image);
        // Source is red on the left, blue on the right — 90° CW puts red on top.
        self::assertTrue($this->isRed($image, 100, 20));
        self::assertTrue($this->isBlue($image, 100, 380));
    }

    public function testBakeClampsCropToImageBounds(): void
    {
        $path = $this->makeJpeg(400, 200);
        $processor = new ImageProcessor();

        $out = $processor->bake($path, ['crop' => ['x' => 0.9, 'y' => 0.0, 'w' => 0.5, 'h' => 1.0]]);
        self::assertSame(40, $out['width']);
        self::assertSame(200, $out['height']);
    }

    public function testBakeFlipsHorizontally(): void
    {
        $path = $this->makeJpeg(400, 200);
        $processor = new ImageProcessor();

        $out = $processor->bake($path, ['flipH' => true]);
        $image = imagecreatefromstring($out['bytes']);
        self::assertNotFalse($image);
        // Source is red on the left, blue on the right — a flip swaps them.
        self::assertTrue($this->isBlue($image, 20, 100));
        self::assertTrue($this->isRed($image, 380, 100));
    }

    public function testMediaValueLegacyId(): void
    {
        $item = MediaValue::normalize(42, false);
        self::assertIsArray($item);
        self::assertSame(42, $item['id']);
        self::assertSame(0, $item['rotation']);
        self::assertNull($item['sourceId']);
        self::assertNull($item['edit']);
        self::assertSame([], $item['overrides']);
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

    public function testMediaValueKeepsVariantsSentBackExpanded(): void
    {
        // The admin PATCHes the value it read from the API, where variants are objects.
        $item = MediaValue::normalize([
            'id' => 30,
            'variants' => ['thumb' => ['id' => 31, 'url' => '/media/31']],
        ], false);

        self::assertIsArray($item);
        self::assertSame(['thumb' => 31], $item['variants']);
    }

    public function testMediaValueKeepsEditAndOverrides(): void
    {
        $value = [
            'id' => 20,
            'sourceId' => 10,
            'edit' => ['rotation' => 90, 'flipH' => true, 'crop' => ['x' => 0.1, 'y' => 0, 'w' => 0.8, 'h' => 1]],
            'positions' => ['thumb' => 'nw'],
            'overrides' => ['thumb' => ['crop' => ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5]]],
            'variants' => ['thumb' => 21],
        ];

        $item = MediaValue::normalize($value, false);
        self::assertIsArray($item);
        self::assertSame(10, $item['sourceId']);
        self::assertSame(90, $item['edit']['rotation']);
        self::assertTrue($item['edit']['flipH']);
        self::assertEqualsWithDelta(0.8, $item['edit']['crop']['w'], 1e-9);
        self::assertEqualsWithDelta(0.5, $item['overrides']['thumb']['crop']['w'], 1e-9);

        self::assertSame([20, 10, 21], MediaValue::collectIds($value));

        $remapped = MediaValue::remapIds($value, [20 => 200, 10 => 100, 21 => 210]);
        self::assertSame(200, $remapped['id']);
        self::assertSame(100, $remapped['sourceId']);
        self::assertSame(210, $remapped['variants']['thumb']);
    }

    private function isRed(\GdImage $image, int $x, int $y): bool
    {
        $rgb = imagecolorat($image, $x, $y);

        return (($rgb >> 16) & 0xFF) > 128 && ($rgb & 0xFF) < 128;
    }

    private function isBlue(\GdImage $image, int $x, int $y): bool
    {
        $rgb = imagecolorat($image, $x, $y);

        return ($rgb & 0xFF) > 128 && (($rgb >> 16) & 0xFF) < 128;
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
