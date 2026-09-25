<?php

declare(strict_types=1);

namespace Cms\GraphQL;

use Cms\Api\PublicApiAuthorizer;
use Cms\Api\QueryEngine;
use Cms\Auth\AuthContext;
use Cms\Core\Exception\NotFoundException;
use Cms\Core\MetadataCache;
use Cms\Fields\FieldRepository;
use Cms\Resources\ResourceRepository;
use Cms\Resources\ResourceService;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use RuntimeException;
use Throwable;

/**
 * Builds an executable GraphQL schema from published + apiEnabled resources.
 */
final class SchemaFactory
{
    public const MAX_RELATION_DEPTH = 3;

    private ?Schema $built = null;

    private JsonType $jsonType;

    /** @var array<string, ObjectType> */
    private array $objectTypes = [];

    /** @var array<string, array{publicKey: string, slug: string, fields: list<array<string, mixed>>}> */
    private array $resourceMeta = [];

    public function __construct(
        private readonly ResourceRepository $resources,
        private readonly FieldRepository $fields,
        private readonly QueryEngine $query,
        private readonly PublicApiAuthorizer $authorizer,
        private readonly ?MetadataCache $metadata = null,
    ) {
        $this->jsonType = new JsonType();
    }

    public function schema(): Schema
    {
        if ($this->built !== null) {
            return $this->built;
        }

        // Stamp so MetadataCache::invalidate() drops in-process rebuilds after schema edits.
        if ($this->metadata !== null && $this->metadata->getGraphqlStamp() === null) {
            $this->metadata->setGraphqlStamp();
        }

        $this->objectTypes = [];
        $this->resourceMeta = [];
        $this->collectResources();

        $queryFields = [];
        $mutationFields = [];
        $metaType = $this->metaType();
        $filterInput = $this->filterInputType();

        foreach ($this->resourceMeta as $publicKey => $meta) {
            $objectType = $this->objectTypeFor($publicKey);
            $connectionType = new ObjectType([
                'name' => TypeNames::connection($publicKey),
                'fields' => [
                    'data' => [
                        'type' => Type::nonNull(Type::listOf(Type::nonNull($objectType))),
                        'resolve' => static fn (array $root): array => $root['data'] ?? [],
                    ],
                    'meta' => [
                        'type' => Type::nonNull($metaType),
                        'resolve' => static fn (array $root): array => $root['meta'] ?? [],
                    ],
                ],
            ]);

            $listName = TypeNames::listField($publicKey);
            $itemName = TypeNames::itemField($publicKey);

            $queryFields[$listName] = [
                'type' => Type::nonNull($connectionType),
                'args' => [
                    'page' => ['type' => Type::int(), 'defaultValue' => 1],
                    'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                    'sort' => ['type' => Type::string()],
                    'search' => ['type' => Type::string()],
                    'filter' => ['type' => Type::listOf(Type::nonNull($filterInput))],
                ],
                'resolve' => function ($root, array $args, array $context) use ($publicKey): array {
                    unset($root);
                    $this->authorize($publicKey, 'read', $context);

                    return $this->query->list($publicKey, $this->toListQuery($args), ['public' => true]);
                },
            ];

            $queryFields[$itemName] = [
                'type' => $objectType,
                'args' => [
                    'id' => Type::nonNull(Type::int()),
                ],
                'resolve' => function ($root, array $args, array $context) use ($publicKey): ?array {
                    unset($root);
                    $this->authorize($publicKey, 'read', $context);
                    try {
                        return $this->query->find($publicKey, (int) $args['id'], ['public' => true]);
                    } catch (NotFoundException) {
                        return null;
                    }
                },
            ];

            $inputType = $this->inputTypeFor($publicKey);

            $mutationFields[TypeNames::createField($publicKey)] = [
                'type' => Type::nonNull($objectType),
                'args' => [
                    'input' => Type::nonNull($inputType),
                ],
                'resolve' => function ($root, array $args, array $context) use ($publicKey): array {
                    unset($root);
                    $this->authorize($publicKey, 'create', $context);
                    $input = \is_array($args['input'] ?? null) ? $args['input'] : [];

                    return $this->query->create($publicKey, $input, ['public' => true]);
                },
            ];

            $mutationFields[TypeNames::updateField($publicKey)] = [
                'type' => Type::nonNull($objectType),
                'args' => [
                    'id' => Type::nonNull(Type::int()),
                    'input' => Type::nonNull($inputType),
                ],
                'resolve' => function ($root, array $args, array $context) use ($publicKey): array {
                    unset($root);
                    $this->authorize($publicKey, 'update', $context);
                    $input = \is_array($args['input'] ?? null) ? $args['input'] : [];

                    return $this->query->patch($publicKey, (int) $args['id'], $input, ['public' => true]);
                },
            ];

            $mutationFields[TypeNames::deleteField($publicKey)] = [
                'type' => Type::nonNull(Type::boolean()),
                'args' => [
                    'id' => Type::nonNull(Type::int()),
                ],
                'resolve' => function ($root, array $args, array $context) use ($publicKey): bool {
                    unset($root);
                    $this->authorize($publicKey, 'delete', $context);
                    $this->query->delete($publicKey, (int) $args['id'], ['public' => true]);

                    return true;
                },
            ];
        }

        if ($queryFields === []) {
            $queryFields['ping'] = [
                'type' => Type::nonNull(Type::string()),
                'resolve' => static fn (): string => 'ok',
            ];
        }

        $queryType = new ObjectType([
            'name' => 'Query',
            'fields' => $queryFields,
        ]);

        $config = ['query' => $queryType];
        if ($mutationFields !== []) {
            $config['mutation'] = new ObjectType([
                'name' => 'Mutation',
                'fields' => $mutationFields,
            ]);
        }

        $this->built = new Schema($config);

        return $this->built;
    }

    public function invalidate(): void
    {
        $this->built = null;
        $this->objectTypes = [];
        $this->resourceMeta = [];
        $this->metadata?->invalidate();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function authorize(string $publicKey, string $action, array $context): void
    {
        /** @var AuthContext|null $auth */
        $auth = $context['auth'] ?? null;
        try {
            $this->authorizer->authorizeActionName($publicKey, $action, $auth);
        } catch (RuntimeException $e) {
            throw new Error($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, string>
     */
    private function toListQuery(array $args): array
    {
        $query = [];
        if (isset($args['page'])) {
            $query['page'] = (string) (int) $args['page'];
        }
        if (isset($args['limit'])) {
            $query['limit'] = (string) (int) $args['limit'];
        }
        if (isset($args['sort']) && \is_string($args['sort']) && $args['sort'] !== '') {
            $query['sort'] = $args['sort'];
        }
        if (isset($args['search']) && \is_string($args['search']) && $args['search'] !== '') {
            $query['search'] = $args['search'];
        }
        $filters = $args['filter'] ?? null;
        if (\is_array($filters)) {
            foreach ($filters as $filter) {
                if (!\is_array($filter)) {
                    continue;
                }
                $field = (string) ($filter['field'] ?? '');
                $op = (string) ($filter['op'] ?? 'eq');
                $value = (string) ($filter['value'] ?? '');
                if ($field === '') {
                    continue;
                }
                if ($op === 'eq') {
                    $query['filter[' . $field . ']'] = $value;
                } else {
                    $query['filter[' . $field . '][' . $op . ']'] = $value;
                }
            }
        }

        return $query;
    }

    private function collectResources(): void
    {
        foreach ($this->resources->all() as $resource) {
            if (($resource['status'] ?? '') !== 'published') {
                continue;
            }
            $settings = \is_string($resource['settings_json'])
                ? json_decode((string) $resource['settings_json'], true)
                : $resource['settings_json'];
            if (\is_array($settings) && ($settings['apiEnabled'] ?? true) === false) {
                continue;
            }
            $slug = (string) $resource['slug'];
            $publicKey = ResourceService::publicKeyFromEndpoint((string) ($resource['endpoint'] ?? ''))
                ?? $slug;
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $publicKey)) {
                continue;
            }
            $fieldRows = $this->fields->forContentType((int) $resource['content_type_id']);
            $this->resourceMeta[$publicKey] = [
                'publicKey' => $publicKey,
                'slug' => $slug,
                'fields' => $fieldRows,
            ];
        }
    }

    private function objectTypeFor(string $publicKey): ObjectType
    {
        if (isset($this->objectTypes[$publicKey])) {
            return $this->objectTypes[$publicKey];
        }

        $meta = $this->resourceMeta[$publicKey];
        $self = $this;
        $type = new ObjectType([
            'name' => TypeNames::object($publicKey),
            'fields' => function () use ($self, $publicKey, $meta): array {
                return $self->buildObjectFields($publicKey, $meta['fields']);
            },
        ]);
        $this->objectTypes[$publicKey] = $type;

        return $type;
    }

    /**
     * @param list<array<string, mixed>> $fieldRows
     * @return array<string, array<string, mixed>>
     */
    private function buildObjectFields(string $publicKey, array $fieldRows): array
    {
        unset($publicKey);
        $fields = [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'resolve' => static fn (array $row): int => (int) ($row['id'] ?? 0),
            ],
            'createdAt' => [
                'type' => Type::string(),
                'resolve' => static fn (array $row): ?string => isset($row['createdAt'])
                    ? (string) $row['createdAt']
                    : (isset($row['created_at']) ? (string) $row['created_at'] : null),
            ],
            'updatedAt' => [
                'type' => Type::string(),
                'resolve' => static fn (array $row): ?string => isset($row['updatedAt'])
                    ? (string) $row['updatedAt']
                    : (isset($row['updated_at']) ? (string) $row['updated_at'] : null),
            ],
        ];

        foreach ($fieldRows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                continue;
            }
            $type = (string) ($row['type'] ?? 'string');
            $spec = $this->fieldSpec($row);
            $config = \is_array($spec['config'] ?? null) ? $spec['config'] : [];
            $readable = (bool) ($spec['readable'] ?? true);
            $hidden = (bool) ($spec['hidden'] ?? false);
            if (!$readable || $hidden) {
                continue;
            }

            if ($type === 'relation') {
                $cardinality = (string) ($config['cardinality'] ?? 'manyToOne');
                if ($cardinality === 'oneToMany') {
                    continue;
                }
                if ($cardinality === 'manyToMany') {
                    $fields[$name] = [
                        'type' => Type::listOf(Type::nonNull(Type::int())),
                        'resolve' => static function (array $entry) use ($name): array {
                            $value = $entry[$name] ?? [];

                            return \is_array($value) ? array_map('intval', $value) : [];
                        },
                    ];
                    $relatedSlug = (string) ($config['relatedSlug'] ?? '');
                    $relatedKey = $this->publicKeyForSlug($relatedSlug);
                    if ($relatedKey !== null) {
                        $nestName = TypeNames::relationNest($name);
                        if (!isset($fields[$nestName])) {
                            $fields[$nestName] = $this->manyRelationResolver($relatedKey, $name);
                        }
                    }
                    continue;
                }

                $fields[$name] = [
                    'type' => Type::int(),
                    'resolve' => static function (array $entry) use ($name): ?int {
                        $value = $entry[$name] ?? null;

                        return $value === null || $value === '' ? null : (int) $value;
                    },
                ];
                $relatedSlug = (string) ($config['relatedSlug'] ?? '');
                $relatedKey = $this->publicKeyForSlug($relatedSlug);
                if ($relatedKey !== null) {
                    $nestName = TypeNames::relationNest($name);
                    if (!isset($fields[$nestName]) && $nestName !== $name) {
                        $fields[$nestName] = $this->oneRelationResolver($relatedKey, $name);
                    }
                }
                continue;
            }

            $gqlType = $this->outputType($type);
            $fields[$name] = [
                'type' => $gqlType,
                'resolve' => static fn (array $entry): mixed => $entry[$name] ?? null,
            ];
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function oneRelationResolver(string $relatedKey, string $fkField): array
    {
        $relatedType = $this->objectTypeFor($relatedKey);

        return [
            'type' => $relatedType,
            'resolve' => function (array $entry, array $args, array $context) use ($relatedKey, $fkField): ?array {
                unset($args);
                $depth = (int) ($context['relationDepth'] ?? 0);
                if ($depth >= self::MAX_RELATION_DEPTH) {
                    return null;
                }
                $fk = $entry[$fkField] ?? null;
                if ($fk === null || $fk === '') {
                    return null;
                }
                $this->authorize($relatedKey, 'read', $context);
                $context['relationDepth'] = $depth + 1;
                try {
                    return $this->query->find($relatedKey, (int) $fk, ['public' => true]);
                } catch (NotFoundException) {
                    return null;
                } catch (Throwable) {
                    return null;
                } finally {
                    $context['relationDepth'] = $depth;
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manyRelationResolver(string $relatedKey, string $fkField): array
    {
        $relatedType = $this->objectTypeFor($relatedKey);

        return [
            'type' => Type::listOf(Type::nonNull($relatedType)),
            'resolve' => function (array $entry, array $args, array $context) use ($relatedKey, $fkField): array {
                unset($args);
                $depth = (int) ($context['relationDepth'] ?? 0);
                if ($depth >= self::MAX_RELATION_DEPTH) {
                    return [];
                }
                $ids = $entry[$fkField] ?? [];
                if (!\is_array($ids) || $ids === []) {
                    return [];
                }
                $this->authorize($relatedKey, 'read', $context);
                $out = [];
                foreach ($ids as $id) {
                    try {
                        $out[] = $this->query->find($relatedKey, (int) $id, ['public' => true]);
                    } catch (NotFoundException) {
                        continue;
                    }
                }

                return $out;
            },
        ];
    }

    private function publicKeyForSlug(string $slug): ?string
    {
        if ($slug === '') {
            return null;
        }
        foreach ($this->resourceMeta as $publicKey => $meta) {
            if ($meta['slug'] === $slug || $publicKey === $slug) {
                return $publicKey;
            }
        }
        $resource = $this->resources->findBySlug($slug) ?? $this->resources->findByPublicKey($slug);
        if ($resource === null || ($resource['status'] ?? '') !== 'published') {
            return null;
        }
        $settings = \is_string($resource['settings_json'])
            ? json_decode((string) $resource['settings_json'], true)
            : $resource['settings_json'];
        if (\is_array($settings) && ($settings['apiEnabled'] ?? true) === false) {
            return null;
        }
        $key = ResourceService::publicKeyFromEndpoint((string) ($resource['endpoint'] ?? ''))
            ?? (string) $resource['slug'];
        if (!isset($this->resourceMeta[$key])) {
            $fieldRows = $this->fields->forContentType((int) $resource['content_type_id']);
            $this->resourceMeta[$key] = [
                'publicKey' => $key,
                'slug' => (string) $resource['slug'],
                'fields' => $fieldRows,
            ];
        }

        return $key;
    }

    private function inputTypeFor(string $publicKey): InputObjectType
    {
        $meta = $this->resourceMeta[$publicKey];
        $fields = [];
        foreach ($meta['fields'] as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                continue;
            }
            $spec = $this->fieldSpec($row);
            $writable = (bool) ($spec['writable'] ?? true);
            $readonly = (bool) ($spec['readonly'] ?? false);
            $hidden = (bool) ($spec['hidden'] ?? false);
            if (!$writable || $readonly || $hidden) {
                continue;
            }
            $type = (string) ($row['type'] ?? 'string');
            $config = \is_array($spec['config'] ?? null) ? $spec['config'] : [];
            if ($type === 'relation' && ($config['cardinality'] ?? 'manyToOne') === 'oneToMany') {
                continue;
            }
            $fields[$name] = ['type' => $this->inputFieldType($type, $config)];
        }

        return new InputObjectType([
            'name' => TypeNames::input($publicKey),
            'fields' => $fields === []
                ? ['_noop' => ['type' => Type::boolean()]]
                : $fields,
        ]);
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function fieldSpec(array $field): array
    {
        $spec = \is_string($field['spec_json'] ?? null)
            ? json_decode((string) $field['spec_json'], true)
            : ($field['spec_json'] ?? null);

        return \is_array($spec) ? $spec : [];
    }

    private function outputType(string $type): Type
    {
        return match ($type) {
            'integer' => Type::int(),
            'float' => Type::float(),
            'boolean' => Type::boolean(),
            'json', 'image', 'file', 'blocks' => $this->jsonType,
            'enum' => Type::string(),
            default => Type::string(),
        };
    }

    /**
     * @param array<string, mixed> $config
     * @phpstan-return (Type&InputType)
     */
    private function inputFieldType(string $type, array $config): Type
    {
        if ($type === 'relation') {
            $cardinality = (string) ($config['cardinality'] ?? 'manyToOne');
            if ($cardinality === 'manyToMany') {
                return Type::listOf(Type::nonNull(Type::int()));
            }

            return Type::int();
        }

        return match ($type) {
            'integer' => Type::int(),
            'float' => Type::float(),
            'boolean' => Type::boolean(),
            'json', 'image', 'file', 'blocks' => $this->jsonType,
            default => Type::string(),
        };
    }

    private function metaType(): ObjectType
    {
        return new ObjectType([
            'name' => 'PageMeta',
            'fields' => [
                'total' => [
                    'type' => Type::nonNull(Type::int()),
                    'resolve' => static fn (array $m): int => (int) ($m['total'] ?? 0),
                ],
                'page' => [
                    'type' => Type::nonNull(Type::int()),
                    'resolve' => static fn (array $m): int => (int) ($m['page'] ?? 1),
                ],
                'limit' => [
                    'type' => Type::nonNull(Type::int()),
                    'resolve' => static fn (array $m): int => (int) ($m['limit'] ?? 20),
                ],
            ],
        ]);
    }

    private function filterInputType(): InputObjectType
    {
        return new InputObjectType([
            'name' => 'FilterInput',
            'fields' => [
                'field' => Type::nonNull(Type::string()),
                'op' => ['type' => Type::string(), 'defaultValue' => 'eq'],
                'value' => Type::nonNull(Type::string()),
            ],
        ]);
    }
}
