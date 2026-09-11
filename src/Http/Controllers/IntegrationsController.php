<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Auth\OAuthSettings;
use Cms\Core\AdminBase;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Integrations\IntegrationApiService;
use Cms\Mail\EmailIntegration;
use Cms\Mail\Mailer;
use Cms\Mail\MailProviderException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class IntegrationsController
{
    private readonly AdminBase $adminBase;

    public function __construct(
        private readonly EmailIntegration $email,
        private readonly Mailer $mailer,
        private readonly IntegrationApiService $apis,
        private readonly AuditLogger $audit,
        private readonly OAuthSettings $oauth,
        private readonly string $appUrl,
        ?AdminBase $adminBase = null,
    ) {
        $this->adminBase = $adminBase ?? AdminBase::default();
    }

    public function getEmail(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);
        $this->email->ensureDefaults();

        return Response::data($this->email->publicConfig());
    }

    public function updateEmail(Request $request, AuthContext $auth): Response
    {
        try {
            $this->email->ensureDefaults();
            $updated = $this->email->update($request->json());
            $this->audit->log(
                $request,
                'integration.email.updated',
                $auth->userId(),
                'integration',
                'email',
                [
                    'provider' => $updated['provider'],
                    'enabled' => $updated['enabled'],
                    'apiKeyConfigured' => $updated['apiKeyConfigured'],
                ],
            );

            return Response::data($updated);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function testEmail(Request $request, AuthContext $auth): Response
    {
        $payload = $request->json();
        $to = $payload['to'] ?? null;
        if (!\is_string($to)) {
            return Response::error('VALIDATION_ERROR', 'Validation failed', 422, [
                'to' => ['to must be a valid email'],
            ]);
        }

        try {
            $this->email->ensureDefaults();
            $this->mailer->sendTest($to);
            $this->audit->log(
                $request,
                'integration.email.test',
                $auth->userId(),
                'integration',
                'email',
                ['to' => $to],
            );

            return Response::data(['ok' => true, 'to' => $to]);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (MailProviderException $e) {
            return Response::error('PROVIDER_ERROR', $e->getMessage(), 502);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function listEmailApis(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);

        return Response::data($this->apis->listEmail());
    }

    public function getEmailApi(Request $request, AuthContext $auth, int $id): Response
    {
        unset($request, $auth);
        try {
            return Response::data($this->apis->getEmail($id));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function createEmailApi(Request $request, AuthContext $auth): Response
    {
        try {
            $created = $this->apis->createEmail($request->json());
            $this->audit->log(
                $request,
                'integration.email.api.created',
                $auth->userId(),
                'integration_api',
                (string) $created['id'],
                ['slug' => $created['slug']],
            );

            return Response::data($created, 201);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function updateEmailApi(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $updated = $this->apis->updateEmail($id, $request->json());
            $this->audit->log(
                $request,
                'integration.email.api.updated',
                $auth->userId(),
                'integration_api',
                (string) $id,
                ['slug' => $updated['slug']],
            );

            return Response::data($updated);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function deleteEmailApi(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $this->apis->deleteEmail($id);
            $this->audit->log(
                $request,
                'integration.email.api.deleted',
                $auth->userId(),
                'integration_api',
                (string) $id,
            );

            return new Response(204, '');
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function getOauth(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);
        $this->oauth->ensureDefaults();

        return Response::data($this->oauth->publicConfig($this->appUrl, $this->adminBase));
    }

    public function updateOauth(Request $request, AuthContext $auth): Response
    {
        try {
            $this->oauth->ensureDefaults();
            $updated = $this->oauth->update($request->json(), $this->appUrl, $this->adminBase);
            $this->audit->log(
                $request,
                'integration.oauth.updated',
                $auth->userId(),
                'integration',
                'oauth',
                [
                    'googleEnabled' => $updated['google']['enabled'],
                    'telegramEnabled' => $updated['telegram']['enabled'],
                ],
            );

            return Response::data($updated);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }
}
