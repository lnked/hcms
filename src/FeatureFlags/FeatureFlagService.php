<?php

declare(strict_types=1);

namespace Cms\FeatureFlags;

use Cms\Core\Exception\ValidationFailedException;
use Cms\Core\Settings;
use RuntimeException;

final class FeatureFlagService
{
    public const TYPES = ['boolean', 'integer', 'string', 'object'];
    public const SETTINGS_KEY = 'features.api';
    public const DEFAULT_PATH = '/api/features';

    public function __construct(
        private readonly FeatureFlagRepository $flags,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @return array{enabled: bool, path: string, requireToken: bool}
     */
    public function getApiSettings(): array
    {
        $raw = $this->settings->get(self::SETTINGS_KEY);
        if (!\is_array($raw)) {
            return self::defaultApiSettings();
        }

        return [
            'enabled' => \array_key_exists('enabled', $raw) ? (bool) $raw['enabled'] : true,
            'path' => isset($raw['path']) && \is_string($raw['path']) && $raw['path'] !== ''
                ? self::normalizePath($raw['path'])
                : self::DEFAULT_PATH,
            'requireToken' => \array_key_exists('requireToken', $raw) ? (bool) $raw['requireToken'] : false,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{enabled: bool, path: string, requireToken: bool}
     */
    public function saveApiSettings(array $payload): array
    {
        $enabled = \array_key_exists('enabled', $payload) ? (bool) $payload['enabled'] : true;
        $requireToken = \array_key_exists('requireToken', $payload) ? (bool) $payload['requireToken'] : false;
        $path = isset($payload['path']) && \is_string($payload['path'])
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
            $path = '/api' . ($path === '/' ? '/features' : $path);
        }

        return $path === '/api' ? self::DEFAULT_PATH : $path;
    }

    public static function assertValidPath(string $path): void
    {
        if (!preg_match('#^/api(/v1)?/[a-z][a-z0-9_/-]{0,62}$#', $path)) {
            throw ValidationFailedException::field(
                'path',
                'path must match /api/{slug} or /api/v1/{slug} (lowercase, digits, _, -, /)',
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $search = null, ?string $type = null, ?bool $enabled = null): array
    {
        return array_map(
            fn (array $row): array => $this->serialize($row),
            $this->flags->list($search, $type, $enabled),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        $row = $this->flags->find($id);
        if ($row === null) {
            throw new RuntimeException('Feature flag not found', 404);
        }

        return $this->serialize($row);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $data = $this->normalizeCreate($payload);
        if ($this->flags->findByKey($data['flag_key']) !== null) {
            throw ValidationFailedException::field('key', 'flag_key already exists');
        }

        return $this->serialize($this->flags->create($data));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $existing = $this->flags->find($id);
        if ($existing === null) {
            throw new RuntimeException('Feature flag not found', 404);
        }
        $data = $this->normalizeUpdate($payload, $existing);

        return $this->serialize($this->flags->update($id, $data));
    }

    public function delete(int $id): void
    {
        if ($this->flags->find($id) === null) {
            throw new RuntimeException('Feature flag not found', 404);
        }
        $this->flags->delete($id);
    }

    /**
     * Public map of enabled flags, optionally filtered by keys.
     *
     * A/B boolean flags: sticky bucket from subject+key (crc32 % 100 < rollout%).
     * Without subject, assignment is random per request (non-sticky).
     *
     * @param list<string>|null $keys
     * @return array{map: array<string, mixed>, hasAb: bool, subject: ?string}
     */
    public function publicMap(?array $keys = null, ?string $subject = null): array
    {
        $rows = $this->flags->listEnabled();
        $out = [];
        $hasAb = false;
        $filter = null;
        if ($keys !== null && $keys !== []) {
            $filter = array_fill_keys($keys, true);
        }
        foreach ($rows as $row) {
            $key = (string) $row['flag_key'];
            if ($filter !== null && !isset($filter[$key])) {
                continue;
            }
            $type = (string) $row['type'];
            $value = $this->decodeValue($type, $row['value_json']);
            $ab = (bool) ($row['ab_test'] ?? false);
            if ($ab && $type === 'boolean') {
                $hasAb = true;
                $percent = (int) ($row['rollout_percent'] ?? 100);
                $sid = $subject;
                if ($sid === null || $sid === '') {
                    $sid = bin2hex(random_bytes(8));
                }
                $value = self::inRollout($sid, $key, $percent);
            }
            $out[$key] = $value;
        }

        return [
            'map' => $out,
            'hasAb' => $hasAb,
            'subject' => $subject !== null && $subject !== '' ? $subject : null,
        ];
    }

    /**
     * Deterministic bucket: same subject+flag always lands in the same 0..99 slot.
     */
    public static function inRollout(string $subject, string $flagKey, int $percent): bool
    {
        if ($percent <= 0) {
            return false;
        }
        if ($percent >= 100) {
            return true;
        }
        $hash = \sprintf('%u', crc32($flagKey . "\0" . $subject));
        $bucket = (int) $hash % 100;

        return $bucket < $percent;
    }

    public function etag(?string $subject = null): string
    {
        $max = $this->flags->maxUpdatedAt() ?? '0';
        $settings = $this->getApiSettings();

        return '"' . sha1($max . '|' . json_encode($settings) . '|' . ($subject ?? '')) . '"';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   name: string,
     *   flag_key: string,
     *   type: string,
     *   value_json: string,
     *   description: ?string,
     *   enabled: int,
     *   ab_test: int,
     *   rollout_percent: int
     * }
     */
    private function normalizeCreate(array $payload): array
    {
        $name = isset($payload['name']) && \is_string($payload['name']) ? trim($payload['name']) : '';
        if ($name === '' || mb_strlen($name) > 191) {
            throw ValidationFailedException::field('name', 'name is required (max 191)');
        }
        $key = isset($payload['key']) && \is_string($payload['key'])
            ? trim($payload['key'])
            : (isset($payload['flagKey']) && \is_string($payload['flagKey']) ? trim($payload['flagKey']) : '');
        if ($key === '' || !preg_match('/^[a-z][a-zA-Z0-9_]{0,63}$/', $key)) {
            throw ValidationFailedException::field('key', 'key must match ^[a-z][a-zA-Z0-9_]{0,63}$');
        }
        $type = isset($payload['type']) && \is_string($payload['type']) ? trim($payload['type']) : '';
        if (!\in_array($type, self::TYPES, true)) {
            throw ValidationFailedException::field('type', 'type must be boolean, integer, string, or object');
        }
        if (!\array_key_exists('value', $payload)) {
            throw ValidationFailedException::field('value', 'value is required');
        }
        $desc = $payload['description'] ?? null;
        if ($desc !== null && !\is_string($desc)) {
            throw ValidationFailedException::field('description', 'description must be a string');
        }
        $description = $desc !== null ? trim($desc) : null;
        if ($description === '') {
            $description = null;
        }
        $ab = $this->normalizeAb($payload, $type);

        return [
            'name' => $name,
            'flag_key' => $key,
            'type' => $type,
            'value_json' => json_encode(
                $this->normalizeValue($type, $payload['value']),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) ?: 'null',
            'description' => $description,
            'enabled' => \array_key_exists('enabled', $payload) ? ((bool) $payload['enabled'] ? 1 : 0) : 1,
            'ab_test' => $ab['ab_test'],
            'rollout_percent' => $ab['rollout_percent'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $existing
     * @return array{
     *   name?: string,
     *   type?: string,
     *   value_json?: string,
     *   description?: ?string,
     *   enabled?: int,
     *   ab_test?: int,
     *   rollout_percent?: int
     * }
     */
    private function normalizeUpdate(array $payload, array $existing): array
    {
        $out = [];
        $type = (string) $existing['type'];
        if (\array_key_exists('name', $payload)) {
            $name = \is_string($payload['name']) ? trim($payload['name']) : '';
            if ($name === '' || mb_strlen($name) > 191) {
                throw ValidationFailedException::field('name', 'name is required (max 191)');
            }
            $out['name'] = $name;
        }

        if (\array_key_exists('type', $payload)) {
            $type = \is_string($payload['type']) ? trim($payload['type']) : '';
            if (!\in_array($type, self::TYPES, true)) {
                throw ValidationFailedException::field('type', 'type must be boolean, integer, string, or object');
            }
            $out['type'] = $type;
        }

        if (\array_key_exists('value', $payload)) {
            $out['value_json'] = json_encode(
                $this->normalizeValue($type, $payload['value']),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) ?: 'null';
        }

        if (\array_key_exists('description', $payload)) {
            $desc = $payload['description'];
            if ($desc !== null && !\is_string($desc)) {
                throw ValidationFailedException::field('description', 'description must be a string');
            }
            $out['description'] = \is_string($desc) ? (trim($desc) !== '' ? trim($desc) : null) : null;
        }

        if (\array_key_exists('enabled', $payload)) {
            $out['enabled'] = (bool) $payload['enabled'] ? 1 : 0;
        }

        if (
            \array_key_exists('abTest', $payload)
            || \array_key_exists('ab_test', $payload)
            || \array_key_exists('rolloutPercent', $payload)
            || \array_key_exists('rollout_percent', $payload)
            || \array_key_exists('type', $payload)
        ) {
            $merged = $payload;
            if (!\array_key_exists('abTest', $merged) && !\array_key_exists('ab_test', $merged)) {
                $merged['abTest'] = (bool) ($existing['ab_test'] ?? false);
            }
            if (!\array_key_exists('rolloutPercent', $merged) && !\array_key_exists('rollout_percent', $merged)) {
                $merged['rolloutPercent'] = (int) ($existing['rollout_percent'] ?? 100);
            }
            $ab = $this->normalizeAb($merged, $type);
            $out['ab_test'] = $ab['ab_test'];
            $out['rollout_percent'] = $ab['rollout_percent'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ab_test: int, rollout_percent: int}
     */
    private function normalizeAb(array $payload, string $type): array
    {
        $ab = (bool) ($payload['abTest'] ?? $payload['ab_test'] ?? false);
        if ($ab && $type !== 'boolean') {
            throw ValidationFailedException::field('abTest', 'A/B test is only supported for boolean flags');
        }
        if (!$ab) {
            return ['ab_test' => 0, 'rollout_percent' => 100];
        }
        $percentRaw = $payload['rolloutPercent'] ?? $payload['rollout_percent'] ?? 50;
        if (!is_numeric($percentRaw)) {
            throw ValidationFailedException::field('rolloutPercent', 'rolloutPercent must be an integer 0-100');
        }
        $percent = (int) $percentRaw;
        if ($percent < 0 || $percent > 100) {
            throw ValidationFailedException::field('rolloutPercent', 'rolloutPercent must be an integer 0-100');
        }

        return [
            'ab_test' => 1,
            'rollout_percent' => $percent,
        ];
    }

    private function normalizeValue(string $type, mixed $value): mixed
    {
        return match ($type) {
            'boolean' => (bool) $value,
            'integer' => is_numeric($value)
                ? (int) $value
                : throw ValidationFailedException::field('value', 'value must be an integer'),
            'string' => \is_string($value) || is_numeric($value)
                ? (string) $value
                : throw ValidationFailedException::field('value', 'value must be a string'),
            'object' => (\is_array($value) && (array_is_list($value) || $this->isAssoc($value)))
                ? $value
                : throw ValidationFailedException::field('value', 'value must be a JSON object or array'),
            default => throw ValidationFailedException::field('type', 'Unknown type'),
        };
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssoc(array $value): bool
    {
        return $value === [] || !array_is_list($value);
    }

    private function decodeValue(string $type, mixed $raw): mixed
    {
        $decoded = \is_string($raw) ? json_decode($raw, true) : $raw;
        if (json_last_error() !== JSON_ERROR_NONE && \is_string($raw)) {
            return match ($type) {
                'boolean' => false,
                'integer' => 0,
                'string' => '',
                default => new \stdClass(),
            };
        }

        return match ($type) {
            'boolean' => (bool) $decoded,
            'integer' => (int) $decoded,
            'string' => \is_string($decoded) ? $decoded : (string) $decoded,
            'object' => \is_array($decoded) ? $decoded : [],
            default => $decoded,
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        $type = (string) $row['type'];

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'key' => (string) $row['flag_key'],
            'type' => $type,
            'value' => $this->decodeValue($type, $row['value_json']),
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'enabled' => (bool) $row['enabled'],
            'abTest' => (bool) ($row['ab_test'] ?? false),
            'rolloutPercent' => (int) ($row['rollout_percent'] ?? 100),
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }
}
