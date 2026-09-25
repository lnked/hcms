<?php

declare(strict_types=1);

namespace Cms\Http;

use Cms\Core\Settings;

/**
 * Instance-wide admin nav visibility + default landing section.
 * Owner toggles on System page. Locked sections cannot be hidden.
 */
final class AdminUiSections
{
    public const DEFAULT_HOME = 'dashboard';

    /** @var list<string> */
    public const ALL = [
        'dashboard',
        'resources',
        'media',
        'logs',
        'docs',
        'changelog',
        'tokens',
        'webhooks',
        'inbound',
        'uptime',
        'feature-flags',
        'key-values',
        'translates',
        'users',
        'integrations',
        'system',
        'backups',
        'account',
    ];

    /** @var list<string> */
    public const LOCKED = ['system', 'account'];

    /**
     * @param array<string, bool> $enabled section id → visible
     */
    public function __construct(private readonly array $enabled)
    {
    }

    public static function defaults(): self
    {
        $map = [];
        foreach (self::ALL as $section) {
            $map[$section] = true;
        }

        return new self($map);
    }

    public static function fromSettings(Settings $settings): self
    {
        $raw = $settings->get('admin.ui.sections');

        return self::fromMap(\is_array($raw) ? $raw : null);
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    public static function fromMap(?array $raw): self
    {
        if ($raw === null) {
            return self::defaults();
        }

        $map = [];
        foreach (self::ALL as $section) {
            if (\in_array($section, self::LOCKED, true)) {
                $map[$section] = true;
                continue;
            }
            $map[$section] = \array_key_exists($section, $raw)
                ? (bool) $raw[$section]
                : true;
        }

        return new self($map);
    }

    public function isEnabled(string $section): bool
    {
        if (\in_array($section, self::LOCKED, true)) {
            return true;
        }

        return $this->enabled[$section] ?? true;
    }

    /**
     * @return list<string>
     */
    public function hidden(): array
    {
        $out = [];
        foreach (self::ALL as $section) {
            if (!$this->isEnabled($section)) {
                $out[] = $section;
            }
        }

        return $out;
    }

    public function resolveHome(string $preferred): string
    {
        if (\in_array($preferred, self::ALL, true) && $this->isEnabled($preferred)) {
            return $preferred;
        }
        foreach (self::ALL as $section) {
            if ($this->isEnabled($section)) {
                return $section;
            }
        }

        return 'account';
    }

    public static function homeFromSettings(Settings $settings): string
    {
        $preferred = $settings->string('admin.ui.home_section', self::DEFAULT_HOME);

        return self::fromSettings($settings)->resolveHome($preferred);
    }

    /**
     * @return array{sections: array<string, bool>, locked: list<string>, homeSection: string}
     */
    public function toPublicArray(string $homeSection = self::DEFAULT_HOME): array
    {
        return [
            'sections' => $this->enabled,
            'locked' => self::LOCKED,
            'homeSection' => $this->resolveHome($homeSection),
        ];
    }

    public static function publicFromSettings(Settings $settings): array
    {
        $ui = self::fromSettings($settings);

        return $ui->toPublicArray($settings->string('admin.ui.home_section', self::DEFAULT_HOME));
    }

    /**
     * @param array<string, mixed> $payload map of section → bool
     * @return array{ok: true, value: array<string, bool>}|array{ok: false, error: array<string, list<string>>}
     */
    public static function validatePayload(array $payload): array
    {
        $unknown = [];
        foreach (array_keys($payload) as $key) {
            if (!\is_string($key) || !\in_array($key, self::ALL, true)) {
                $unknown[] = (string) $key;
            }
        }
        if ($unknown !== []) {
            return ['ok' => false, 'error' => ['adminSections' => ['Unknown sections: ' . implode(', ', $unknown)]]];
        }

        $map = [];
        foreach (self::ALL as $section) {
            $map[$section] = true;
        }
        foreach (self::ALL as $section) {
            if (!\array_key_exists($section, $payload)) {
                continue;
            }
            if (!\is_bool($payload[$section])) {
                return ['ok' => false, 'error' => ['adminSections' => ["{$section} must be a boolean"]]];
            }
            if (\in_array($section, self::LOCKED, true)) {
                $map[$section] = true;
                continue;
            }
            $map[$section] = $payload[$section];
        }

        return ['ok' => true, 'value' => $map];
    }

    /**
     * @return array{ok: true, value: string}|array{ok: false, error: array<string, list<string>>}
     */
    public static function validateHomeSection(string $section, self $ui): array
    {
        if (!\in_array($section, self::ALL, true)) {
            return ['ok' => false, 'error' => ['homeSection' => ['Unknown section']]];
        }
        if (!$ui->isEnabled($section)) {
            return ['ok' => false, 'error' => ['homeSection' => ['Section is hidden — enable it first']]];
        }

        return ['ok' => true, 'value' => $section];
    }
}
