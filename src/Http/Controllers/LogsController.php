<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\ApiLogRepository;
use Cms\Audit\AuditRepository;
use Cms\Auth\AuthContext;
use Cms\Http\Request;
use Cms\Http\Response;

final class LogsController
{
    public function __construct(
        private readonly AuditRepository $audit,
        private readonly ApiLogRepository $apiLogs,
    ) {
    }

    public function audit(Request $request, AuthContext $auth): Response
    {
        unset($auth);
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $limit = max(1, (int) ($request->query['limit'] ?? 50));
        $action = $request->query['action'] ?? null;

        return Response::json($this->audit->page($page, $limit, $action));
    }

    public function api(Request $request, AuthContext $auth): Response
    {
        unset($auth);
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $limit = max(1, (int) ($request->query['limit'] ?? 50));

        return Response::json($this->apiLogs->page($page, $limit));
    }
}
