<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Content\UrlSlug;
use PHPUnit\Framework\TestCase;

final class UrlSlugTest extends TestCase
{
    public function testSlugifiesLatinAndStripsSpecialChars(): void
    {
        $this->assertSame('hello-world-123', UrlSlug::from('Hello World! 123'));
    }

    public function testTransliteratesCyrillic(): void
    {
        $this->assertSame('privet-mir', UrlSlug::from('Привет мир'));
    }
}
