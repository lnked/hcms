<?php

declare(strict_types=1);

namespace Cms\Hooks;

use Cms\Http\Request;

final class RequestMeta
{
    /**
     * @return array{ip: string, userAgent: string, origin: ?string, source: string}
     */
    public static function fromRequest(Request $request, string $source): array
    {
        $origin = $request->headers['origin'] ?? null;

        return [
            'ip' => $request->ip,
            'userAgent' => $request->userAgent,
            'origin' => \is_string($origin) && $origin !== '' ? $origin : null,
            'source' => $source,
        ];
    }
}
