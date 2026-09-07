<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Content\EntryRevisionService;
use Cms\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class EntryRevisionServiceTest extends TestCase
{
    private EntryRevisionService $service;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE cms_entry_revisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            resource_id INTEGER NOT NULL,
            entry_id INTEGER NOT NULL,
            data_json TEXT NOT NULL,
            diff_json TEXT NULL,
            actor_user_id INTEGER NULL,
            created_at TEXT NOT NULL
        )');
        $this->service = new EntryRevisionService(new Connection($pdo));
    }

    public function testSnapshotAndList(): void
    {
        $id = $this->service->snapshot(1, 10, ['id' => 10, 'title' => 'A'], ['id' => 10, 'title' => 'B'], 5);
        self::assertGreaterThan(0, $id);
        $list = $this->service->list(1, 10);
        self::assertCount(1, $list);
        self::assertSame('A', $list[0]['data']['title']);
        self::assertSame(['title' => ['from' => 'A', 'to' => 'B']], $list[0]['diff']);
    }

    public function testRestoreData(): void
    {
        $id = $this->service->snapshot(1, 10, ['id' => 10, 'title' => 'old']);
        $data = $this->service->dataForRestore(1, 10, $id);
        self::assertSame('old', $data['title']);
    }

    public function testPruneKeepsLastN(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->service->snapshot(1, 10, ['n' => $i]);
        }
        $list = $this->service->list(1, 10, 100);
        self::assertCount(50, $list);
    }
}
