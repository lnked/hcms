<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Core\Locale;
use Cms\Core\Settings;
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

    public function update(Request $request): Response
    {
        $payload = $request->json();
        if (!isset($payload['language']) || !is_string($payload['language'])) {
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

        return Response::data(['language' => $language]);
    }
}
