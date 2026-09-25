<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Fields\FieldTypeRegistry;
use PHPUnit\Framework\TestCase;

final class FieldTypeRegistryTest extends TestCase
{
    public function testRegistersMvpTypes(): void
    {
        $registry = new FieldTypeRegistry();
        $this->assertContains('string', $registry->names());
        $this->assertContains('enum', $registry->names());
        $this->assertContains('slug', $registry->names());
        $this->assertContains('richtext', $registry->names());
        $this->assertContains('blocks', $registry->names());
        $this->assertTrue($registry->has('email'));
        $this->assertSame('integer', $registry->get('integer')->name());
        $this->assertSame('richtext', $registry->get('richtext')->name());
        $this->assertSame('number', $registry->get('integer')->widget());
        $descriptors = $registry->descriptors();
        $this->assertNotEmpty($descriptors);
        $this->assertArrayHasKey('widget', $descriptors[0]);
    }

    public function testEnumRequiresOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FieldTypeRegistry())->get('enum')->validateConfig(['options' => []]);
    }

    public function testSlugRequiresAssociatedWith(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FieldTypeRegistry())->get('slug')->validateConfig(['associatedWith' => '']);
    }

    public function testImageEncodeFormatOptional(): void
    {
        $image = (new FieldTypeRegistry())->get('image');
        $image->validateConfig(['formats' => [], 'encodeFormat' => null, 'sizes' => []]);
        $image->validateConfig(['formats' => [], 'encodeFormat' => 'webp', 'sizes' => []]);
        $image->validateConfig(['formats' => [], 'encodeFormat' => 'avif', 'sizes' => []]);
        $image->validateConfig(['formats' => [], 'encodeFormat' => 'jpeg', 'sizes' => []]);
        $this->assertSame('image', $image->name());
        $this->assertArrayHasKey('encodeFormat', $image->defaultConfig());
        $this->assertContains('avif', \Cms\Fields\Types\MediaFieldConfig::IMAGE_FORMATS);
        $this->assertContains('avif', \Cms\Fields\Types\MediaFieldConfig::ENCODE_FORMATS);
    }

    public function testImageEncodeFormatRejectsUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FieldTypeRegistry())->get('image')->validateConfig([
            'formats' => [],
            'encodeFormat' => 'gif',
            'sizes' => [],
        ]);
    }
}
