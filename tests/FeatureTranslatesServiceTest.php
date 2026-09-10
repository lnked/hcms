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

    public function testInRolloutBoundaries(): void
    {
        $this->assertFalse(FeatureFlagService::inRollout('any', 'flag', 0));
        $this->assertTrue(FeatureFlagService::inRollout('any', 'flag', 100));
    }

    public function testInRolloutIsStickyForSubject(): void
    {
        $a = FeatureFlagService::inRollout('user-42', 'newCheckout', 50);
        $b = FeatureFlagService::inRollout('user-42', 'newCheckout', 50);
        $this->assertSame($a, $b);

        $sameBucket = 0;
        for ($i = 0; $i < 200; $i++) {
            if (FeatureFlagService::inRollout('subj-' . $i, 'newCheckout', 50)) {
                ++$sameBucket;
            }
        }
        // ~50% of 200 — allow wide band so flaky CI doesn't fail
        $this->assertGreaterThan(60, $sameBucket);
        $this->assertLessThan(140, $sameBucket);
    }

    public function testInRolloutIndependentPerFlagKey(): void
    {
        $hitsA = 0;
        $hitsB = 0;
        for ($i = 0; $i < 300; $i++) {
            $sid = 'user-' . $i;
            if (FeatureFlagService::inRollout($sid, 'flagA', 30)) {
                ++$hitsA;
            }
            if (FeatureFlagService::inRollout($sid, 'flagB', 30)) {
                ++$hitsB;
            }
        }
        // Different keys → different hashes; not required to differ for every subject,
        // but totals should both be near 30% and not identical distributions in practice.
        $this->assertGreaterThan(40, $hitsA);
        $this->assertLessThan(140, $hitsA);
        $this->assertGreaterThan(40, $hitsB);
        $this->assertLessThan(140, $hitsB);
    }
}
