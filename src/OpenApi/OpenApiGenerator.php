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
use Cms\Resources\ResourceApiService;
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
                $settings = \is_string($resource['settings_json'])
                    ? json_decode((string) $resource['settings_json'], true)
                    : $resource['settings_json'];
                if (\is_array($settings) && ($settings['apiEnabled'] ?? true) === false) {
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

                $public = \is_array($settings['public'] ?? null) ? $settings['public'] : [];
                $paths['/' . $pathKey] = $this->collectionPath($slug, $tag, $schemaName, $inputName, $public, $fieldRows);
                $paths['/' . $pathKey . '/{id}'] = $this->itemPath($slug, $tag, $schemaName, $inputName, $public);

                if ($this->apis !== null) {
                    foreach ($this->apis->enabledForResource((int) $resource['id']) as $apiRow) {
                        $apiSlug = (string) $apiRow['slug'];
                        $apiLabel = (string) ($apiRow['label'] ?? $apiSlug);
                        $apiFields = $apiRow['fields_json'];
                        if (\is_string($apiFields)) {
                            $apiFields = json_decode($apiFields, true);
                        }
                        $apiJoins = $apiRow['joins_json'];
                        if (\is_string($apiJoins)) {
                            $apiJoins = json_decode($apiJoins, true);
                        }
                        $apiSettings = $apiRow['settings_json'];
                        if (\is_string($apiSettings)) {
                            $apiSettings = json_decode($apiSettings, true);
                        }
                        $apiPublic = $this->customPublicAccess(
                            \is_array($apiSettings) ? $apiSettings : [],
                            $public,
                        );
                        $apiMethods = ResourceApiService::normalizeMethods(
                            \is_string($apiRow['methods_json'])
                                ? json_decode((string) $apiRow['methods_json'], true)
                                : $apiRow['methods_json'],
                        );

                        $customSchema = $this->customItemSchema(
                            $fieldRows,
                            \is_array($apiFields) ? $apiFields : null,
                            \is_array($apiJoins) ? $apiJoins : [],
                        );
                        $customSchemaName = $this->schemaName($slug . '_' . $apiSlug);
                        $schemas[$customSchemaName] = $customSchema;

                        $customInputName = null;
                        if (ResourceApiService::hasWriteMethod($apiMethods)) {
                            $customInputName = $customSchemaName . 'Input';
                            $schemas[$customInputName] = $this->inputSchema(
                                $fieldRows,
                                \is_array($apiFields) ? array_values(array_map('strval', $apiFields)) : null,
                            );
                        }

                        $customCollection = $this->customCollectionPath(
                            $slug,
                            $apiSlug,
                            $apiLabel,
                            $tag,
                            $customSchemaName,
                            $customInputName,
                            $apiMethods,
                            $apiPublic,
                            $fieldRows,
                        );
                        if ($customCollection !== []) {
                            $paths['/' . $pathKey . '/' . $apiSlug] = $customCollection;
                        }
                        $customItem = $this->customItemPath(
                            $slug,
                            $apiSlug,
                            $apiLabel,
                            $tag,
                            $customSchemaName,
                            $customInputName,
                            $apiMethods,
                            $apiPublic,
                        );
                        if ($customItem !== []) {
                            $paths['/' . $pathKey . '/' . $apiSlug . '/{id}'] = $customItem;
                        }
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

        $tags[] = ['name' => 'Features', 'description' => 'Feature flags'];
        $tags[] = ['name' => 'Translates', 'description' => 'Translation keys'];
        $paths['/features'] = $this->featuresPath();
        $paths['/translates'] = $this->translatesPath();

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
     * @return array<string, mixed>
     */
    private function featuresPath(): array
    {
        return [
            'get' => [
                'tags' => ['Features'],
                'summary' => 'List feature flags',
                'description' => 'Returns enabled flags as a key→value map. Filter with `keys`. '
                    . 'Boolean A/B flags evaluate via sticky bucket from `subject` / `X-Flag-Subject`.',
                'parameters' => [
                    [
                        'name' => 'keys',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string'],
                        'description' => 'Comma-separated flag keys',
                    ],
                    [
                        'name' => 'subject',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string', 'maxLength' => 128],
                        'description' => 'Stable subject id for A/B bucketing (alias: sid). Or header X-Flag-Subject.',
                    ],
                ],
                'security' => [],
                'responses' => [
                    '200' => [
                        'description' => 'Flag map',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'object',
                                            'additionalProperties' => true,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    '401' => ['description' => 'Unauthorized when requireToken is enabled'],
                    '404' => ['description' => 'API disabled'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function translatesPath(): array
    {
        return [
            'get' => [
                'tags' => ['Translates'],
                'summary' => 'List translations for a locale',
                'description' => 'Returns key→string map for the given locale with fallback to default locale.',
                'parameters' => [
                    [
                        'name' => 'locale',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string'],
                        'description' => 'Locale code (required when multiple locales are enabled)',
                    ],
                    [
                        'name' => 'keys',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string'],
                        'description' => 'Comma-separated translation keys',
                    ],
                ],
                'security' => [],
                'responses' => [
                    '200' => [
                        'description' => 'Translation map',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'object',
                                            'additionalProperties' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    '401' => ['description' => 'Unauthorized when requireToken is enabled'],
                    '404' => ['description' => 'API disabled'],
                    '422' => ['description' => 'Missing or unknown locale'],
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
            if (!isset($cached['components']) || !\is_array($cached['components'])) {
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
     * @param list<string>|null $only Restricts the body to a custom API projection
     * @return array<string, mixed>
     */
    private function inputSchema(array $fieldRows, ?array $only = null): array
    {
        $properties = [];
        $required = [];
        foreach ($fieldRows as $field) {
            $spec = $this->spec($field);
            if (!($spec['writable'] ?? true) || ($spec['hidden'] ?? false) || ($spec['readonly'] ?? false)) {
                continue;
            }
            $name = (string) $field['name'];
            if ($only !== null && !\in_array($name, $only, true)) {
                continue;
            }
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
                'enum' => array_values(array_map('strval', \is_array($spec['config']['options'] ?? null) ? $spec['config']['options'] : [])),
            ],
            'image', 'file' => $this->mediaPropertySchema($spec),
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
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function mediaPropertySchema(array $spec): array
    {
        $cropRect = [
            'type' => 'object',
            'description' => 'Crop rect as 0..1 fractions of the rotated image',
            'properties' => [
                'x' => ['type' => 'number'],
                'y' => ['type' => 'number'],
                'w' => ['type' => 'number'],
                'h' => ['type' => 'number'],
            ],
        ];
        $item = [
            'type' => 'object',
            'description' => 'Media value with original and optional variants',
            'properties' => [
                'id' => ['type' => 'integer'],
                'sourceId' => [
                    'type' => 'integer',
                    'nullable' => true,
                    'description' => 'Untouched original when id points at an edited master',
                ],
                'rotation' => ['type' => 'integer', 'enum' => [0, 90, 180, 270]],
                'edit' => [
                    'type' => 'object',
                    'nullable' => true,
                    'description' => 'Base edit baked into the master image',
                    'properties' => [
                        'rotation' => ['type' => 'integer', 'enum' => [0, 90, 180, 270]],
                        'flipH' => ['type' => 'boolean'],
                        'flipV' => ['type' => 'boolean'],
                        'crop' => $cropRect + ['nullable' => true],
                    ],
                ],
                'positions' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                'overrides' => [
                    'type' => 'object',
                    'description' => 'Per-size crop overrides keyed by size prefix',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => ['crop' => $cropRect],
                    ],
                ],
                'variants' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'url' => [
                                'type' => 'string',
                                'description' => 'Relative public path /media/{id}/{filename}',
                            ],
                            'fullUrl' => [
                                'type' => 'string',
                                'format' => 'uri',
                                'description' => 'Absolute public URL (APP_URL + url)',
                            ],
                            'width' => ['type' => 'integer', 'nullable' => true],
                            'height' => ['type' => 'integer', 'nullable' => true],
                        ],
                    ],
                ],
                'media' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'url' => [
                            'type' => 'string',
                            'description' => 'Relative public path /media/{id}/{filename}',
                        ],
                        'fullUrl' => [
                            'type' => 'string',
                            'format' => 'uri',
                            'description' => 'Absolute public URL (APP_URL + url)',
                        ],
                        'mime' => ['type' => 'string'],
                        'width' => ['type' => 'integer', 'nullable' => true],
                        'height' => ['type' => 'integer', 'nullable' => true],
                    ],
                ],
            ],
        ];
        $multiple = (bool) ($spec['config']['multiple'] ?? false);
        if ($multiple) {
            return ['type' => 'array', 'items' => $item];
        }

        return $item;
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
        $spec = \is_string($field['spec_json'])
            ? json_decode((string) $field['spec_json'], true)
            : $field['spec_json'];

        return \is_array($spec) ? $spec : [];
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
     * @param array<mixed>|null $fields
     * @param array<mixed> $joins
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
                if (!\is_string($name) || !isset($byName[$name])) {
                    continue;
                }
                $field = $byName[$name];
                $spec = $this->spec($field);
                $properties[$name] = $this->propertySchema((string) $field['type'], $spec);
            }
        }

        foreach ($joins as $join) {
            if (!\is_array($join)) {
                continue;
            }
            $as = isset($join['as']) && \is_string($join['as']) ? $join['as'] : '';
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
     * Resolves the tri-state public flags of a custom API against its resource.
     *
     * @param array<string, mixed> $apiSettings
     * @param array<string, mixed> $resourcePublic
     * @return array<string, bool>
     */
    private function customPublicAccess(array $apiSettings, array $resourcePublic): array
    {
        $apiPublic = \is_array($apiSettings['public'] ?? null) ? $apiSettings['public'] : [];
        $out = [];
        foreach (['read', 'create', 'update', 'delete'] as $action) {
            $out[$action] = \array_key_exists($action, $apiPublic) && $apiPublic[$action] !== null
                ? (bool) $apiPublic[$action]
                : (bool) ($resourcePublic[$action] ?? false);
        }

        return $out;
    }

    /**
     * @param list<string> $methods
     * @param array<string, bool> $public
     * @param list<array<string, mixed>> $fieldRows
     * @return array<string, mixed>
     */
    private function customCollectionPath(
        string $slug,
        string $apiSlug,
        string $apiLabel,
        string $tag,
        string $schemaName,
        ?string $inputName,
        array $methods,
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

        $operationSuffix = $slug . '_' . str_replace('-', '_', $apiSlug);
        $path = [];
        if (\in_array('GET', $methods, true)) {
            $path['get'] = [
                'tags' => [$tag],
                'summary' => $apiLabel . ' (list)',
                'operationId' => 'list_' . $operationSuffix,
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
            ];
            if ($public['read'] ?? false) {
                $path['get']['security'] = [];
            }
        }

        if (\in_array('POST', $methods, true) && $inputName !== null) {
            $path['post'] = [
                'tags' => [$tag],
                'summary' => $apiLabel . ' (create)',
                'operationId' => 'create_' . $operationSuffix,
                'requestBody' => $this->jsonRequestBody($inputName),
                'responses' => [
                    '201' => $this->jsonDataResponse('Created', $schemaName),
                    '422' => ['description' => 'Validation error'],
                ],
            ];
            if ($public['create'] ?? false) {
                $path['post']['security'] = [];
            }
        }

        return $path;
    }

    /**
     * @param list<string> $methods
     * @param array<string, bool> $public
     * @return array<string, mixed>
     */
    private function customItemPath(
        string $slug,
        string $apiSlug,
        string $apiLabel,
        string $tag,
        string $schemaName,
        ?string $inputName,
        array $methods,
        array $public,
    ): array {
        $idParam = [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'integer'],
        ];
        $operationSuffix = $slug . '_' . str_replace('-', '_', $apiSlug);
        $path = [];

        if (\in_array('GET', $methods, true)) {
            $path['get'] = [
                'tags' => [$tag],
                'summary' => $apiLabel . ' (item)',
                'operationId' => 'get_' . $operationSuffix,
                'parameters' => [$idParam],
                'responses' => [
                    '200' => $this->jsonDataResponse('OK', $schemaName),
                    '404' => ['description' => 'Not found'],
                ],
            ];
            if ($public['read'] ?? false) {
                $path['get']['security'] = [];
            }
        }

        if (\in_array('PATCH', $methods, true) && $inputName !== null) {
            $path['patch'] = [
                'tags' => [$tag],
                'summary' => $apiLabel . ' (update)',
                'operationId' => 'patch_' . $operationSuffix,
                'parameters' => [$idParam],
                'requestBody' => $this->jsonRequestBody($inputName),
                'responses' => [
                    '200' => $this->jsonDataResponse('OK', $schemaName),
                    '404' => ['description' => 'Not found'],
                    '422' => ['description' => 'Validation error'],
                ],
            ];
            if ($public['update'] ?? false) {
                $path['patch']['security'] = [];
            }
        }

        if (\in_array('DELETE', $methods, true)) {
            $path['delete'] = [
                'tags' => [$tag],
                'summary' => $apiLabel . ' (delete)',
                'operationId' => 'delete_' . $operationSuffix,
                'parameters' => [$idParam],
                'responses' => [
                    '204' => ['description' => 'Deleted'],
                    '404' => ['description' => 'Not found'],
                ],
            ];
            if ($public['delete'] ?? false) {
                $path['delete']['security'] = [];
            }
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonRequestBody(string $inputName): array
    {
        return [
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/' . $inputName],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonDataResponse(string $description, string $schemaName): array
    {
        return [
            'description' => $description,
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
        ];
    }
}
