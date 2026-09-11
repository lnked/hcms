<?php

declare(strict_types=1);

namespace Cms\Tests\Integration;

use Cms\Api\QueryEngine;
use Cms\Database\Connection;
use Cms\Database\MigrationService;
use Cms\Database\SchemaDiff;
use Cms\Fields\FieldRepository;
use Cms\Fields\FieldTypeRegistry;
use Cms\Fields\SqlTypeMapper;
use Cms\Resources\ResourceRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * MySQL integration tests for QueryEngine + MigrationService.
 * Skipped unless CMS_TEST_DSN is set, e.g.:
 *   CMS_TEST_DSN=mysql:host=127.0.0.1;port=3306;dbname=hcms_test CMS_TEST_DB_USER=root CMS_TEST_DB_PASS=
 */
final class QueryEngineMigrationTest extends TestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('CMS_TEST_DSN') ?: '';
        if ($dsn === '') {
            return;
        }
        $user = getenv('CMS_TEST_DB_USER') ?: 'root';
        $pass = getenv('CMS_TEST_DB_PASS') ?: '';
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            self::markTestSkipped('Set CMS_TEST_DSN to run MySQL integration tests');
        }
    }

    public function testConnectionAlive(): void
    {
        $stmt = self::$pdo?->query('SELECT 1 AS ok');
        self::assertNotNull($stmt);
        $row = $stmt->fetch();
        self::assertSame(1, (int) ($row['ok'] ?? 0));
    }

    public function testQueryEngineConstructsAgainstLiveDb(): void
    {
        $db = $this->connection();
        $engine = new QueryEngine(
            $db,
            new ResourceRepository($db),
            new FieldRepository($db),
        );
        self::assertInstanceOf(QueryEngine::class, $engine);

        $migrations = new MigrationService(
            $db,
            new ResourceRepository($db),
            new FieldRepository($db),
            new SqlTypeMapper(new FieldTypeRegistry()),
            new SchemaDiff(),
        );
        self::assertInstanceOf(MigrationService::class, $migrations);
    }

    private function connection(): Connection
    {
        $dsn = (string) getenv('CMS_TEST_DSN');
        $parts = [];
        foreach (explode(';', substr($dsn, \strlen('mysql:'))) as $chunk) {
            if ($chunk === '' || !str_contains($chunk, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $chunk, 2);
            $parts[$k] = $v;
        }

        return Connection::connect([
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (int) ($parts['port'] ?? 3306),
            'database' => $parts['dbname'] ?? 'hcms_test',
            'username' => getenv('CMS_TEST_DB_USER') ?: 'root',
            'password' => getenv('CMS_TEST_DB_PASS') ?: '',
            'charset' => 'utf8mb4',
        ]);
    }
}
