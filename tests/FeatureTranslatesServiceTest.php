<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\FeatureFlags\FeatureFlagService;
use Cms\Translates\TranslationService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FeatureTranslatesServiceTest extends TestCase
{
    public function testFeaturePathNormalization(): void
    {
        $this->assertSame('/api/features', FeatureFlagService::normalizePath('features'));
        $this->assertSame('/api/features', FeatureFlagService::normalizePath('/api/features'));
        $this->assertSame('/api/v1/flags', FeatureFlagService::normalizePath('/api/v1/flags'));
    }

    public function testFeaturePathValidationRejectsBad(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FeatureFlagService::assertValidPath('/wrong');
    }

    public function testFeaturePathValidationAccepts(): void
    {
        FeatureFlagService::assertValidPath('/api/features');
        FeatureFlagService::assertValidPath('/api/v1/my-flags');
        $this->addToAssertionCount(1);
    }

    public function testTranslatesPathNormalization(): void
    {
        $this->assertSame('/api/translates', TranslationService::normalizePath('translates'));
        $this->assertSame('/api/i18n', TranslationService::normalizePath('/api/i18n'));
    }

    public function testDefaultApiSettings(): void
    {
        $f = FeatureFlagService::defaultApiSettings();
        $this->assertTrue($f['enabled']);
        $this->assertSame('/api/features', $f['path']);
        $this->assertFalse($f['requireToken']);

        $t = TranslationService::defaultApiSettings();
        $this->assertSame('/api/translates', $t['path']);
    }
}
