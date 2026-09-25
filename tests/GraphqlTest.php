<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Api\PublicApiAuthorizer;
use Cms\Api\QueryEngine;
use Cms\Auth\TokenGrantRepository;
use Cms\Core\Settings;
use Cms\Database\Connection;
use Cms\Fields\FieldRepository;
use Cms\GraphQL\GraphQLSchemaFactory;
use Cms\GraphQL\GraphqlSettings;
use Cms\Http\Controllers\GraphqlController;
use Cms\Http\Request;
use Cms\Resources\ResourceRepository;
use GraphQL\GraphQL;
use PDO;
use PHPUnit\Framework\TestCase;

final class GraphqlTest extends TestCase
{
    public function testTypeNames(): void
    {
        $this->assertSame('Articles', \Cms\GraphQL\TypeNames::object('articles'));
        $this->assertSame('article', \Cms\GraphQL\TypeNames::itemField('articles'));
        $this->assertSame('createArticles', \Cms\GraphQL\TypeNames::createField('articles'));
        $this->assertSame('author', \Cms\GraphQL\TypeNames::relationNest('author_id'));
        $this->assertSame('tags_entry', \Cms\GraphQL\TypeNames::relationNest('tags'));
    }

    public function testDisabledGraphqlReturns404(): void
    {
        $db = self::settingsDb(false);
        $controller = self::controller($db);
        $response = $controller->execute(
            new Request('POST', '/api/graphql', [], [], null, '{"query":"{ ping }"}', '127.0.0.1', 'test'),
            null,
        );
        $this->assertSame(404, $response->status);
    }

    public function testPlaygroundDisabledByDefaultWhenApiEnabled(): void
    {
        $db = self::settingsDb(true, false);
        $controller = self::controller($db);
        $response = $controller->playground(
            new Request('GET', '/api/graphql', [], [], null, '', '127.0.0.1', 'test'),
        );
        $this->assertSame(404, $response->status);
    }

    public function testPlaygroundEnabledServesHtml(): void
    {
        $db = self::settingsDb(true, true);
        $controller = self::controller($db);
        $response = $controller->playground(
            new Request('GET', '/api/graphql', [], [], null, '', '127.0.0.1', 'test'),
        );
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('graphiql', strtolower($response->body));
    }

    public function testLegacyGraphqlEnabledKeyStillWorks(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE cms_settings (`key` TEXT PRIMARY KEY, value_json TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE cms_content_types (id INTEGER PRIMARY KEY, slug TEXT, label TEXT, is_system INTEGER)');
        $pdo->exec('CREATE TABLE cms_resources (
            id INTEGER PRIMARY KEY, content_type_id INTEGER, slug TEXT, endpoint TEXT, status TEXT, settings_json TEXT
        )');
        $pdo->exec('CREATE TABLE cms_fields (
            id INTEGER PRIMARY KEY, content_type_id INTEGER, name TEXT, type TEXT, sort_order INTEGER, spec_json TEXT
        )');
        $pdo->exec('CREATE TABLE cms_token_grants (
            id INTEGER PRIMARY KEY, token_id INTEGER, resource_id INTEGER,
            can_read INTEGER, can_create INTEGER, can_update INTEGER, can_delete INTEGER
        )');
        $db = new Connection($pdo);
        (new Settings($db))->set(GraphqlSettings::LEGACY_ENABLED_KEY, true);
        $this->assertTrue((new GraphqlSettings(new Settings($db)))->enabled());
    }

    public function testSchemaExposesResourceQueryFields(): void
    {
        $db = self::seedResourceDb();
        $factory = self::schemaFactory($db);
        $result = GraphQL::executeQuery(
            $factory->schema(),
            '{ __type(name: "Query") { fields { name } } }',
        )->toArray();

        $names = array_map(
            static fn (array $f): string => $f['name'],
            $result['data']['__type']['fields'] ?? [],
        );
        $this->assertContains('posts', $names);
        $this->assertContains('post', $names);
    }

    public function testSchemaExposesRelationNestField(): void
    {
        $db = self::seedResourceDb(withRelation: true);
        $factory = self::schemaFactory($db);
        $result = GraphQL::executeQuery(
            $factory->schema(),
            '{ __type(name: "Posts") { fields { name } } }',
        )->toArray();

        $names = array_map(
            static fn (array $f): string => $f['name'],
            $result['data']['__type']['fields'] ?? [],
        );
        $this->assertContains('author_id', $names);
        $this->assertContains('author', $names);
        $this->assertContains('title', $names);
    }

    public function testPublicReadListViaGraphql(): void
    {
        $db = self::seedResourceDb(withTable: true);
        (new GraphqlSettings(new Settings($db)))->setEnabled(true);
        $controller = self::controller($db);
        $body = json_encode([
            'query' => 'query { posts(limit: 10) { data { id title } meta { total } } }',
        ], JSON_THROW_ON_ERROR);
        $response = $controller->execute(
            new Request('POST', '/api/graphql', [], [], null, $body, '127.0.0.1', 'test'),
            null,
        );
        $this->assertSame(200, $response->status);
        $payload = json_decode($response->body, true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('data', $payload);
        $this->assertSame(1, $payload['data']['posts']['meta']['total'] ?? null);
        $this->assertSame('Hello', $payload['data']['posts']['data'][0]['title'] ?? null);
    }

    public function testUnauthorizedWhenPublicReadDisabled(): void
    {
        $db = self::seedResourceDb(withTable: true, publicRead: false);
        (new GraphqlSettings(new Settings($db)))->setEnabled(true);
        $controller = self::controller($db);
        $body = json_encode([
            'query' => 'query { posts { data { id } } }',
        ], JSON_THROW_ON_ERROR);
        $response = $controller->execute(
            new Request('POST', '/api/graphql', [], [], null, $body, '127.0.0.1', 'test'),
            null,
        );
        $payload = json_decode($response->body, true);
        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload['errors'] ?? []);
        $this->assertSame(401, $response->status);
    }

    private static function controller(Connection $db): GraphqlController
    {
        return new GraphqlController(self::schemaFactory($db), new Settings($db));
    }

    private static function schemaFactory(Connection $db): GraphQLSchemaFactory
    {
        $resources = new ResourceRepository($db);
        $fields = new FieldRepository($db);
        $grants = new TokenGrantRepository($db);
        $authorizer = new PublicApiAuthorizer($resources, $grants);
        $query = new QueryEngine($db, $resources, $fields);

        return new GraphQLSchemaFactory($resources, $fields, $query, $authorizer);
    }

    private static function settingsDb(bool $enabled, bool $playground = false): Connection
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE cms_settings (`key` TEXT PRIMARY KEY, value_json TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE cms_content_types (id INTEGER PRIMARY KEY, slug TEXT, label TEXT, is_system INTEGER)');
        $pdo->exec('CREATE TABLE cms_resources (
            id INTEGER PRIMARY KEY, content_type_id INTEGER, slug TEXT, endpoint TEXT, status TEXT, settings_json TEXT
        )');
        $pdo->exec('CREATE TABLE cms_fields (
            id INTEGER PRIMARY KEY, content_type_id INTEGER, name TEXT, type TEXT, sort_order INTEGER, spec_json TEXT
        )');
        $pdo->exec('CREATE TABLE cms_token_grants (
            id INTEGER PRIMARY KEY, token_id INTEGER, resource_id INTEGER,
            can_read INTEGER, can_create INTEGER, can_update INTEGER, can_delete INTEGER
        )');
        $db = new Connection($pdo);
        $gql = new GraphqlSettings(new Settings($db));
        $gql->setEnabled($enabled);
        $gql->setPlayground($playground);

        return $db;
    }

    private static function seedResourceDb(
        bool $withRelation = false,
        bool $withTable = false,
        bool $publicRead = true,
    ): Connection {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE cms_settings (`key` TEXT PRIMARY KEY, value_json TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE cms_content_types (id INTEGER PRIMARY KEY, slug TEXT, label TEXT, is_system INTEGER)');
        $pdo->exec('CREATE TABLE cms_resources (
            id INTEGER PRIMARY KEY, content_type_id INTEGER, slug TEXT, endpoint TEXT, status TEXT, settings_json TEXT
        )');
        $pdo->exec('CREATE TABLE cms_fields (
            id INTEGER PRIMARY KEY, content_type_id INTEGER, name TEXT, type TEXT, sort_order INTEGER, spec_json TEXT
        )');
        $pdo->exec('CREATE TABLE cms_token_grants (
            id INTEGER PRIMARY KEY, token_id INTEGER, resource_id INTEGER,
            can_read INTEGER, can_create INTEGER, can_update INTEGER, can_delete INTEGER
        )');

        $pdo->exec("INSERT INTO cms_content_types (id, slug, label, is_system) VALUES (1, 'posts', 'Posts', 0)");
        if ($withRelation) {
            $pdo->exec("INSERT INTO cms_content_types (id, slug, label, is_system) VALUES (2, 'authors', 'Authors', 0)");
            $pdo->exec("INSERT INTO cms_resources (id, content_type_id, slug, endpoint, status, settings_json)
                VALUES (2, 2, 'authors', '/api/authors', 'published', '{\"apiEnabled\":true,\"public\":{\"read\":true}}')");
            $pdo->exec("INSERT INTO cms_fields (id, content_type_id, name, type, sort_order, spec_json) VALUES
                (10, 2, 'name', 'string', 0, '{\"readable\":true}')");
        }

        $public = $publicRead ? 'true' : 'false';
        $pdo->exec("INSERT INTO cms_resources (id, content_type_id, slug, endpoint, status, settings_json)
            VALUES (1, 1, 'posts', '/api/posts', 'published',
            '{\"apiEnabled\":true,\"public\":{\"read\":{$public}},\"pagination\":true,\"filtering\":true,\"search\":true,\"sorting\":true}')");
        $pdo->exec("INSERT INTO cms_fields (id, content_type_id, name, type, sort_order, spec_json) VALUES
            (1, 1, 'title', 'string', 0, '{\"readable\":true,\"writable\":true,\"required\":true}')");
        if ($withRelation) {
            $pdo->exec("INSERT INTO cms_fields (id, content_type_id, name, type, sort_order, spec_json) VALUES
                (2, 1, 'author_id', 'relation', 1,
                '{\"readable\":true,\"writable\":true,\"config\":{\"cardinality\":\"manyToOne\",\"relatedSlug\":\"authors\"}}')");
        }

        if ($withTable) {
            $pdo->exec('CREATE TABLE res_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                created_at TEXT,
                updated_at TEXT,
                deleted_at TEXT
            )');
            $pdo->exec("INSERT INTO res_posts (id, title, created_at, updated_at, deleted_at)
                VALUES (1, 'Hello', '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL)");
        }

        return new Connection($pdo);
    }
}
