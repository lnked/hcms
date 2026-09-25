<?php

declare(strict_types=1);

namespace Cms\Preview;

use InvalidArgumentException;

/**
 * Signed short-TTL preview tokens (HMAC with APP_SECRET).
 * Payload: resourceId, entryId, slug, exp — resolve reads live entry from DB.
 */
final class PreviewTokenService
{
    private const DEFAULT_TTL_SECONDS = 900;

    public function __construct(
        private readonly string $appSecret,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
    }

    /**
     * @return array{token: string, expiresAt: int}
     */
    public function issue(int $resourceId, int $entryId, string $slug): array
    {
        if ($this->appSecret === '') {
            throw new InvalidArgumentException('APP_SECRET is not configured');
        }
        $exp = time() + max(60, $this->ttlSeconds);
        $data = [
            'resourceId' => $resourceId,
            'entryId' => $entryId,
            'slug' => $slug,
            'exp' => $exp,
            'nonce' => bin2hex(random_bytes(8)),
        ];
        $json = json_encode($data, JSON_UNESCAPED_SLASHES) ?: '{}';
        $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $token = $b64 . '.' . hash_hmac('sha256', $b64, $this->appSecret);

        return ['token' => $token, 'expiresAt' => $exp];
    }

    /**
     * @return array{resourceId: int, entryId: int, slug: string, exp: int}
     */
    public function parse(string $token): array
    {
        if ($this->appSecret === '') {
            throw new InvalidArgumentException('Invalid preview token');
        }
        $parts = explode('.', $token, 2);
        if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException('Invalid preview token');
        }
        [$payload, $sig] = $parts;
        $expected = hash_hmac('sha256', $payload, $this->appSecret);
        if (!hash_equals($expected, $sig)) {
            throw new InvalidArgumentException('Invalid preview token');
        }
        $b64 = strtr($payload, '-_', '+/');
        $pad = \strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $json = base64_decode($b64, true);
        if ($json === false) {
            throw new InvalidArgumentException('Invalid preview token');
        }
        $data = json_decode($json, true);
        if (!\is_array($data)) {
            throw new InvalidArgumentException('Invalid preview token');
        }
        $exp = (int) ($data['exp'] ?? 0);
        if ($exp < time()) {
            throw new InvalidArgumentException('Preview token expired');
        }
        $resourceId = (int) ($data['resourceId'] ?? 0);
        $entryId = (int) ($data['entryId'] ?? 0);
        $slug = isset($data['slug']) && \is_string($data['slug']) ? $data['slug'] : '';
        if ($resourceId < 1 || $entryId < 1 || $slug === '') {
            throw new InvalidArgumentException('Invalid preview token');
        }

        return [
            'resourceId' => $resourceId,
            'entryId' => $entryId,
            'slug' => $slug,
            'exp' => $exp,
        ];
    }

    /**
     * @param array{token: string, expiresAt: int} $issued
     */
    public static function buildPreviewUrl(string $template, string $slug, int $entryId, array $issued): string
    {
        $url = str_replace(
            ['{token}', '{slug}', '{id}', '{expiresAt}'],
            [$issued['token'], $slug, (string) $entryId, (string) $issued['expiresAt']],
            $template,
        );

        return $url;
    }
}
