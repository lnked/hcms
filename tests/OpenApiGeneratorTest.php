<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\Config;
use Cms\Core\Version;
use Cms\Database\Connection;
use Cms\Fields\FieldRepository;
use Cms\OpenApi\OpenApiGenerator;
use Cms\Resources\ResourceApiRepository;
use Cms\Resources\ResourceRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class OpenApiGeneratorTest extends TestCase
{
    public function testEmptySpecWithoutRepositories(): void
    {
        $spec = (new OpenApiGenerator(self::config()))->generate();

        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertSame(Version::current(), $spec['info']['version']);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
        $this->assertCount(2, $spec['servers']);
    }

    public function testCustomApiExposesConfiguredMethods(): void
    {
        $db = self::seed([
            'slug' => 'leads',
            'methods' => ['GET', 'POST', 'PATCH', 'DELETE'],
            'fields' => ['title'],
            'public' => ['create' => true],
        ]);
        $spec = (new OpenApiGenerator(
            self::config(),
            new ResourceRepository($db),
            new FieldRepository($db),
            null,
            new ResourceApiRepository($db),
        ))->generate();

        $collection = $spec['paths']['/posts/leads'];
        $item = $spec['paths']['/posts/leads/{id}'];

        $this->assertSame(['get', 'post'], array_keys($collection));
        $this->assertSame(['get', 'patch', 'delete'], array_keys($item));
        $this->assertSame(
            '#/components/schemas/PostsLeadsInput',
            $collection['post']['requestBody']['content']['application/json']['schema']['$ref'],
        );

        // The projection is the write mask, and `computed` is not writable at all.
        $input = $spec['components']['schemas']['PostsLeadsInput'];
        $this->assertSame(['title'], array_keys($input['properties']));

        // public.create overrides the resource, the other methods inherit its defaults.
        $this->assertSame([], $collection['post']['security']);
        $this->assertArrayNotHasKey('security', $item['patch']);
        $this->assertArrayNotHasKey('security', $item['delete']);
    }

    public function testReadOnlyCustomApiHasNoWriteOperations(): void
    {
        $db = self::seed(['slug' => 'card', 'methods' => ['GET']]);
        $spec = (new OpenApiGenerator(
            self::config(),
            new ResourceRepository($db),
            new FieldRepository($db),
            null,
            new ResourceApiRepository($db),
        ))->generate();

        $this->assertSame(['get'], array_keys($spec['paths']['/posts/card']));
        $this->assertSame(['get'], array_keys($spec['paths']['/posts/card/{id}']));
        $this->assertArrayNotHasKey('PostsCardInput', $spec['components']['schemas']);
    }

    private static function config(): Config
    {
        return new Config(
            appEnv: 'test',
            debug: true,
            appUrl: 'http://localhost',
            appSecret: 'x',
            dbHost: '127.0.0.1',
            dbPort: 3306,
            dbName: '',
            dbUser: '',
            dbPassword: '',
            dbCharset: 'utf8mb4',
            githubRepo: 'lnked/hcms',
        );
    }

    /**
     * @param array{slug: string, methods: list<string>, fields?: list<string>, public?: array<string, bool>} $api
     */
    private static function seed(array $api): Connection
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE cms_content_types (id INTEGER PRIMARY KEY, slug TEXT, label TEXT, is_system INTEGER)');
        $pdo->exec('CREATE TABLE cms_resources (
            id INTEGER PRIMARY KEY, content_type_id INTEGER, slug TEXT, endpoint TEXT, status TEXT, settings_json TEXT
        )');
        $pdo->exec('CREATE TABLE cms_fields (
            id INTEGER PRIMARY KEY, content_type_id INTEGER, name TEXT, type TEXT, sort_order INTEGER, spec_json TEXT
        )');
        $pdo->exec('CREATE TABLE cms_resource_apis (
            id INTEGER PRIMARY KEY, resource_id INTEGER, slug TEXT, label TEXT, enabled INTEGER,
            methods_json TEXT, fields_json TEXT, joins_json TEXT, settings_json TEXT, created_at TEXT, updated_at TEXT
        )');

        $pdo->exec("INSERT INTO cms_content_types (id, slug, label, is_system) VALUES (1, 'posts', 'Posts', 0)");
        $pdo->exec("INSERT INTO cms_resources (id, content_type_id, slug, endpoint, status, settings_json)
            VALUES (1, 1, 'posts', '/api/posts', 'published', '{\"public\":{\"read\":true}}')");
        $pdo->exec("INSERT INTO cms_fields (id, content_type_id, name, type, sort_order, spec_json) VALUES
            (1, 1, 'title', 'string', 0, '{\"required\":true}'),
            (2, 1, 'computed', 'string', 1, '{\"writable\":false}')");

        $fields = $api['fields'] ?? null;
        $stmt = $pdo->prepare('INSERT INTO cms_resource_apis
            (id, resource_id, slug, label, enabled, methods_json, fields_json, joins_json, settings_json, created_at, updated_at)
            VALUES (1, 1, :slug, :label, 1, :methods, :fields, :joins, :settings, :now, :now)');
        $stmt->execute([
            'slug' => $api['slug'],
            'label' => ucfirst($api['slug']),
            'methods' => json_encode($api['methods']),
            'fields' => $fields === null ? null : json_encode($fields),
            'joins' => '[]',
            'settings' => json_encode(['public' => $api['public'] ?? []]),
            'now' => '2026-01-01 00:00:00',
        ]);

        return new Connection($pdo);
    }
}
