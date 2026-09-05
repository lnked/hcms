<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Auth\AuthContext;
use Cms\Core\Version;
use Cms\Database\Connection;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\System\ChangelogRepository;
use Cms\System\LatestRelease;
use Cms\System\UpdateService;
use RuntimeException;
use Throwable;

final class SystemController
{
    public function __construct(
        private readonly ChangelogRepository $changelog,
        private readonly LatestRelease $latest,
        private readonly Connection $db,
        private readonly ?UpdateService $updates = null,
    ) {
    }

    public function version(Request $request, AuthContext $auth): Response
    {
        unset($request);
        $current = Version::current();
        $latest = $this->latest->fetch();
        $latestVersion = is_array($latest) && isset($latest['version']) && is_string($latest['version'])
            ? $latest['version']
            : null;

        $seen = $auth->user['changelog_seen_version'] ?? null;

        return Response::json([
            'current' => $current,
            'latest' => $latestVersion,
            'updateAvailable' => $latestVersion !== null && Version::isGreater($latestVersion, $current),
            'releasedAt' => is_array($latest) ? ($latest['releasedAt'] ?? null) : null,
            'channel' => is_array($latest) ? ($latest['channel'] ?? 'stable') : 'stable',
            'changelogSeenVersion' => is_string($seen) ? $seen : null,
            'backupReady' => true,
        ]);
    }

    public function changelog(Request $request): Response
    {
        $since = $request->query('since');
        $channel = $request->query('channel');

        return Response::data($this->changelog->since($since, $channel));
    }

    public function markSeen(Request $request, AuthContext $auth): Response
    {
        $payload = $request->json();
        $version = isset($payload['version']) && is_string($payload['version']) ? $payload['version'] : '';
        if ($version === '') {
            return Response::error('VALIDATION_ERROR', 'Validation failed', 422, [
                'version' => ['Version is required'],
            ]);
        }

        $userId = $auth->userId();
        if ($userId === null) {
            return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
        }

        $this->db->execute(
            'UPDATE cms_users SET changelog_seen_version = :version WHERE id = :id',
            ['version' => $version, 'id' => $userId],
        );

        return Response::data(['changelogSeenVersion' => $version]);
    }

    public function updateCheck(Request $request, AuthContext $auth): Response
    {
        unset($auth);
        if ($this->updates === null) {
            return Response::error('SERVICE_UNAVAILABLE', 'Updater unavailable', 503);
        }
        $force = ($request->query['force'] ?? '') === '1';

        return Response::data($this->updates->check($force));
    }

    public function updatePreview(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);
        if ($this->updates === null) {
            return Response::error('SERVICE_UNAVAILABLE', 'Updater unavailable', 503);
        }

        return Response::data($this->updates->preview());
    }

    public function updateStatus(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);
        if ($this->updates === null) {
            return Response::error('SERVICE_UNAVAILABLE', 'Updater unavailable', 503);
        }

        return Response::data($this->updates->status());
    }

    public function updateRun(Request $request, AuthContext $auth): Response
    {
        unset($auth);
        if ($this->updates === null) {
            return Response::error('SERVICE_UNAVAILABLE', 'Updater unavailable', 503);
        }
        try {
            $ack = (bool) ($request->json()['acknowledgeBreaking'] ?? false);

            return Response::data($this->updates->run($ack));
        } catch (RuntimeException $e) {
            return Response::error('UPDATE_ERROR', $e->getMessage(), 400);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }
}
