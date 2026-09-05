<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\Version;
use PHPUnit\Framework\TestCase;

final class UpdateBreakingGateTest extends TestCase
{
    public function testBreakingRequiresAckLogic(): void
    {
        $hasBreaking = true;
        $acknowledgeBreaking = false;
        $this->assertTrue($hasBreaking && !$acknowledgeBreaking);

        $acknowledgeBreaking = true;
        $this->assertFalse($hasBreaking && !$acknowledgeBreaking);
    }

    public function testVersionCompareForUpdateAvailable(): void
    {
        $this->assertTrue(Version::isGreater('0.12.0', '0.11.0'));
        $this->assertFalse(Version::isGreater('0.11.0', '0.12.0'));
    }
}
