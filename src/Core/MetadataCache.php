<?php

declare(strict_types=1);

namespace Cms\Core;

/**
 * Thin invalidation helper for OpenAPI / metadata file cache.
 */
final class MetadataCache
{
    public const OPENAPI_KEY = 'openapi.json';

    public function __construct(private readonly FileCache $cache)
    {
    }

    public function fileCache(): FileCache
    {
        return $this->cache;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOpenApi(): ?array
    {
        $value = $this->cache->get(self::OPENAPI_KEY);

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $spec
     */
    public function setOpenApi(array $spec, int $ttl = 3600): void
    {
        $this->cache->set(self::OPENAPI_KEY, $spec, $ttl);
    }

    public function invalidate(): void
    {
        $this->cache->forget(self::OPENAPI_KEY);
    }

    public function flush(): void
    {
        $this->cache->flush();
    }
}
