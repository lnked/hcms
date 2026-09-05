<?php

declare(strict_types=1);

namespace Cms\OpenApi;

use Cms\Core\Config;
use Cms\Core\Version;
use Cms\Fields\FieldRepository;
use Cms\Resources\ResourceRepository;

final class OpenApiGenerator
{
    public function __construct(
        private readonly Config $config,
        private readonly ?ResourceRepository $resources = null,
        private readonly ?FieldRepository $fields = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $paths = [];
        $schemas = [];
        $tags = [];

        if ($this->resources !== null && $this->fields !== null) {
            foreach ($this->resources->all() as $resource) {
                if (($resource['status'] ?? '') !== 'published') {
                    continue;
                }
                $settings = is_string($resource['settings_json'])
                    ? json_decode((string) $resource['settings_json'], true)
                    : $resource['settings_json'];
                if (is_array($settings) && ($settings['apiEnabled'] ?? true) === false) {
                    continue;
                }

                $slug = (string) $resource['slug'];
                $label = (string) ($resource['content_type_label'] ?? $slug);
                $tag = $label;
                $tags[] = ['name' => $tag, 'description' => 'Resource `' . $slug . '`'];

                $fieldRows = $this->fields->forContentType((int) $resource['content_type_id']);
                $schemaName = $this->schemaName($slug);
                $inputName = $schemaName . 'Input';
                $schemas[$schemaName] = $this->itemSchema($fieldRows);
                $schemas[$inputName] = $this->inputSchema($fieldRows);

                $public = is_array($settings['public'] ?? null) ? $settings['public'] : [];
                $paths['/' . $slug] = $this->collectionPath($slug, $tag, $schemaName, $inputName, $public, $fieldRows);
                $paths['/' . $slug . '/{id}'] = $this->itemPath($slug, $tag, $schemaName, $inputName, $public);
            }
        }

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'HCMS API',
                'version' => Version::current(),
                'description' => 'Generated public API for published resources.',
            ],
            'servers' => [
                ['url' => rtrim($this->config->appUrl, '/') . '/api'],
                ['url' => rtrim($this->config->appUrl, '/') . '/api/v1'],
            ],
            'tags' => $tags,
            'paths' => $paths === [] ? new \stdClass() : $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'API token',
                    ],
                ],
                'schemas' => $schemas === [] ? new \stdClass() : $schemas,
            ],
            'security' => [
                ['bearerAuth' => []],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $fieldRows
     * @return array<string, mixed>
     */
    private function itemSchema(array $fieldRows): array
    {
        $properties = [
            'id' => ['type' => 'integer', 'readOnly' => true],
            'createdAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'readOnly' => true],
            'updatedAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'readOnly' => true],
        ];
        foreach ($fieldRows as $field) {
            $spec = $this->spec($field);
            if (!($spec['readable'] ?? true) || ($spec['hidden'] ?? false)) {
                continue;
            }
            $properties[(string) $field['name']] = $this->propertySchema((string) $field['type'], $spec);
        }

        return [
            'type' => 'object',
            'properties' => $properties,
        ];
    }

    /**
     * @param list<array<string, mixed>> $fieldRows
     * @return array<string, mixed>
     */
    private function inputSchema(array $fieldRows): array
    {
        $properties = [];
        $required = [];
        foreach ($fieldRows as $field) {
            $spec = $this->spec($field);
            if (!($spec['writable'] ?? true) || ($spec['hidden'] ?? false) || ($spec['readonly'] ?? false)) {
                continue;
            }
            $name = (string) $field['name'];
            $properties[$name] = $this->propertySchema((string) $field['type'], $spec);
            if ($spec['required'] ?? false) {
                $required[] = $name;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function propertySchema(string $type, array $spec): array
    {
        $schema = match ($type) {
            'integer' => ['type' => 'integer'],
            'float' => ['type' => 'number', 'format' => 'double'],
            'boolean' => ['type' => 'boolean'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'datetime' => ['type' => 'string', 'format' => 'date-time'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'url' => ['type' => 'string', 'format' => 'uri'],
            'uuid' => ['type' => 'string', 'format' => 'uuid'],
            'json' => ['type' => 'object', 'additionalProperties' => true],
            'text' => ['type' => 'string'],
            'enum' => [
                'type' => 'string',
                'enum' => array_values(array_map('strval', is_array($spec['config']['options'] ?? null) ? $spec['config']['options'] : [])),
            ],
            'image', 'file' => ['type' => 'integer', 'description' => 'Media id'],
            default => ['type' => 'string'],
        };

        if (($spec['nullable'] ?? true) === true) {
            $schema['nullable'] = true;
        }
        if (isset($spec['config']['maxLength']) && is_numeric($spec['config']['maxLength']) && $type === 'string') {
            $schema['maxLength'] = (int) $spec['config']['maxLength'];
        }

        return $schema;
    }

    /**
     * @param list<array<string, mixed>> $fieldRows
     * @param array<string, mixed> $public
     * @return array<string, mixed>
     */
    private function collectionPath(
        string $slug,
        string $tag,
        string $schemaName,
        string $inputName,
        array $public,
        array $fieldRows,
    ): array {
        $parameters = [
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]],
            ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 20, 'maximum' => 100]],
            ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Field or -field'],
            ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string']],
        ];
        foreach ($fieldRows as $field) {
            $spec = $this->spec($field);
            if ($spec['filterable'] ?? false) {
                $name = (string) $field['name'];
                $parameters[] = [
                    'name' => 'filter[' . $name . ']',
                    'in' => 'query',
                    'schema' => ['type' => 'string'],
                ];
            }
        }

        $path = [
            'get' => [
                'tags' => [$tag],
                'summary' => 'List ' . $slug,
                'operationId' => 'list_' . $slug,
                'parameters' => $parameters,
                'responses' => [
                    '200' => [
                        'description' => 'OK',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => ['$ref' => '#/components/schemas/' . $schemaName],
                                        ],
                                        'meta' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'page' => ['type' => 'integer'],
                                                'limit' => ['type' => 'integer'],
                                                'total' => ['type' => 'integer'],
                                                'totalPages' => ['type' => 'integer'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'post' => [
                'tags' => [$tag],
                'summary' => 'Create ' . $slug,
                'operationId' => 'create_' . $slug,
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/' . $inputName],
                        ],
                    ],
                ],
                'responses' => [
                    '201' => [
                        'description' => 'Created',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => ['$ref' => '#/components/schemas/' . $schemaName],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        if (($public['read'] ?? false) === true) {
            $path['get']['security'] = [];
        }
        if (($public['create'] ?? false) === true) {
            $path['post']['security'] = [];
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $public
     * @return array<string, mixed>
     */
    private function itemPath(
        string $slug,
        string $tag,
        string $schemaName,
        string $inputName,
        array $public,
    ): array {
        $idParam = [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'integer'],
        ];

        $path = [
            'get' => [
                'tags' => [$tag],
                'summary' => 'Get ' . $slug,
                'operationId' => 'get_' . $slug,
                'parameters' => [$idParam],
                'responses' => [
                    '200' => [
                        'description' => 'OK',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => ['$ref' => '#/components/schemas/' . $schemaName],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    '404' => ['description' => 'Not found'],
                ],
            ],
            'patch' => [
                'tags' => [$tag],
                'summary' => 'Update ' . $slug,
                'operationId' => 'patch_' . $slug,
                'parameters' => [$idParam],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/' . $inputName],
                        ],
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'OK',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => ['$ref' => '#/components/schemas/' . $schemaName],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'delete' => [
                'tags' => [$tag],
                'summary' => 'Delete ' . $slug,
                'operationId' => 'delete_' . $slug,
                'parameters' => [$idParam],
                'responses' => [
                    '204' => ['description' => 'Deleted'],
                    '404' => ['description' => 'Not found'],
                ],
            ],
        ];

        if (($public['read'] ?? false) === true) {
            $path['get']['security'] = [];
        }
        if (($public['update'] ?? false) === true) {
            $path['patch']['security'] = [];
        }
        if (($public['delete'] ?? false) === true) {
            $path['delete']['security'] = [];
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function spec(array $field): array
    {
        $spec = is_string($field['spec_json'])
            ? json_decode((string) $field['spec_json'], true)
            : $field['spec_json'];

        return is_array($spec) ? $spec : [];
    }

    private function schemaName(string $slug): string
    {
        $parts = explode('_', $slug);
        $name = '';
        foreach ($parts as $part) {
            $name .= ucfirst($part);
        }

        return $name === '' ? 'Resource' : $name;
    }
}
