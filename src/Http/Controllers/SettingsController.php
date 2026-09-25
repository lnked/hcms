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

    public function security(): Response
    {
        return Response::data($this->securitySettings());
    }

    public function update(Request $request, AuthContext $context): Response
    {
        $payload = $request->json();
        $hasLanguage = \array_key_exists('language', $payload);
        $hasApiAccess = \array_key_exists('apiAccess', $payload);
        $hasAdminBase = \array_key_exists('adminBase', $payload);
        $hasAdminSections = \array_key_exists('adminSections', $payload);
        $hasHomeSection = \array_key_exists('homeSection', $payload);
        $hasSecurity = \array_key_exists('security', $payload);

        if (
            !$hasLanguage
            && !$hasApiAccess
            && !$hasAdminBase
            && !$hasAdminSections
            && !$hasHomeSection
            && !$hasSecurity
        ) {
            return Response::error('VALIDATION_ERROR', 'Validation failed', 422, [
                'language' => ['Provide language, apiAccess, adminBase, adminSections, homeSection, and/or security'],
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

        if ($hasSecurity) {
            $role = isset($context->user['role']) ? (string) $context->user['role'] : '';
            if ($role !== 'owner') {
                return Response::error('FORBIDDEN', 'Only the owner can change security settings', 403);
            }
            if (!\is_array($payload['security'])) {
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, [
                    'security' => ['Must be an object'],
                ]);
            }
            $validated = $this->validateSecurityPayload($payload['security']);
            if ($validated['ok'] === false) {
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $validated['error']);
            }
            foreach ($validated['value'] as $key => $value) {
                $this->settings->set($key, $value);
            }
            $out['security'] = $this->securitySettings();
        }

        return Response::data($out);
    }

    /**
     * @return array{
     *   ipAutoBlockAfterSpamRejects: int,
     *   ipAutoBlockAfterLoginBlocks: int,
     *   ipAutoBlockWindowSeconds: int,
     *   ipAutoBlockTtlSeconds: int
     * }
     */
    private function securitySettings(): array
    {
        return [
            'ipAutoBlockAfterSpamRejects' => max(0, $this->settings->int('security.ip_auto_block_after_spam_rejects', 0)),
            'ipAutoBlockAfterLoginBlocks' => max(0, $this->settings->int('security.ip_auto_block_after_login_blocks', 3)),
            'ipAutoBlockWindowSeconds' => max(60, $this->settings->int('security.ip_auto_block_window_seconds', 3600)),
            'ipAutoBlockTtlSeconds' => max(60, $this->settings->int('security.ip_auto_block_ttl_seconds', 3600)),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: true, value: array<string, int>}|array{ok: false, error: array<string, list<string>>}
     */
    private function validateSecurityPayload(array $payload): array
    {
        $map = [
            'ipAutoBlockAfterSpamRejects' => ['key' => 'security.ip_auto_block_after_spam_rejects', 'min' => 0],
            'ipAutoBlockAfterLoginBlocks' => ['key' => 'security.ip_auto_block_after_login_blocks', 'min' => 0],
            'ipAutoBlockWindowSeconds' => ['key' => 'security.ip_auto_block_window_seconds', 'min' => 60],
            'ipAutoBlockTtlSeconds' => ['key' => 'security.ip_auto_block_ttl_seconds', 'min' => 60],
        ];
        $out = [];
        $errors = [];
        foreach ($map as $field => $meta) {
            if (!\array_key_exists($field, $payload)) {
                continue;
            }
            $raw = $payload[$field];
            if (!is_numeric($raw) || (int) $raw != $raw) {
                $errors[$field] = ['Must be an integer'];
                continue;
            }
            $value = (int) $raw;
            if ($value < $meta['min']) {
                $errors[$field] = ['Must be >= ' . $meta['min']];
                continue;
            }
            $out[$meta['key']] = $value;
        }
        if ($errors !== []) {
            return ['ok' => false, 'error' => $errors];
        }
        if ($out === []) {
            return ['ok' => false, 'error' => ['security' => ['Provide at least one security field']]];
        }

        return ['ok' => true, 'value' => $out];
    }
}
