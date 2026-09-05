<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Content\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function testValidSlug(): void
    {
        $this->assertTrue(Slug::isValid('users'));
        $this->assertTrue(Slug::isValid('blog_posts'));
        $this->assertFalse(Slug::isValid('Users'));
        $this->assertFalse(Slug::isValid('1users'));
        $this->assertFalse(Slug::isValid('users-v2'));
    }

    public function testFromName(): void
    {
        $this->assertSame('blog_posts', Slug::fromName('Blog Posts'));
        $this->assertSame('articles', Slug::fromName('articles'));
    }
}
