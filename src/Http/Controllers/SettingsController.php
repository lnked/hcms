<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Core\Locale;
use Cms\Core\Settings;
use Cms\Http\ApiAccess;
use Cms\Http\Request;
use Cms\Http\Response;

final class SettingsController
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function locale(): Response
    {
        return Response::data([
            'language' => Locale::normalize($this->settings->string('app.language', 'en')),
        ]);
    }

    public function apiAccess(): Response
    {
        return Response::data(ApiAccess::fromSettings($this->settings)->toArray());
    }

    public function update(Request $request): Response
    {
        $payload = $request->json();
        $hasLanguage = \array_key_exists('language', $payload);
        $hasApiAccess = \array_key_exists('apiAccess', $payload);

        if (!$hasLanguage && !$hasApiAccess) {
            return Response::error('VALIDATION_ERROR', 'Validation failed', 422, [
                'language' => ['Provide language and/or apiAccess'],
            ]);
        }

        $out = [];

        if ($hasLanguage) {
            if (!\is_string($payload['language'])) {
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, [
                    'language' => ['Language is required'],
                ]);
            }
            if (!Locale::isSupported($payload['language'])) {
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, [
                    'language' => ['Unsupported language'],
                ]);
            }
            $language = Locale::normalize($payload['language']);
            $this->settings->set('app.language', $language);
            $out['language'] = $language;
        }

        if ($hasApiAccess) {
            if (!\is_array($payload['apiAccess'])) {
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, [
                    'apiAccess' => ['Must be an object'],
                ]);
            }
            $validated = ApiAccess::validatePayload($payload['apiAccess']);
            if ($validated['ok'] === false) {
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $validated['error']);
            }
            $this->settings->set('api.access', $validated['value']);
            $out['apiAccess'] = $validated['value'];
        }

        return Response::data($out);
    }
}
