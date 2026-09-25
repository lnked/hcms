<?php

declare(strict_types=1);

namespace Cms\Webhooks;

/**
 * Known revalidation / build-hook presets for outbound webhooks.
 */
final class WebhookPresets
{
    public const CUSTOM = 'custom';
    public const VERCEL_DEPLOY = 'vercel_deploy';
    public const NETLIFY_BUILD = 'netlify_build';
    public const CLOUDFLARE_PURGE = 'cloudflare_purge';
    public const FASTLY_PURGE = 'fastly_purge';

    public const PAYLOAD_HCMS = 'hcms';
    public const PAYLOAD_EMPTY = 'empty';
    public const PAYLOAD_SURROGATE_KEYS = 'surrogate_keys';

    /** @var list<string> */
    public const ALL = [
        self::CUSTOM,
        self::VERCEL_DEPLOY,
        self::NETLIFY_BUILD,
        self::CLOUDFLARE_PURGE,
        self::FASTLY_PURGE,
    ];

    /** @var list<string> */
    public const PAYLOAD_MODES = [
        self::PAYLOAD_HCMS,
        self::PAYLOAD_EMPTY,
        self::PAYLOAD_SURROGATE_KEYS,
    ];

    /**
     * @return array{payloadMode: string, defaultEvents: list<string>}
     */
    public static function defaults(string $preset): array
    {
        return match ($preset) {
            self::VERCEL_DEPLOY, self::NETLIFY_BUILD => [
                'payloadMode' => self::PAYLOAD_EMPTY,
                'defaultEvents' => ['entry.created', 'entry.updated', 'entry.deleted', 'resource.published'],
            ],
            self::CLOUDFLARE_PURGE, self::FASTLY_PURGE => [
                'payloadMode' => self::PAYLOAD_SURROGATE_KEYS,
                'defaultEvents' => ['entry.updated', 'entry.deleted', 'resource.published'],
            ],
            default => [
                'payloadMode' => self::PAYLOAD_HCMS,
                'defaultEvents' => ['entry.created'],
            ],
        };
    }

    public static function normalizePreset(?string $preset): ?string
    {
        if ($preset === null || $preset === '' || $preset === self::CUSTOM) {
            return null;
        }

        return \in_array($preset, self::ALL, true) ? $preset : null;
    }

    public static function normalizePayloadMode(?string $mode, ?string $preset): string
    {
        if (\is_string($mode) && \in_array($mode, self::PAYLOAD_MODES, true)) {
            return $mode;
        }
        if ($preset !== null) {
            return self::defaults($preset)['payloadMode'];
        }

        return self::PAYLOAD_HCMS;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{body: string, contentType: string}
     */
    public static function encodeBody(string $payloadMode, array $payload): array
    {
        if ($payloadMode === self::PAYLOAD_EMPTY) {
            return ['body' => '{}', 'contentType' => 'application/json; charset=utf-8'];
        }

        if ($payloadMode === self::PAYLOAD_SURROGATE_KEYS) {
            $slug = \is_string($payload['slug'] ?? null) ? $payload['slug'] : '';
            $keys = array_values(array_filter([$slug], static fn (string $k): bool => $k !== ''));
            $body = json_encode(
                [
                    'surrogate_keys' => $keys,
                    'tags' => $keys,
                    'files' => [],
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) ?: '{}';

            return ['body' => $body, 'contentType' => 'application/json; charset=utf-8'];
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        return ['body' => $body, 'contentType' => 'application/json; charset=utf-8'];
    }
}
