<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\ApiLogRepository;
use Cms\Audit\AuditLogger;
use Cms\Audit\AuditRepository;
use Cms\Auth\AuthContext;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Security\IpBlockRepository;

final class LogsController
{
    public function __construct(
        private readonly AuditRepository $audit,
        private readonly ApiLogRepository $apiLogs,
        private readonly ?IpBlockRepository $ipBlocks = null,
        private readonly ?AuditLogger $auditLogger = null,
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
        $path = isset($request->query['path']) && is_string($request->query['path'])
            ? $request->query['path']
            : null;
        $minStatus = isset($request->query['minStatus']) ? (int) $request->query['minStatus'] : null;
        $days = isset($request->query['days']) ? (int) $request->query['days'] : null;

        return Response::json($this->apiLogs->page($page, $limit, $path, $minStatus, $days));
    }

    public function anomalies(Request $request, AuthContext $auth): Response
    {
        unset($auth, $request);
        if ($this->ipBlocks === null) {
            return Response::data([
                'failedLogins' => [],
                'rateLimited' => [],
                'publicCreates' => [],
                'status429' => [],
            ]);
        }

        return Response::data([
            'failedLogins' => $this->ipBlocks->topAuditIps([
                'auth.login_failed',
                'auth.login_blocked',
                'auth.login_denied',
            ], 24, 10),
            'rateLimited' => $this->ipBlocks->topAuditIps(['security.ip_blocked'], 24, 10),
            'publicCreates' => $this->ipBlocks->topApiIps(24, 10),
            'status429' => $this->ipBlocks->topApiIps(24, 10, 429),
        ]);
    }

    public function ipBlocks(Request $request, AuthContext $auth): Response
    {
        unset($auth);
        if ($this->ipBlocks === null) {
            return Response::json(['data' => [], 'meta' => ['page' => 1, 'limit' => 50, 'total' => 0, 'totalPages' => 1]]);
        }
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $limit = max(1, (int) ($request->query['limit'] ?? 50));

        return Response::json($this->ipBlocks->page($page, $limit));
    }

    public function blockIp(Request $request, AuthContext $auth): Response
    {
        if ($this->ipBlocks === null) {
            return Response::error('SERVICE_UNAVAILABLE', 'Unavailable', 503);
        }
        $payload = $request->json();
        $ip = isset($payload['ip']) && is_string($payload['ip']) ? trim($payload['ip']) : '';
        $reason = isset($payload['reason']) && is_string($payload['reason']) ? trim($payload['reason']) : 'manual';
        $ttl = isset($payload['ttlSeconds']) ? (int) $payload['ttlSeconds'] : 3600;
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return Response::error('VALIDATION_ERROR', 'Valid IP is required', 422);
        }
        $expires = $ttl > 0 ? date('Y-m-d H:i:s', time() + $ttl) : null;
        $id = $this->ipBlocks->block($ip, $reason !== '' ? $reason : 'manual', $expires, $auth->userId());
        $this->auditLogger?->log($request, 'security.ip_blocked', $auth->userId(), 'ip', $ip, [
            'reason' => $reason,
            'expiresAt' => $expires,
        ]);

        return Response::data([
            'id' => $id,
            'ip' => $ip,
            'reason' => $reason,
            'expiresAt' => $expires,
        ], 201);
    }

    public function unblockIp(Request $request, AuthContext $auth, int $id): Response
    {
        if ($this->ipBlocks === null) {
            return Response::error('SERVICE_UNAVAILABLE', 'Unavailable', 503);
        }
        if ($id <= 0) {
            return Response::error('VALIDATION_ERROR', 'Invalid id', 422);
        }
        $this->ipBlocks->delete($id);
        $this->auditLogger?->log($request, 'security.ip_unblocked', $auth->userId(), 'ip', (string) $id);

        return new Response(204, '');
    }
}
