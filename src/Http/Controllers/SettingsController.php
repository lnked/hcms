<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Auth\AuthContext;
use Cms\Core\AdminBase;
use Cms\Core\EnvFile;
use Cms\Core\Locale;
use Cms\Core\Paths;
use Cms\Core\Settings;
use Cms\Http\AdminUiSections;
use Cms\Http\ApiAccess;
use Cms\Http\Request;
use Cms\Http\Response;
use RuntimeException;

final class SettingsController
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Paths $paths,
        private readonly AdminBase $adminBase,
    ) {
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

    public function adminBase(): Response
    {
        return Response::data($this->adminBase->toPublicArray());
    }

    public function adminSections(): Response
    {
        return Response::data(AdminUiSections::publicFromSettings($this->settings));
    }

    public function update(Request $request, AuthContext $context): Response
    {
        $payload = $request->json();
        $hasLanguage = \array_key_exists('language', $payload);
        $hasApiAccess = \array_key_exists('apiAccess', $payload);
        $hasAdminBase = \array_key_exists('adminBase', $payload);
        $hasAdminSections = \array_key_exists('adminSections', $payload);
        $hasHomeSection = \array_key_exists('homeSection', $payload);

        if (!$hasLanguage && !$hasApiAccess && !$hasAdminBase && !$hasAdminSections && !$hasHomeSection) {
            return Response::error('VALIDATION_ERROR', 'Validation failed', 422, [
                'language' => ['Provide language, apiAccess, adminBase, adminSections, and/or homeSection'],
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

        if ($hasAdminBase) {
            $role = isset($context->user['role']) ? (string) $context->user['role'] : '';
            if ($role !== 'owner') {
                return Response::error('FORBIDDEN', 'Only the owner can change the admin base path', 403);
            }
            if (!\is_string($payload['adminBase'])) {
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, [
                    'adminBase' => ['Must be a string (e.g. admin, panel, or empty for root)'],
                ]);
            }
            $parsed = AdminBase::tryNormalize($payload['adminBase']);
            if ($parsed['ok'] === false) {
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, [
                    'adminBase' => [$parsed['error']],
                ]);
            }
            $next = AdminBase::fromRaw($payload['adminBase']);
            try {
                EnvFile::upsert($this->paths->envFile(), 'CMS_ADMIN_BASE', $next->segment());
            } catch (RuntimeException $e) {
                return Response::error('ERROR', $e->getMessage(), 500);
            }
            $this->settings->set('app.admin_base', $next->segment());
            $out['adminBase'] = $next->toPublicArray();
        }

        if ($hasAdminSections) {
            $role = isset($context->user['role']) ? (string) $context->user['role'] : '';
            if ($role !== 'owner') {
                return Response::error('FORBIDDEN', 'Only the owner can change admin UI sections', 403);
            }
            if (!\is_array($payload['adminSections'])) {
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, [
                    'adminSections' => ['Must be an object of section → boolean'],
                ]);
            }
            $validated = AdminUiSections::validatePayload($payload['adminSections']);
            if ($validated['ok'] === false) {
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $validated['error']);
            }
            $ui = new AdminUiSections($validated['value']);
            $this->settings->set('admin.ui.sections', $validated['value']);
            $home = $this->settings->string('admin.ui.home_section', AdminUiSections::DEFAULT_HOME);
            if (!$ui->isEnabled($home)) {
                $home = $ui->resolveHome(AdminUiSections::DEFAULT_HOME);
                $this->settings->set('admin.ui.home_section', $home);
            }
            $out['adminSections'] = $ui->toPublicArray($home);
        }

        if ($hasHomeSection) {
            $role = isset($context->user['role']) ? (string) $context->user['role'] : '';
            if ($role !== 'owner') {
                return Response::error('FORBIDDEN', 'Only the owner can change the home section', 403);
            }
            if (!\is_string($payload['homeSection'])) {
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, [
                    'homeSection' => ['Must be a section id string'],
                ]);
            }
            $ui = AdminUiSections::fromSettings($this->settings);
            $validatedHome = AdminUiSections::validateHomeSection($payload['homeSection'], $ui);
            if ($validatedHome['ok'] === false) {
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $validatedHome['error']);
            }
            $this->settings->set('admin.ui.home_section', $validatedHome['value']);
            $out['homeSection'] = $validatedHome['value'];
            $out['adminSections'] = $ui->toPublicArray($validatedHome['value']);
        }

        return Response::data($out);
    }
}
