<?php

declare(strict_types=1);

namespace Cms\OpenApi;

use Cms\Core\Config;
use Cms\Core\MetadataCache;
use Cms\Core\Version;
use Cms\Fields\FieldRepository;
use Cms\Integrations\IntegrationApiRepository;
use Cms\Integrations\IntegrationApiService;
use Cms\Resources\ResourceApiRepository;
use Cms\Resources\ResourceRepository;
use Cms\Resources\ResourceService;

final class OpenApiGenerator
{
    public function __construct(
        private readonly Config $config,
        private readonly ?ResourceRepository $resources = null,
        private readonly ?FieldRepository $fields = null,
        private readonly ?MetadataCache $metadata = null,
        private readonly ?ResourceApiRepository $apis = null,
        private readonly ?IntegrationApiRepository $integrationApis = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $cached = $this->metadata?->getOpenApi();
        if ($cached !== null) {
            return $this->normalizeCached($cached);
        }

        $spec = $this->build();
        $this->metadata?->setOpenApi($spec);

        return $spec;
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
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
                $pathKey = ResourceService::publicKeyFromEndpoint((string) ($resource['endpoint'] ?? ''))
                    ?? $slug;
                $label = (string) ($resource['content_type_label'] ?? $slug);
                $tag = $label;
                $tags[] = ['name' => $tag, 'description' => 'Resource `' . $slug . '`'];

                $fieldRows = $this->fields->forContentType((int) $resource['content_type_id']);
                $schemaName = $this->schemaName($slug);
                $inputName = $schemaName . 'Input';
                $schemas[$schemaName] = $this->itemSchema($fieldRows);
                $schemas[$inputName] = $this->inputSchema($fieldRows);

                $public = is_array($settings['public'] ?? null) ? $settings['public'] : [];
                $paths['/' . $pathKey] = $this->collectionPath($slug, $tag, $schemaName, $inputName, $public, $fieldRows);
                $paths['/' . $pathKey . '/{id}'] = $this->itemPath($slug, $tag, $schemaName, $inputName, $public);

                if ($this->apis !== null) {
                    foreach ($this->apis->enabledForResource((int) $resource['id']) as $apiRow) {
                        $apiSlug = (string) $apiRow['slug'];
                        $apiLabel = (string) ($apiRow['label'] ?? $apiSlug);
                        $apiFields = $apiRow['fields_json'];
                        if (is_string($apiFields)) {
                            $apiFields = json_decode($apiFields, true);
                        }
                        $apiJoins = $apiRow['joins_json'];
                        if (is_string($apiJoins)) {
                            $apiJoins = json_decode($apiJoins, true);
                        }
                        $apiSettings = $apiRow['settings_json'];
                        if (is_string($apiSettings)) {
                            $apiSettings = json_decode($apiSettings, true);
                        }
                        $apiPublicRead = is_array($apiSettings['public'] ?? null)
                            && array_key_exists('read', $apiSettings['public'])
                            && $apiSettings['public']['read'] !== null
                            ? (bool) $apiSettings['public']['read']
                            : (bool) ($public['read'] ?? false);

                        $customSchema = $this->customItemSchema(
                            $fieldRows,
                            is_array($apiFields) ? $apiFields : null,
                            is_array($apiJoins) ? $apiJoins : [],
                        );
                        $customSchemaName = $this->schemaName($slug . '_' . $apiSlug);
                        $schemas[$customSchemaName] = $customSchema;

                        $paths['/' . $pathKey . '/' . $apiSlug] = $this->customCollectionPath(
                            $slug,
                            $apiSlug,
                            $apiLabel,
                            $tag,
                            $customSchemaName,
                            $apiPublicRead,
                            $fieldRows,
                        );
                        $paths['/' . $pathKey . '/' . $apiSlug . '/{id}'] = $this->customItemPath(
                            $slug,
                            $apiSlug,
                            $apiLabel,
                            $tag,
                            $customSchemaName,
                            $apiPublicRead,
                        );
                    }
                }
            }
        }

        $tags[] = [
            'name' => 'Integrations',
            'description' => 'Token-only integration endpoints (Email grant required).',
        ];
        $schemas['EmailSendRequest'] = [
            'type' => 'object',
            'required' => ['to'],
            'properties' => [
                'to' => ['type' => 'string', 'format' => 'email'],
                'subject' => ['type' => 'string'],
                'html' => ['type' => 'string'],
                'text' => ['type' => 'string'],
                'fromEmail' => ['type' => 'string', 'format' => 'email'],
                'fromName' => ['type' => 'string'],
                'vars' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ],
        ];
        $schemas['EmailSendResponse'] = [
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'object',
                    'properties' => [
                        'ok' => ['type' => 'boolean'],
                        'provider' => ['type' => 'string'],
                        'to' => ['type' => 'string', 'format' => 'email'],
                    ],
                ],
            ],
        ];
        $paths['/integrations/email/send'] = $this->emailSendPath('Built-in send endpoint');
        if ($this->integrationApis !== null) {
            foreach ($this->integrationApis->enabledForIntegration(IntegrationApiService::EMAIL_KEY) as $row) {
                $slug = (string) ($row['slug'] ?? '');
                if ($slug === '' || $slug === 'send') {
                    continue;
                }
                $label = (string) ($row['label'] ?? $slug);
                $paths['/integrations/email/' . $slug] = $this->emailSendPath('Custom email API: ' . $label);
            }
        }

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'HCMS API',
                'version' => Version::current(),
                'description' => 'Generated public API for published resources and integrations.',
            ],
            'servers' => [
                ['url' => rtrim($this->config->appUrl, '/') . '/api'],
                ['url' => rtrim($this->config->appUrl, '/') . '/api/v1'],
            ],
            'tags' => $tags,
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'API token',
                    ],
                ],
                'schemas' => $schemas,
            ],
            'security' => [
                ['bearerAuth' => []],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emailSendPath(string $summary): array
    {
        return [
            'post' => [
                'tags' => ['Integrations'],
                'summary' => $summary,
                'security' => [['bearerAuth' => []]],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/EmailSendRequest'],
                        ],
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Email accepted by provider',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/EmailSendResponse'],
                            ],
                        ],
                    ],
                    '401' => ['description' => 'Unauthorized'],
                    '403' => ['description' => 'Forbidden — missing Email grant'],
                    '422' => ['description' => 'Validation error'],
                    '502' => ['description' => 'Provider error'],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $cached
     * @return array<string, mixed>
     */
    private function normalizeCached(array $cached): array
    {
        if (($cached['paths'] ?? null) === [] || ($cached['paths'] ?? null) === null) {
            $cached['paths'] = new \stdClass();
        }
        $schemas = $cached['components']['schemas'] ?? null;
        if ($schemas === [] || $schemas === null) {
            if (!isset($cached['components']) || !is_array($cached['components'])) {
                $cached['components'] = [];
            }
            $cached['components']['schemas'] = new \stdClass();
        }

        return $cached;
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
            'richtext' => ['type' => 'string', 'format' => 'markdown'],
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
            ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Tokenized word search; ranked by match count'],
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
        $parts = preg_split('/[_-]+/', $slug) ?: [];
        $name = '';
        foreach ($parts as $part) {
            $name .= ucfirst($part);
        }

        return $name === '' ? 'Resource' : $name;
    }

    /**
     * @param list<array<string, mixed>> $fieldRows
     * @param list<mixed>|null $fields
     * @param list<mixed> $joins
     * @return array<string, mixed>
     */
    private function customItemSchema(array $fieldRows, ?array $fields, array $joins): array
    {
        $byName = [];
        foreach ($fieldRows as $field) {
            $byName[(string) $field['name']] = $field;
        }

        $properties = [
            'id' => ['type' => 'integer', 'readOnly' => true],
        ];

        if ($fields === null) {
            $properties['createdAt'] = ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'readOnly' => true];
            $properties['updatedAt'] = ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'readOnly' => true];
            foreach ($fieldRows as $field) {
                $spec = $this->spec($field);
                if (!($spec['readable'] ?? true) || ($spec['hidden'] ?? false)) {
                    continue;
                }
                $properties[(string) $field['name']] = $this->propertySchema((string) $field['type'], $spec);
            }
        } else {
            foreach ($fields as $name) {
                if (!is_string($name) || !isset($byName[$name])) {
                    continue;
                }
                $field = $byName[$name];
                $spec = $this->spec($field);
                $properties[$name] = $this->propertySchema((string) $field['type'], $spec);
            }
        }

        foreach ($joins as $join) {
            if (!is_array($join)) {
                continue;
            }
            $as = isset($join['as']) && is_string($join['as']) ? $join['as'] : '';
            if ($as === '') {
                continue;
            }
            $properties[$as] = [
                'type' => 'object',
                'nullable' => true,
                'additionalProperties' => true,
                'description' => 'Embedded related resource `' . ($join['relatedSlug'] ?? '') . '`',
            ];
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
    private function customCollectionPath(
        string $slug,
        string $apiSlug,
        string $apiLabel,
        string $tag,
        string $schemaName,
        bool $publicRead,
        array $fieldRows,
    ): array {
        $parameters = [
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]],
            ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 20, 'maximum' => 100]],
            ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Field or -field'],
            ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Tokenized word search; ranked by match count'],
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
                'summary' => $apiLabel . ' (list)',
                'operationId' => 'list_' . $slug . '_' . str_replace('-', '_', $apiSlug),
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
        ];
        if ($publicRead) {
            $path['get']['security'] = [];
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function customItemPath(
        string $slug,
        string $apiSlug,
        string $apiLabel,
        string $tag,
        string $schemaName,
        bool $publicRead,
    ): array {
        $path = [
            'get' => [
                'tags' => [$tag],
                'summary' => $apiLabel . ' (item)',
                'operationId' => 'get_' . $slug . '_' . str_replace('-', '_', $apiSlug),
                'parameters' => [
                    [
                        'name' => 'id',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'integer'],
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
                    '404' => ['description' => 'Not found'],
                ],
            ],
        ];
        if ($publicRead) {
            $path['get']['security'] = [];
        }

        return $path;
    }
}
