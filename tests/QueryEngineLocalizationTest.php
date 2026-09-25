<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Api\QueryEngine;
use Cms\Core\Exception\ValidationFailedException;
use Cms\Database\Connection;
use Cms\Fields\FieldRepository;
use Cms\Resources\ResourceRepository;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * SQLite unit tests for QueryEngine content localization (listTranslations / createTranslation guards).
 * Full createTranslation success path needs MySQL (SHOW COLUMNS in ensureActorColumns).
 */
final class QueryEngineLocalizationTest extends TestCase
{
    public function testListTranslationsReturnsSiblingsInGroup(): void
    {
        $engine = $this->engine($this->seedLocalizedDb());

        $list = $engine->listTranslations('posts', 1);

        self::assertSame(
            [
                ['id' => 1, 'locale' => 'en'],
                ['id' => 2, 'locale' => 'ru'],
            ],
            $list,
        );
    }

    public function testListTranslationsWithoutGroupReturnsSelf(): void
    {
        $db = $this->seedLocalizedDb(withGroup: false);
        $engine = $this->engine($db);

        $list = $engine->listTranslations('posts', 1);

        self::assertSame([['id' => 1, 'locale' => 'en']], $list);
    }

    public function testListTranslationsRequiresLocalizationEnabled(): void
    {
        $db = $this->seedLocalizedDb(localizationEnabled: false);
        $engine = $this->engine($db);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Localization is not enabled');
        $engine->listTranslations('posts', 1);
    }

    public function testCreateTranslationRejectsInvalidLocale(): void
    {
        $engine = $this->engine($this->seedLocalizedDb());

        $this->expectException(ValidationFailedException::class);
        $engine->createTranslation('posts', 1, 'EN_US');
    }

    public function testCreateTranslationRejectsDuplicateLocale(): void
    {
        $engine = $this->engine($this->seedLocalizedDb());

        try {
            $engine->createTranslation('posts', 1, 'ru');
            self::fail('Expected ValidationFailedException');
        } catch (ValidationFailedException $e) {
            self::assertSame(['locale' => ['Translation for this locale already exists']], $e->fields());
        }
    }

    public function testCreateTranslationRequiresLocalizationEnabled(): void
    {
        $db = $this->seedLocalizedDb(localizationEnabled: false);
        $engine = $this->engine($db);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Localization is not enabled');
        $engine->createTranslation('posts', 1, 'de');
    }

    private function engine(Connection $db): QueryEngine
    {
        return new QueryEngine($db, new ResourceRepository($db), new FieldRepository($db));
    }

    private function seedLocalizedDb(
        bool $localizationEnabled = true,
        bool $withGroup = true,
    ): Connection {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE cms_settings (`key` TEXT PRIMARY KEY, value_json TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE cms_locales (
            id INTEGER PRIMARY KEY, code TEXT, label TEXT, enabled INTEGER, is_default INTEGER, sort_order INTEGER
        )');
        $pdo->exec('CREATE TABLE cms_content_types (id INTEGER PRIMARY KEY, slug TEXT, label TEXT, is_system INTEGER)');
        $pdo->exec('CREATE TABLE cms_resources (
            id INTEGER PRIMARY KEY, content_type_id INTEGER, slug TEXT, endpoint TEXT, status TEXT, settings_json TEXT
        )');
        $pdo->exec('CREATE TABLE cms_fields (
            id INTEGER PRIMARY KEY, content_type_id INTEGER, name TEXT, type TEXT, sort_order INTEGER, spec_json TEXT
        )');
        $pdo->exec('CREATE TABLE res_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            locale TEXT,
            translation_group_id TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT,
            created_by INTEGER,
            updated_by INTEGER
        )');

        $pdo->exec("INSERT INTO cms_locales (id, code, label, enabled, is_default, sort_order)
            VALUES (1, 'en', 'English', 1, 1, 0), (2, 'ru', 'Russian', 1, 0, 1)");
        $pdo->exec("INSERT INTO cms_content_types (id, slug, label, is_system) VALUES (1, 'posts', 'Posts', 0)");

        $loc = $localizationEnabled ? 'true' : 'false';
        $pdo->exec("INSERT INTO cms_resources (id, content_type_id, slug, endpoint, status, settings_json)
            VALUES (1, 1, 'posts', '/api/posts', 'published',
            '{\"apiEnabled\":true,\"public\":{\"read\":true},\"localization\":{\"enabled\":{$loc}}}')");
        $pdo->exec("INSERT INTO cms_fields (id, content_type_id, name, type, sort_order, spec_json) VALUES
            (1, 1, 'title', 'string', 0, '{\"readable\":true,\"writable\":true,\"required\":true}')");

        $group = $withGroup ? '11111111-1111-4111-8111-111111111111' : '';
        $pdo->exec("INSERT INTO res_posts (id, title, locale, translation_group_id, created_at, updated_at, deleted_at)
            VALUES (1, 'Hello', 'en', " . ($group === '' ? 'NULL' : "'{$group}'") . ", '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL)");
        if ($withGroup) {
            $pdo->exec("INSERT INTO res_posts (id, title, locale, translation_group_id, created_at, updated_at, deleted_at)
                VALUES (2, 'Привет', 'ru', '{$group}', '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL)");
        }

        return new Connection($pdo);
    }
}
