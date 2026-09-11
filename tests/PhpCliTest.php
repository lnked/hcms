<?php

declare(strict_types=1);

namespace Cms\Tests\Core;

use Cms\Core\PhpCli;
use PHPUnit\Framework\TestCase;

final class PhpCliTest extends TestCase
{
    public function testCandidatesPreferExplicitAndEnv(): void
    {
        $prev = $_ENV['CMS_PHP_CLI'] ?? null;
        $_ENV['CMS_PHP_CLI'] = '/custom/php8.3';

        try {
            $candidates = PhpCli::candidates('/explicit/php');
            self::assertSame('/explicit/php', $candidates[0]);
            self::assertContains('/custom/php8.3', $candidates);
            self::assertContains('php', $candidates);
        } finally {
            if ($prev === null) {
                unset($_ENV['CMS_PHP_CLI']);
            } else {
                $_ENV['CMS_PHP_CLI'] = $prev;
            }
        }
    }

    public function testVersionIdOfCurrentBinaryMatchesRuntime(): void
    {
        if (!\defined('PHP_BINARY') || PHP_BINARY === '' || !is_file(PHP_BINARY)) {
            self::markTestSkipped('PHP_BINARY unavailable');
        }

        self::assertSame(PHP_VERSION_ID, PhpCli::versionId(PHP_BINARY));
    }

    public function testResolveReturnsUsableBinaryOnThisRuntime(): void
    {
        if (PHP_VERSION_ID < PhpCli::MIN_VERSION_ID) {
            self::markTestSkipped('Test host PHP is below 8.3');
        }

        $bin = PhpCli::resolve();
        self::assertNotSame('', $bin);
        self::assertGreaterThanOrEqual(PhpCli::MIN_VERSION_ID, PhpCli::versionId($bin));
    }

    public function testMissingAbsolutePathYieldsZeroVersion(): void
    {
        self::assertSame(0, PhpCli::versionId('/no/such/php-binary-hcms-test'));
    }
}
