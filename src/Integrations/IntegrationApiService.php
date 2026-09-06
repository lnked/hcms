<?php

declare(strict_types=1);

namespace Cms\Integrations;

use Cms\Core\MetadataCache;
use InvalidArgumentException;
use RuntimeException;

final class IntegrationApiService
{
    public const EMAIL_KEY = 'email';

    /** @var list<string> */
    private const RESERVED_SLUGS = ['send'];

    public function __construct(
        private readonly IntegrationApiRepository $apis,
        private readonly ?MetadataCache $metadata = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEmail(): array
    {
        return array_map(
            fn (array $row): array => $this->serialize($row),
            $this->apis->forIntegration(self::EMAIL_KEY),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getEmail(int $id): array
    {
        $row = $this->requireEmailApi($id);

        return $this->serialize($row);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createEmail(array $payload): array
    {
        $normalized = $this->normalizePayload($payload, null);
        $existing = $this->apis->findByKeyAndSlug(self::EMAIL_KEY, $normalized['slug']);
        if ($existing !== null) {
            throw new InvalidArgumentException('Slug already exists');
        }

        $row = $this->apis->create([
            'integration_key' => self::EMAIL_KEY,
            ...$normalized,
        ]);
        $this->metadata?->invalidate();

        return $this->serialize($row);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateEmail(int $id, array $payload): array
    {
        $existing = $this->requireEmailApi($id);
        $normalized = $this->normalizePayload($payload, $existing);
        if ($normalized['slug'] !== (string) $existing['slug']) {
            $conflict = $this->apis->findByKeyAndSlug(self::EMAIL_KEY, $normalized['slug']);
            if ($conflict !== null && (int) $conflict['id'] !== $id) {
                throw new InvalidArgumentException('Slug already exists');
            }
        }

        $updated = $this->serialize($this->apis->update($id, $normalized));
        $this->metadata?->invalidate();

        return $updated;
    }

    public function deleteEmail(int $id): void
    {
        $this->requireEmailApi($id);
        $this->apis->delete($id);
        $this->metadata?->invalidate();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findEnabledEmailBySlug(string $slug): ?array
    {
        $row = $this->apis->findByKeyAndSlug(self::EMAIL_KEY, $slug);
        if ($row === null || !(bool) $row['enabled']) {
            return null;
        }

        return $this->serialize($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireEmailApi(int $id): array
    {
        $row = $this->apis->find($id);
        if ($row === null || (string) $row['integration_key'] !== self::EMAIL_KEY) {
            throw new RuntimeException('Integration API not found', 404);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $existing
     * @return array{
     *   slug: string,
     *   label: string,
     *   enabled: bool,
     *   defaults: array{subject: string, html: string, text: string},
     *   settings: array{allowFromOverride: bool}
     * }
     */
    private function normalizePayload(array $payload, ?array $existing): array
    {
        $slug = isset($payload['slug']) && is_string($payload['slug'])
            ? strtolower(trim($payload['slug']))
            : (is_string($existing['slug'] ?? null) ? (string) $existing['slug'] : '');
        if ($slug === '' || !preg_match('/^[a-z][a-z0-9_-]{0,62}$/', $slug) || ctype_digit($slug)) {
            throw new InvalidArgumentException('Invalid slug');
        }
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            throw new InvalidArgumentException('Slug is reserved');
        }

        $label = isset($payload['label']) && is_string($payload['label'])
            ? trim($payload['label'])
            : (is_string($existing['label'] ?? null) ? (string) $existing['label'] : '');
        if ($label === '') {
            throw new InvalidArgumentException('Label is required');
        }

        $enabled = array_key_exists('enabled', $payload)
            ? (bool) $payload['enabled']
            : (bool) ($existing['enabled'] ?? true);

        $defaultsIn = $payload['defaults'] ?? null;
        if ($defaultsIn === null && $existing !== null) {
            $decoded = json_decode((string) ($existing['defaults_json'] ?? '{}'), true);
            $defaultsIn = is_array($decoded) ? $decoded : [];
        }
        if ($defaultsIn === null) {
            $defaultsIn = [];
        }
        if (!is_array($defaultsIn)) {
            throw new InvalidArgumentException('defaults must be an object');
        }

        $settingsIn = $payload['settings'] ?? null;
        if ($settingsIn === null && $existing !== null) {
            $decoded = json_decode((string) ($existing['settings_json'] ?? '{}'), true);
            $settingsIn = is_array($decoded) ? $decoded : [];
        }
        if ($settingsIn === null) {
            $settingsIn = ['allowFromOverride' => true];
        }
        if (!is_array($settingsIn)) {
            throw new InvalidArgumentException('settings must be an object');
        }

        return [
            'slug' => $slug,
            'label' => $label,
            'enabled' => $enabled,
            'defaults' => [
                'subject' => isset($defaultsIn['subject']) && is_string($defaultsIn['subject'])
                    ? $defaultsIn['subject']
                    : '',
                'html' => isset($defaultsIn['html']) && is_string($defaultsIn['html'])
                    ? $defaultsIn['html']
                    : '',
                'text' => isset($defaultsIn['text']) && is_string($defaultsIn['text'])
                    ? $defaultsIn['text']
                    : '',
            ],
            'settings' => [
                'allowFromOverride' => (bool) ($settingsIn['allowFromOverride'] ?? true),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        $defaults = json_decode((string) ($row['defaults_json'] ?? '{}'), true);
        $settings = json_decode((string) ($row['settings_json'] ?? '{}'), true);
        if (!is_array($defaults)) {
            $defaults = [];
        }
        if (!is_array($settings)) {
            $settings = [];
        }
        $slug = (string) $row['slug'];

        return [
            'id' => (int) $row['id'],
            'integrationKey' => (string) $row['integration_key'],
            'slug' => $slug,
            'label' => (string) $row['label'],
            'enabled' => (bool) $row['enabled'],
            'defaults' => [
                'subject' => is_string($defaults['subject'] ?? null) ? $defaults['subject'] : '',
                'html' => is_string($defaults['html'] ?? null) ? $defaults['html'] : '',
                'text' => is_string($defaults['text'] ?? null) ? $defaults['text'] : '',
            ],
            'settings' => [
                'allowFromOverride' => (bool) ($settings['allowFromOverride'] ?? true),
            ],
            'path' => '/api/integrations/email/' . $slug,
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }
}
