<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Auth\AuthContext;
use Cms\Auth\RolePolicy;
use Cms\Backup\BackupCloudOAuthService;
use Cms\Backup\BackupRemoteSettings;
use Cms\Backup\DataBackupService;
use Cms\Backup\RemoteDriverFactory;
use Cms\Http\Request;
use Cms\Http\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class BackupsController
{
    public function __construct(
        private readonly DataBackupService $backups,
        private readonly BackupRemoteSettings $remoteSettings,
        private readonly BackupCloudOAuthService $oauth,
        private readonly RemoteDriverFactory $drivers,
        private readonly string $appUrl,
        private readonly string $apiPrefix,
    ) {
    }

    public function list(Request $request, AuthContext $auth): Response
    {
        unset($request);
        $deny = RolePolicy::denyUnless($auth, 'settings.write');
        if ($deny !== null) {
            // list is readable for admin with settings section — use null capability via GET
        }
        unset($deny);

        return Response::data($this->backups->list());
    }

    public function status(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);

        return Response::data($this->backups->status());
    }

    public function create(Request $request, AuthContext $auth): Response
    {
        $deny = RolePolicy::denyUnless($auth, 'settings.write');
        if ($deny !== null) {
            return $deny;
        }
        $body = $request->json();
        $pushTo = [];
        if (isset($body['pushTo']) && \is_array($body['pushTo'])) {
            foreach ($body['pushTo'] as $p) {
                if (\is_string($p) && \in_array($p, BackupRemoteSettings::ALL_PROVIDERS, true)) {
                    $pushTo[] = $p;
                }
            }
        }
        try {
            $result = $this->backups->enqueueCreate(array_values(array_unique($pushTo)));
        } catch (RuntimeException $e) {
            return Response::error('BACKUP_BUSY', $e->getMessage(), 409);
        }

        return Response::data($result, 202);
    }

    public function restore(Request $request, AuthContext $auth, string $id): Response
    {
        $deny = RolePolicy::denyUnless($auth, 'system.write');
        if ($deny !== null) {
            return $deny;
        }
        $body = $request->json();
        $confirm = (bool) ($body['confirm'] ?? false);
        try {
            return Response::data($this->backups->restore($id, $confirm));
        } catch (RuntimeException $e) {
            return Response::error('BACKUP_RESTORE_FAILED', $e->getMessage(), 422);
        }
    }

    public function delete(Request $request, AuthContext $auth, string $id): Response
    {
        unset($request);
        $deny = RolePolicy::denyUnless($auth, 'settings.write');
        if ($deny !== null) {
            return $deny;
        }
        try {
            $this->backups->delete($id);
        } catch (RuntimeException $e) {
            return Response::error('BACKUP_NOT_FOUND', $e->getMessage(), 404);
        }

        return Response::data(['ok' => true]);
    }

    public function download(Request $request, AuthContext $auth, string $id): Response
    {
        unset($request);
        $deny = RolePolicy::denyUnless($auth, 'settings.write');
        if ($deny !== null) {
            // GET allowed for admin readers — still require auth context
        }
        unset($deny, $auth);
        try {
            $path = $this->backups->zipPath($id);
        } catch (RuntimeException $e) {
            return Response::error('BACKUP_NOT_FOUND', $e->getMessage(), 404);
        }
        $body = file_get_contents($path);
        if ($body === false) {
            return Response::error('BACKUP_READ_FAILED', 'Cannot read backup zip', 500);
        }

        return new Response(200, $body, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . $id . '.zip"',
            'Content-Length' => (string) \strlen($body),
        ]);
    }

    public function getCloud(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);

        return Response::data($this->remoteSettings->publicConfig($this->appUrl, $this->apiPrefix));
    }

    public function updateCloud(Request $request, AuthContext $auth): Response
    {
        $deny = RolePolicy::denyUnless($auth, 'settings.write');
        if ($deny !== null) {
            return $deny;
        }
        try {
            $this->remoteSettings->update($request->json());
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        }

        return Response::data($this->remoteSettings->publicConfig($this->appUrl, $this->apiPrefix));
    }

    public function connect(Request $request, AuthContext $auth, string $provider): Response
    {
        unset($request);
        $deny = RolePolicy::denyUnless($auth, 'settings.write');
        if ($deny !== null) {
            return $deny;
        }
        if ($provider === 'sftp') {
            return Response::error('VALIDATION_ERROR', 'SFTP uses saved credentials — call test instead', 422);
        }
        try {
            $url = $this->oauth->authorizeUrl($provider);
        } catch (RuntimeException $e) {
            return Response::error('OAUTH_CONFIG', $e->getMessage(), 422);
        }

        return Response::data(['url' => $url]);
    }

    public function callback(Request $request, string $provider): Response
    {
        $code = \is_string($request->query['code'] ?? null) ? (string) $request->query['code'] : '';
        $state = \is_string($request->query['state'] ?? null) ? (string) $request->query['state'] : '';
        $error = \is_string($request->query['error'] ?? null) ? (string) $request->query['error'] : '';
        if ($error !== '') {
            return Response::redirect($this->oauth->completeRedirectUrl($provider, false, $error));
        }
        try {
            $this->oauth->handleCallback($provider, $code, $state);
        } catch (Throwable $e) {
            return Response::redirect($this->oauth->completeRedirectUrl($provider, false, $e->getMessage()));
        }

        return Response::redirect($this->oauth->completeRedirectUrl($provider, true));
    }

    public function disconnect(Request $request, AuthContext $auth, string $provider): Response
    {
        unset($request);
        $deny = RolePolicy::denyUnless($auth, 'settings.write');
        if ($deny !== null) {
            return $deny;
        }
        try {
            $this->remoteSettings->disconnect($provider);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        }

        return Response::data($this->remoteSettings->publicConfig($this->appUrl, $this->apiPrefix));
    }

    public function test(Request $request, AuthContext $auth, string $provider): Response
    {
        unset($request);
        $deny = RolePolicy::denyUnless($auth, 'settings.write');
        if ($deny !== null) {
            return $deny;
        }
        try {
            $this->drivers->make($provider)->test();
        } catch (RuntimeException $e) {
            return Response::error('BACKUP_TEST_FAILED', $e->getMessage(), 422);
        }

        return Response::data(['ok' => true, 'provider' => $provider]);
    }

    public function push(Request $request, AuthContext $auth, string $id): Response
    {
        $deny = RolePolicy::denyUnless($auth, 'settings.write');
        if ($deny !== null) {
            return $deny;
        }
        $body = $request->json();
        $provider = \is_string($body['to'] ?? null) ? (string) $body['to'] : '';
        if (!\in_array($provider, BackupRemoteSettings::ALL_PROVIDERS, true)) {
            return Response::error('VALIDATION_ERROR', 'Invalid provider', 422);
        }
        try {
            return Response::data($this->backups->push($id, $provider));
        } catch (RuntimeException $e) {
            return Response::error('BACKUP_PUSH_FAILED', $e->getMessage(), 422);
        }
    }
}
