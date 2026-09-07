<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\System\TreeVerifier;
use PHPUnit\Framework\TestCase;

final class TreeVerifierTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hcms-tree-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src/Http', 0775, true);
        file_put_contents($this->root . '/VERSION', "1.2.3\n");
        file_put_contents($this->root . '/src/autoload.php', '<?php');
        file_put_contents($this->root . '/src/bootstrap.php', '<?php');
        file_put_contents($this->root . '/src/Http/Kernel.php', '<?php');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function testCleanTreeWithoutVendorHasNoProblems(): void
    {
        $this->assertSame([], TreeVerifier::problems($this->root));
    }

    public function testDetectsEmptyAndMissingFiles(): void
    {
        file_put_contents($this->root . '/src/bootstrap.php', '');
        unlink($this->root . '/VERSION');

        $problems = TreeVerifier::problems($this->root);

        $this->assertContains('missing VERSION', $problems);
        $this->assertContains('empty src/bootstrap.php', $problems);
    }

    public function testDetectsVendorGeneratedAtTheWrongDepth(): void
    {
        // Exactly the 0.45.3 regression: one dirname() too many, so every
        // mapped class lands above the install root.
        $this->writeClassmap('$baseDir = dirname(dirname($vendorDir));');

        $problems = TreeVerifier::problems($this->root);

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('wrong depth', $problems[0]);
        $this->assertStringContainsString('Cms\Http\Kernel', $problems[0]);
    }

    public function testAcceptsVendorGeneratedAtTheRightDepth(): void
    {
        $this->writeClassmap('$baseDir = dirname($vendorDir);');

        $this->assertSame([], TreeVerifier::problems($this->root));
    }

    public function testDetectsPsr4PrefixPointingAtMissingDirectory(): void
    {
        $this->writeClassmap('$baseDir = dirname($vendorDir);');
        file_put_contents(
            $this->root . '/vendor/composer/autoload_psr4.php',
            "<?php\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\n"
            . "return array('Cms\\\\' => array(\$baseDir . '/nope'));\n",
        );

        $problems = TreeVerifier::problems($this->root);

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('/nope', implode(' ', $problems));
    }

    private function writeClassmap(string $baseDirLine): void
    {
        mkdir($this->root . '/vendor/composer', 0775, true);
        file_put_contents(
            $this->root . '/vendor/composer/autoload_classmap.php',
            "<?php\n\$vendorDir = dirname(__DIR__);\n" . $baseDirLine . "\n"
            . "return array('Cms\\\\Http\\\\Kernel' => \$baseDir . '/src/Http/Kernel.php');\n",
        );
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeDir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
