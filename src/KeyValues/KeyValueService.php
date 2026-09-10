<?php

declare(strict_types=1);

namespace Cms\KeyValues;

use Cms\Core\Settings;
use InvalidArgumentException;
use RuntimeException;

final class KeyValueService
{
    public const SETTINGS_KEY = 'key_values.api';
    public const DEFAULT_PATH = '/api/kv';

    public function __construct(
        private readonly KeyValueRepository $entries,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @return array{enabled: bool, path: string, requireToken: bool}
     */
    public function getApiSettings(): array
    {
        $raw = $this->settings->get(self::SETTINGS_KEY);
        if (!is_array($raw)) {
            return self::defaultApiSettings();
        }

        return [
            'enabled' => array_key_exists('enabled', $raw) ? (bool) $raw['enabled'] : true,
            'path' => isset($raw['path']) && is_string($raw['path']) && $raw['path'] !== ''
                ? self::normalizePath($raw['path'])
                : self::DEFAULT_PATH,
            'requireToken' => array_key_exists('requireToken', $raw) ? (bool) $raw['requireToken'] : false,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{enabled: bool, path: string, requireToken: bool}
     */
    public function saveApiSettings(array $payload): array
    {
        $enabled = array_key_exists('enabled', $payload) ? (bool) $payload['enabled'] : true;
        $requireToken = array_key_exists('requireToken', $payload) ? (bool) $payload['requireToken'] : false;
        $path = isset($payload['path']) && is_string($payload['path'])
            ? self::normalizePath($payload['path'])
            : self::DEFAULT_PATH;
        self::assertValidPath($path);
        $value = [
            'enabled' => $enabled,
            'path' => $path,
            'requireToken' => $requireToken,
        ];
        $this->settings->set(self::SETTINGS_KEY, $value);

        return $value;
    }

    /**
     * @return array{enabled: bool, path: string, requireToken: bool}
     */
    public static function defaultApiSettings(): array
    {
        return [
            'enabled' => true,
            'path' => self::DEFAULT_PATH,
            'requireToken' => false,
        ];
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, " \t\n\r\0\x0B/");
        if (!str_starts_with($path, '/api')) {
            $path = '/api' . ($path === '/' ? '/kv' : $path);
        }

        return $path === '/api' ? self::DEFAULT_PATH : $path;
    }

    public static function assertValidPath(string $path): void
    {
        if (!preg_match('#^/api(/v1)?/[a-z][a-z0-9_/-]{0,62}$#', $path)) {
            throw new InvalidArgumentException(
                'path must match /api/{slug} or /api/v1/{slug} (lowercase, digits, _, -, /)',
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $search = null): array
    {
        return array_map(
            fn (array $row): array => $this->serialize($row),
            $this->entries->list($search),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        $row = $this->entries->find($id);
        if ($row === null) {
            throw new RuntimeException('Key-value entry not found', 404);
        }

        return $this->serialize($row);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload, ?int $userId): array
    {
        $key = $this->normalizeKey($payload);
        if ($this->entries->findByKey($key) !== null) {
            throw new InvalidArgumentException('key already exists');
        }
        if (!array_key_exists('value', $payload)) {
            throw new InvalidArgumentException('value is required');
        }

        return $this->serialize($this->entries->create([
            'entry_key' => $key,
            'value_json' => $this->encodeValue($payload['value']),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload, ?int $userId): array
    {
        $existing = $this->entries->find($id);
        if ($existing === null) {
            throw new RuntimeException('Key-value entry not found', 404);
        }
        $data = ['updated_by' => $userId];
        if (array_key_exists('value', $payload)) {
            $data['value_json'] = $this->encodeValue($payload['value']);
        }

        return $this->serialize($this->entries->update($id, $data));
    }

    public function delete(int $id): void
    {
        if ($this->entries->find($id) === null) {
            throw new RuntimeException('Key-value entry not found', 404);
        }
        $this->entries->delete($id);
    }

    /**
     * Public map of all entries, optionally filtered by keys.
     *
     * @param list<string>|null $keys
     * @return array<string, mixed>
     */
    public function publicMap(?array $keys = null): array
    {
        $rows = $this->entries->listAll();
        $out = [];
        $filter = null;
        if ($keys !== null && $keys !== []) {
            $filter = array_fill_keys($keys, true);
        }
        foreach ($rows as $row) {
            $key = (string) $row['entry_key'];
            if ($filter !== null && !isset($filter[$key])) {
                continue;
            }
            $out[$key] = $this->decodeValue($row['value_json']);
        }

        return $out;
    }

    public function etag(): string
    {
        $max = $this->entries->maxUpdatedAt() ?? '0';
        $settings = $this->getApiSettings();

        return '"' . sha1($max . '|' . json_encode($settings)) . '"';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function normalizeKey(array $payload): string
    {
        $key = isset($payload['key']) && is_string($payload['key'])
            ? trim($payload['key'])
            : '';
        if ($key === '' || !preg_match('/^[a-z][a-zA-Z0-9_]{0,63}$/', $key)) {
            throw new InvalidArgumentException(
                'key must match ^[a-z][a-zA-Z0-9_]{0,63}$',
            );
        }

        return $key;
    }

    private function encodeValue(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new InvalidArgumentException('value must be JSON-serializable');
        }

        return $encoded;
    }

    private function decodeValue(mixed $raw): mixed
    {
        if (!is_string($raw)) {
            return $raw;
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $raw;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'key' => (string) $row['entry_key'],
            'value' => $this->decodeValue($row['value_json']),
            'createdBy' => $this->serializeAuthor(
                $row['created_by'] ?? null,
                $row['created_by_name'] ?? null,
                $row['created_by_email'] ?? null,
            ),
            'updatedBy' => $this->serializeAuthor(
                $row['updated_by'] ?? null,
                $row['updated_by_name'] ?? null,
                $row['updated_by_email'] ?? null,
            ),
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /**
     * @return array{id: int, name: string, email: string}|null
     */
    private function serializeAuthor(mixed $id, mixed $name, mixed $email): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }

        return [
            'id' => (int) $id,
            'name' => is_string($name) ? $name : '',
            'email' => is_string($email) ? $email : '',
        ];
    }
}
