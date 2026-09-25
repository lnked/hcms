<?php

declare(strict_types=1);

namespace Cms\GraphQL;

use Cms\Core\Settings;

/**
 * Reads GraphQL opt-in flags from cms_settings.
 * Canonical keys: api.graphql.enabled / api.graphql.playground.
 * Legacy fallback: graphql.enabled (pre-0.62.21).
 */
final class GraphqlSettings
{
    public const ENABLED_KEY = 'api.graphql.enabled';

    public const PLAYGROUND_KEY = 'api.graphql.playground';

    /** @deprecated migrate to ENABLED_KEY */
    public const LEGACY_ENABLED_KEY = 'graphql.enabled';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->bool(self::ENABLED_KEY, $this->bool(self::LEGACY_ENABLED_KEY, false));
    }

    /** GraphiQL HTML — off by default even when the API is enabled. */
    public function playground(): bool
    {
        return $this->enabled() && $this->bool(self::PLAYGROUND_KEY, false);
    }

    /**
     * @return array{enabled: bool, playground: bool}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled(),
            'playground' => $this->bool(self::PLAYGROUND_KEY, false),
        ];
    }

    public function setEnabled(bool $enabled): void
    {
        $this->settings->set(self::ENABLED_KEY, $enabled);
        // Keep legacy key in sync so older installs / docs still work.
        $this->settings->set(self::LEGACY_ENABLED_KEY, $enabled);
    }

    public function setPlayground(bool $playground): void
    {
        $this->settings->set(self::PLAYGROUND_KEY, $playground);
    }

    private function bool(string $key, bool $default): bool
    {
        $value = $this->settings->get($key);
        if ($value === null) {
            return $default;
        }

        return $value === true || $value === 1 || $value === '1';
    }
}
