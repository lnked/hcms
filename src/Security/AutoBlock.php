<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Audit\AuditLogger;
use Cms\Core\Settings;
use Cms\Http\Request;

/**
 * Auto-block IPs after repeated audit signals (login blocks, spam rejects).
 */
final class AutoBlock
{
    public function __construct(
        private readonly IpBlockRepository $ipBlocks,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {
    }

    public function maybeBlock(Request $request, string $action, int $threshold, string $reason): void
    {
        if ($threshold <= 0) {
            return;
        }
        $window = max(60, $this->settings->int('security.ip_auto_block_window_seconds', 3600));
        $count = $this->ipBlocks->countAuditActions($request->ip, $action, $window);
        if ($count < $threshold) {
            return;
        }
        $ttl = max(60, $this->settings->int('security.ip_auto_block_ttl_seconds', 3600));
        $expires = date('Y-m-d H:i:s', time() + $ttl);
        $this->ipBlocks->block($request->ip, $reason, $expires, null);
        $this->audit->log($request, 'security.ip_blocked', null, 'ip', $request->ip, [
            'reason' => $reason,
            'expiresAt' => $expires,
        ]);
    }
}
