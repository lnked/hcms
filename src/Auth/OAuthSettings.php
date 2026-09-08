<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Core\Settings;
use InvalidArgumentException;

/**
 * @phpstan-type GoogleConfig array{enabled: bool, clientId: string, clientSecret: string}
 * @phpstan-type TelegramConfig array{enabled: bool, botUsername: string, botToken: string}
 */
final class OAuthSettings
{
    public const GOOGLE_KEY = 'auth.google';
    public const TELEGRAM_KEY = 'auth.telegram';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function ensureDefaults(): void
    {
        if ($this->settings->get(self::GOOGLE_KEY) === null) {
            $this->settings->set(self::GOOGLE_KEY, $this->googleDefaults());
        }
        if ($this->settings->get(self::TELEGRAM_KEY) === null) {
            $this->settings->set(self::TELEGRAM_KEY, $this->telegramDefaults());
        }
    }

    /**
     * @return GoogleConfig
     */
    public function google(): array
    {
        return $this->readGoogle($this->settings->get(self::GOOGLE_KEY));
    }

    /**
     * @return TelegramConfig
     */
    public function telegram(): array
    {
        return $this->readTelegram($this->settings->get(self::TELEGRAM_KEY));
    }

    /**
     * @return array{
     *   google: array{
     *     enabled: bool,
     *     clientId: string,
     *     clientSecretConfigured: bool,
     *     clientSecretMasked: string|null,
     *     redirectUri: string
     *   },
     *   telegram: array{
     *     enabled: bool,
     *     botUsername: string,
     *     botTokenConfigured: bool,
     *     botTokenMasked: string|null
     *   }
     * }
     */
    public function publicConfig(string $appUrl): array
    {
        $this->ensureDefaults();
        $google = $this->google();
        $telegram = $this->telegram();
        $secretConfigured = $google['clientSecret'] !== '';
        $tokenConfigured = $telegram['botToken'] !== '';

        return [
            'google' => [
                'enabled' => $google['enabled'],
                'clientId' => $google['clientId'],
                'clientSecretConfigured' => $secretConfigured,
                'clientSecretMasked' => $secretConfigured ? $this->mask($google['clientSecret']) : null,
                'redirectUri' => rtrim($appUrl, '/') . '/admin/api/auth/google/callback',
            ],
            'telegram' => [
                'enabled' => $telegram['enabled'],
                'botUsername' => $telegram['botUsername'],
                'botTokenConfigured' => $tokenConfigured,
                'botTokenMasked' => $tokenConfigured ? $this->mask($telegram['botToken']) : null,
            ],
        ];
    }

    /**
     * @return array{google: array{enabled: bool, clientId: string}, telegram: array{enabled: bool, botUsername: string}}
     */
    public function publicProviders(): array
    {
        $this->ensureDefaults();
        $google = $this->google();
        $telegram = $this->telegram();
        $googleReady = $google['enabled'] && $google['clientId'] !== '' && $google['clientSecret'] !== '';
        $telegramReady = $telegram['enabled'] && $telegram['botUsername'] !== '' && $telegram['botToken'] !== '';

        return [
            'google' => [
                'enabled' => $googleReady,
                'clientId' => $googleReady ? $google['clientId'] : '',
            ],
            'telegram' => [
                'enabled' => $telegramReady,
                'botUsername' => $telegramReady ? $telegram['botUsername'] : '',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   google: array{
     *     enabled: bool,
     *     clientId: string,
     *     clientSecretConfigured: bool,
     *     clientSecretMasked: string|null,
     *     redirectUri: string
     *   },
     *   telegram: array{
     *     enabled: bool,
     *     botUsername: string,
     *     botTokenConfigured: bool,
     *     botTokenMasked: string|null
     *   }
     * }
     */
    public function update(array $payload, string $appUrl): array
    {
        $this->ensureDefaults();
        if (isset($payload['google']) && is_array($payload['google'])) {
            $current = $this->google();
            $incoming = $payload['google'];
            if (array_key_exists('enabled', $incoming)) {
                $current['enabled'] = (bool) $incoming['enabled'];
            }
            if (isset($incoming['clientId']) && is_string($incoming['clientId'])) {
                $current['clientId'] = trim($incoming['clientId']);
            }
            if (isset($incoming['clientSecret']) && is_string($incoming['clientSecret']) && $incoming['clientSecret'] !== '') {
                $current['clientSecret'] = trim($incoming['clientSecret']);
            }
            if ($current['enabled'] && ($current['clientId'] === '' || $current['clientSecret'] === '')) {
                throw new InvalidArgumentException('Google client ID and secret are required when enabled');
            }
            $this->settings->set(self::GOOGLE_KEY, $current);
        }
        if (isset($payload['telegram']) && is_array($payload['telegram'])) {
            $current = $this->telegram();
            $incoming = $payload['telegram'];
            if (array_key_exists('enabled', $incoming)) {
                $current['enabled'] = (bool) $incoming['enabled'];
            }
            if (isset($incoming['botUsername']) && is_string($incoming['botUsername'])) {
                $current['botUsername'] = ltrim(trim($incoming['botUsername']), '@');
            }
            if (isset($incoming['botToken']) && is_string($incoming['botToken']) && $incoming['botToken'] !== '') {
                $current['botToken'] = trim($incoming['botToken']);
            }
            if ($current['enabled'] && ($current['botUsername'] === '' || $current['botToken'] === '')) {
                throw new InvalidArgumentException('Telegram bot username and token are required when enabled');
            }
            $this->settings->set(self::TELEGRAM_KEY, $current);
        }

        return $this->publicConfig($appUrl);
    }

    /**
     * @return GoogleConfig
     */
    public function googleDefaults(): array
    {
        return [
            'enabled' => false,
            'clientId' => '',
            'clientSecret' => '',
        ];
    }

    /**
     * @return TelegramConfig
     */
    public function telegramDefaults(): array
    {
        return [
            'enabled' => false,
            'botUsername' => '',
            'botToken' => '',
        ];
    }

    /**
     * @return GoogleConfig
     */
    private function readGoogle(mixed $stored): array
    {
        $defaults = $this->googleDefaults();
        if (!is_array($stored)) {
            return $defaults;
        }

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'clientId' => is_string($stored['clientId'] ?? null) ? trim($stored['clientId']) : '',
            'clientSecret' => is_string($stored['clientSecret'] ?? null) ? $stored['clientSecret'] : '',
        ];
    }

    /**
     * @return TelegramConfig
     */
    private function readTelegram(mixed $stored): array
    {
        $defaults = $this->telegramDefaults();
        if (!is_array($stored)) {
            return $defaults;
        }

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'botUsername' => is_string($stored['botUsername'] ?? null)
                ? ltrim(trim($stored['botUsername']), '@')
                : '',
            'botToken' => is_string($stored['botToken'] ?? null) ? $stored['botToken'] : '',
        ];
    }

    private function mask(string $secret): string
    {
        $len = strlen($secret);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }

        return '••••' . substr($secret, -4);
    }
}
