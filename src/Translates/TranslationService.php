<?php

declare(strict_types=1);

namespace Cms\Translates;

use Cms\Core\Exception\ValidationFailedException;
use Cms\Core\Settings;
use InvalidArgumentException;
use RuntimeException;

final class TranslationService
{
    public const SETTINGS_KEY = 'translates.api';
    public const DEFAULT_PATH = '/api/translates';

    public function __construct(
        private readonly LocaleRepository $locales,
        private readonly TranslationRepository $translations,
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
        $path = isset($payload['path']) && \is_string($payload['path'])
            ? self::normalizePath($payload['path'])
            : self::DEFAULT_PATH;
        self::assertValidPath($path);
        $value = [
            'enabled' => \array_key_exists('enabled', $payload) ? (bool) $payload['enabled'] : true,
            'path' => $path,
            'requireToken' => \array_key_exists('requireToken', $payload) ? (bool) $payload['requireToken'] : false,
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
            $path = '/api' . ($path === '/' ? '/translates' : $path);
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
    public function listLocales(): array
    {
        return array_map(fn (array $row): array => $this->serializeLocale($row), $this->locales->all());
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createLocale(array $payload): array
    {
        $code = isset($payload['code']) && \is_string($payload['code']) ? trim($payload['code']) : '';
        $label = isset($payload['label']) && \is_string($payload['label']) ? trim($payload['label']) : '';
        $fields = [];
        if ($code === '' || !preg_match('/^[a-z]{2}(-[A-Za-z]{2})?$/', $code)) {
            $fields['code'] = ['code must be like en or en-US'];
        } elseif ($this->locales->find($code) !== null) {
            $fields['code'] = ['locale already exists'];
        }
        if ($label === '' || mb_strlen($label) > 191) {
            $fields['label'] = ['label is required (max 191)'];
        }
        if ($fields !== []) {
            throw new ValidationFailedException('Validation failed', $fields);
        }
        $enabled = \array_key_exists('enabled', $payload) ? ((bool) $payload['enabled'] ? 1 : 0) : 1;
        $sortOrder = isset($payload['sortOrder']) && is_numeric($payload['sortOrder'])
            ? (int) $payload['sortOrder']
            : \count($this->locales->all());
        $isDefault = \array_key_exists('isDefault', $payload) && (bool) $payload['isDefault'];
        if ($isDefault || $this->locales->defaultLocale() === null) {
            $this->locales->clearDefault();
            $isDefault = true;
        }

        return $this->serializeLocale($this->locales->create([
            'code' => $code,
            'label' => $label,
            'enabled' => $enabled,
            'is_default' => $isDefault ? 1 : 0,
            'sort_order' => $sortOrder,
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateLocale(string $code, array $payload): array
    {
        if ($this->locales->find($code) === null) {
            throw new RuntimeException('Locale not found', 404);
        }
        $data = [];
        if (\array_key_exists('label', $payload)) {
            $label = \is_string($payload['label']) ? trim($payload['label']) : '';
            if ($label === '' || mb_strlen($label) > 191) {
                throw ValidationFailedException::field('label', 'label is required (max 191)');
            }
            $data['label'] = $label;
        }
        if (\array_key_exists('enabled', $payload)) {
            $data['enabled'] = (bool) $payload['enabled'] ? 1 : 0;
        }
        if (\array_key_exists('sortOrder', $payload) && is_numeric($payload['sortOrder'])) {
            $data['sort_order'] = (int) $payload['sortOrder'];
        }
        if (\array_key_exists('isDefault', $payload) && (bool) $payload['isDefault']) {
            $this->locales->clearDefault();
            $data['is_default'] = 1;
        }

        return $this->serializeLocale($this->locales->update($code, $data));
    }

    /**
     * @return array<string, mixed>
     */
    public function setDefaultLocale(string $code): array
    {
        if ($this->locales->find($code) === null) {
            throw new RuntimeException('Locale not found', 404);
        }
        $this->locales->clearDefault();

        return $this->serializeLocale($this->locales->update($code, ['is_default' => 1, 'enabled' => 1]));
    }

    public function deleteLocale(string $code): void
    {
        $row = $this->locales->find($code);
        if ($row === null) {
            throw new RuntimeException('Locale not found', 404);
        }
        if ((bool) $row['is_default']) {
            throw new InvalidArgumentException('Cannot delete the default locale');
        }
        $this->locales->delete($code);
        foreach ($this->translations->allRows() as $tr) {
            $values = $this->decodeValues($tr['values_json']);
            if (\array_key_exists($code, $values)) {
                unset($values[$code]);
                $this->translations->update((int) $tr['id'], [
                    'values_json' => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                ]);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTranslations(?string $search = null): array
    {
        return array_map(
            fn (array $row): array => $this->serializeTranslation($row),
            $this->translations->list($search),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getTranslation(int $id): array
    {
        $row = $this->translations->find($id);
        if ($row === null) {
            throw new RuntimeException('Translation not found', 404);
        }

        return $this->serializeTranslation($row);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createTranslation(array $payload): array
    {
        $key = isset($payload['key']) && \is_string($payload['key']) ? trim($payload['key']) : '';
        if ($key === '' || !preg_match('/^[a-z][a-z0-9_.-]{0,190}$/', $key)) {
            throw ValidationFailedException::field(
                'key',
                'key must match ^[a-z][a-z0-9_.-]{0,190}$',
            );
        }
        if ($this->translations->findByKey($key) !== null) {
            throw ValidationFailedException::field('key', 'translation key already exists');
        }
        $description = null;
        if (\array_key_exists('description', $payload)) {
            $description = \is_string($payload['description']) ? trim($payload['description']) : null;
            if ($description === '') {
                $description = null;
            }
            if ($description !== null && mb_strlen($description) > 255) {
                throw ValidationFailedException::field('description', 'description max 255');
            }
        }
        $values = $this->normalizeValues($payload['values'] ?? []);

        return $this->serializeTranslation($this->translations->create([
            'translation_key' => $key,
            'description' => $description,
            'values_json' => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateTranslation(int $id, array $payload): array
    {
        $existing = $this->translations->find($id);
        if ($existing === null) {
            throw new RuntimeException('Translation not found', 404);
        }
        $data = [];
        if (\array_key_exists('description', $payload)) {
            $description = \is_string($payload['description']) ? trim($payload['description']) : null;
            if ($description === '') {
                $description = null;
            }
            if ($description !== null && mb_strlen($description) > 255) {
                throw ValidationFailedException::field('description', 'description max 255');
            }
            $data['description'] = $description;
        }
        if (\array_key_exists('values', $payload)) {
            $current = $this->decodeValues($existing['values_json']);
            $incoming = $this->normalizeValues($payload['values'] ?? []);
            $data['values_json'] = json_encode(
                array_merge($current, $incoming),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) ?: '{}';
        }

        return $this->serializeTranslation($this->translations->update($id, $data));
    }

    public function deleteTranslation(int $id): void
    {
        if ($this->translations->find($id) === null) {
            throw new RuntimeException('Translation not found', 404);
        }
        $this->translations->delete($id);
    }

    /**
     * @param array<string, mixed> $map key => {locale: value}
     * @return array{created: int, updated: int}
     */
    public function import(array $map): array
    {
        $created = 0;
        $updated = 0;
        foreach ($map as $key => $values) {
            if (!\is_string($key) || !\is_array($values)) {
                continue;
            }
            $key = trim($key);
            if ($key === '' || !preg_match('/^[a-z][a-z0-9_.-]{0,190}$/', $key)) {
                continue;
            }
            $normalized = $this->normalizeValues($values);
            $existing = $this->translations->findByKey($key);
            if ($existing === null) {
                $this->translations->create([
                    'translation_key' => $key,
                    'description' => null,
                    'values_json' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                ]);
                ++$created;
            } else {
                $merged = array_merge($this->decodeValues($existing['values_json']), $normalized);
                $this->translations->update((int) $existing['id'], [
                    'values_json' => json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                ]);
                ++$updated;
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function export(): array
    {
        $out = [];
        foreach ($this->translations->allRows() as $row) {
            $out[(string) $row['translation_key']] = $this->decodeValues($row['values_json']);
        }

        return $out;
    }

    /**
     * @param list<string>|null $keys
     * @return array<string, string>
     */
    public function publicMap(?string $locale, ?array $keys = null): array
    {
        $enabled = $this->locales->enabled();
        if ($enabled === []) {
            return [];
        }
        $default = $this->locales->defaultLocale();
        $defaultCode = $default !== null ? (string) $default['code'] : (string) $enabled[0]['code'];

        if ($locale === null || $locale === '') {
            if (\count($enabled) === 1) {
                $locale = (string) $enabled[0]['code'];
            } else {
                throw new InvalidArgumentException('locale query parameter is required');
            }
        }

        $localeCodes = array_map(static fn (array $r): string => (string) $r['code'], $enabled);
        if (!\in_array($locale, $localeCodes, true)) {
            throw new InvalidArgumentException('Unknown or disabled locale');
        }

        $filter = null;
        if ($keys !== null && $keys !== []) {
            $filter = array_fill_keys($keys, true);
        }

        $out = [];
        foreach ($this->translations->allRows() as $row) {
            $key = (string) $row['translation_key'];
            if ($filter !== null && !isset($filter[$key])) {
                continue;
            }
            $values = $this->decodeValues($row['values_json']);
            $value = $values[$locale] ?? null;
            if ($value === null || $value === '') {
                $value = $values[$defaultCode] ?? '';
            }
            $out[$key] = \is_string($value) ? $value : (string) $value;
        }

        return $out;
    }

    public function etag(): string
    {
        $max = $this->translations->maxUpdatedAt() ?? '0';
        $settings = $this->getApiSettings();
        $locales = array_map(
            static fn (array $r): string => (string) $r['code'] . ':' . (int) $r['enabled'] . ':' . (int) $r['is_default'],
            $this->locales->all(),
        );

        return '"' . sha1($max . '|' . json_encode($settings) . '|' . implode(',', $locales)) . '"';
    }

    /**
     * @param mixed $raw
     * @return array<string, string>
     */
    private function normalizeValues(mixed $raw): array
    {
        if (!\is_array($raw)) {
            throw new InvalidArgumentException('values must be an object of locale → string');
        }
        $known = array_fill_keys(
            array_map(static fn (array $r): string => (string) $r['code'], $this->locales->all()),
            true,
        );
        $out = [];
        foreach ($raw as $code => $value) {
            if (!\is_string($code) || !isset($known[$code])) {
                continue;
            }
            if (!\is_string($value) && !is_numeric($value)) {
                throw new InvalidArgumentException('values.' . $code . ' must be a string');
            }
            $out[$code] = (string) $value;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function decodeValues(mixed $raw): array
    {
        $decoded = \is_string($raw) ? json_decode($raw, true) : $raw;
        if (!\is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            if (\is_string($k) && (\is_string($v) || is_numeric($v))) {
                $out[$k] = (string) $v;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serializeLocale(array $row): array
    {
        return [
            'code' => (string) $row['code'],
            'label' => (string) $row['label'],
            'enabled' => (bool) $row['enabled'],
            'isDefault' => (bool) $row['is_default'],
            'sortOrder' => (int) $row['sort_order'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serializeTranslation(array $row): array
    {
        $values = $this->decodeValues($row['values_json']);
        $enabledCodes = array_map(
            static fn (array $r): string => (string) $r['code'],
            $this->locales->enabled(),
        );
        $missing = [];
        foreach ($enabledCodes as $code) {
            if (!isset($values[$code]) || $values[$code] === '') {
                $missing[] = $code;
            }
        }

        return [
            'id' => (int) $row['id'],
            'key' => (string) $row['translation_key'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'values' => $values,
            'missing' => $missing,
            'updatedAt' => (string) $row['updated_at'],
        ];
    }
}
