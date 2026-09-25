<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Api\QueryEngine;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Preview\PreviewTokenService;
use InvalidArgumentException;
use RuntimeException;

final class PreviewController
{
    public function __construct(
        private readonly PreviewTokenService $tokens,
        private readonly QueryEngine $query,
    ) {
    }

    public function resolve(Request $request, string $token): Response
    {
        unset($request);
        try {
            $claims = $this->tokens->parse($token);
            $entry = $this->query->find($claims['slug'], $claims['entryId']);

            return Response::data([
                'resourceId' => $claims['resourceId'],
                'slug' => $claims['slug'],
                'entry' => $entry,
                'expiresAt' => $claims['exp'],
            ])->withHeaders(['Cache-Control' => 'private, no-store']);
        } catch (InvalidArgumentException $e) {
            return Response::error('UNAUTHORIZED', $e->getMessage(), 401);
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            $status = \in_array($code, [401, 403, 404], true) ? $code : 404;

            return Response::error(
                $status === 404 ? 'NOT_FOUND' : 'UNAUTHORIZED',
                $e->getMessage(),
                $status,
            );
        }
    }
}
