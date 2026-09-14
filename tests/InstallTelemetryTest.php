<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\Version;
use Cms\Install\InstallTelemetry;
use PHPUnit\Framework\TestCase;

final class InstallTelemetryTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $envRestore = [];

    protected function tearDown(): void
    {
        foreach ($this->envRestore as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        $this->envRestore = [];
    }

    private function setEnv(string $key, ?string $value): void
    {
        if (!\array_key_exists($key, $this->envRestore)) {
            $current = getenv($key);
            $this->envRestore[$key] = $current === false ? false : (string) $current;
        }
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public function testEnabledByDefault(): void
    {
        $this->setEnv('HCMS_TELEMETRY', null);
        $this->setEnv('HCMS_NO_TELEMETRY', null);
        $this->setEnv('HCMS_TELEMETRY_URL', null);

        $this->assertTrue(InstallTelemetry::enabled([]));
        $this->assertSame(InstallTelemetry::DEFAULT_URL, InstallTelemetry::endpoint());
    }

    public function testOptOutViaPayload(): void
    {
        $this->setEnv('HCMS_TELEMETRY', null);
        $this->setEnv('HCMS_NO_TELEMETRY', null);
        $this->setEnv('HCMS_TELEMETRY_URL', null);

        $this->assertFalse(InstallTelemetry::enabled(['telemetry' => false]));
        $this->assertTrue(InstallTelemetry::enabled(['telemetry' => true]));
    }

    public function testOptOutViaEnv(): void
    {
        $this->setEnv('HCMS_TELEMETRY_URL', null);
        $this->setEnv('HCMS_NO_TELEMETRY', null);
        $this->setEnv('HCMS_TELEMETRY', '0');
        $this->assertFalse(InstallTelemetry::enabled([]));

        $this->setEnv('HCMS_TELEMETRY', null);
        $this->setEnv('HCMS_NO_TELEMETRY', '1');
        $this->assertFalse(InstallTelemetry::enabled(['telemetry' => true]));
    }

    public function testUrlOverrideOffDisables(): void
    {
        $this->setEnv('HCMS_TELEMETRY', null);
        $this->setEnv('HCMS_NO_TELEMETRY', null);
        $this->setEnv('HCMS_TELEMETRY_URL', 'off');

        $this->assertSame('', InstallTelemetry::endpoint());
        $this->assertFalse(InstallTelemetry::enabled([]));
    }

    public function testBuildBodySanitizes(): void
    {
        $this->setEnv('HCMS_TELEMETRY_URL', null);
        $body = InstallTelemetry::buildBody([
            'telemetrySource' => 'wizard',
        ]);

        $this->assertSame(Version::current(), $body['version']);
        $this->assertSame(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $body['php']);
        $this->assertSame('wizard', $body['source']);
        $this->assertContains($body['os'], ['Windows', 'BSD', 'Darwin', 'Solaris', 'Linux', 'Unknown']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $body['date']);

        $fallback = InstallTelemetry::buildBody(['telemetrySource' => 'evil']);
        $this->assertSame('api', $fallback['source']);
    }
}
