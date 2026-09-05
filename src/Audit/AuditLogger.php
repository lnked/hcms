<?php

declare(strict_types=1);

namespace Cms\Audit;

use Cms\Database\Connection;
use Cms\Http\Request;

final class AuditLogger
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function log(
        Request $request,
        string $action,
        ?int $userId = null,
        ?string $entityType = null,
        ?string $entityId = null,
        array $metadata = [],
    ): void {
        $this->db->execute(
            'INSERT INTO cms_audit_logs (user_id, action, entity_type, entity_id, metadata_json, ip, user_agent, created_at)
             VALUES (:user_id, :action, :entity_type, :entity_id, :metadata, :ip, :ua, :created_at)',
            [
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
                'ip' => $request->ip,
                'ua' => substr($request->userAgent, 0, 512),
                'created_at' => date('Y-m-d H:i:s'),
            ],
        );
    }
}
