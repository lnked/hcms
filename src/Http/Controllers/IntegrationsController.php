<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Mail\EmailIntegration;
use Cms\Mail\Mailer;
use Cms\Mail\MailProviderException;
use InvalidArgumentException;
use Throwable;

final class IntegrationsController
{
    public function __construct(
        private readonly EmailIntegration $email,
        private readonly Mailer $mailer,
        private readonly AuditLogger $audit,
    ) {
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
        if (!is_string($to)) {
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
}
