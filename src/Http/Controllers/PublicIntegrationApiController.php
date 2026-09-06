<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Auth\AuthContext;
use Cms\Auth\TokenGrantRepository;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Integrations\IntegrationApiService;
use Cms\Mail\Mailer;
use Cms\Mail\MailProviderException;
use InvalidArgumentException;
use Throwable;

final class PublicIntegrationApiController
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly IntegrationApiService $apis,
        private readonly TokenGrantRepository $grants,
    ) {
    }

    public function sendEmail(Request $request, ?AuthContext $auth): Response
    {
        $denied = $this->authorize($auth);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $result = $this->mailer->sendIntegration($request->json(), [], true);

            return Response::data($result);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (MailProviderException $e) {
            return Response::error('PROVIDER_ERROR', $e->getMessage(), 502);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function sendEmailCustom(Request $request, string $slug, ?AuthContext $auth): Response
    {
        $denied = $this->authorize($auth);
        if ($denied !== null) {
            return $denied;
        }

        if ($slug === 'send') {
            return $this->sendEmail($request, $auth);
        }

        $api = $this->apis->findEnabledEmailBySlug($slug);
        if ($api === null) {
            return Response::error('NOT_FOUND', 'Integration API not found', 404);
        }

        $defaults = is_array($api['defaults'] ?? null) ? $api['defaults'] : [];
        $settings = is_array($api['settings'] ?? null) ? $api['settings'] : [];
        $allowFromOverride = (bool) ($settings['allowFromOverride'] ?? true);

        try {
            $result = $this->mailer->sendIntegration(
                $request->json(),
                [
                    'subject' => is_string($defaults['subject'] ?? null) ? $defaults['subject'] : '',
                    'html' => is_string($defaults['html'] ?? null) ? $defaults['html'] : '',
                    'text' => is_string($defaults['text'] ?? null) ? $defaults['text'] : '',
                ],
                $allowFromOverride,
            );

            return Response::data($result);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (MailProviderException $e) {
            return Response::error('PROVIDER_ERROR', $e->getMessage(), 502);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    private function authorize(?AuthContext $auth): ?Response
    {
        if ($auth === null) {
            return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
        }
        if ($auth->isAdmin()) {
            return null;
        }
        if (($auth->token['type'] ?? '') !== 'api') {
            return Response::error('FORBIDDEN', 'Forbidden', 403);
        }
        if (!$this->grants->allowsIntegration($auth->tokenId(), IntegrationApiService::EMAIL_KEY)) {
            return Response::error('FORBIDDEN', 'Forbidden', 403);
        }

        return null;
    }
}
