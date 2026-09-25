<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Core\AdminBase;
use Cms\Core\Settings;
use InvalidArgumentException;

/**
 * @phpstan-type GoogleConfig array{enabled: bool, clientId: string, clientSecret: string}
 * @phpstan-type TelegramConfig array{enabled: bool, botUsername: string, botToken: string}
 * @phpstan-type OidcConfig array{
 *   enabled: bool,
 *   issuer: string,
 *   clientId: string,
 *   clientSecret: string,
 *   scopes: string,
 *   claimEmail: string,
 *   claimSub: string,
 *   authorizationEndpoint: string,
 *   tokenEndpoint: string,
 *   userinfoEndpoint: string
 * }
 */
final class OAuthSettings
{
    public const GOOGLE_KEY = 'auth.google';
    public const TELEGRAM_KEY = 'auth.telegram';
    public const OIDC_KEY = 'auth.oidc';

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
        if ($this->settings->get(self::OIDC_KEY) === null) {
            $this->settings->set(self::OIDC_KEY, $this->oidcDefaults());
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
     * @return OidcConfig
     */
    public function oidc(): array
    {
        return $this->readOidc($this->settings->get(self::OIDC_KEY));
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
     *   },
     *   oidc: array{
     *     enabled: bool,
     *     issuer: string,
     *     clientId: string,
     *     clientSecretConfigured: bool,
     *     clientSecretMasked: string|null,
     *     scopes: string,
     *     claimEmail: string,
     *     claimSub: string,
     *     authorizationEndpoint: string,
     *     tokenEndpoint: string,
     *     userinfoEndpoint: string,
     *     redirectUri: string
     *   }
     * }
     */
    public function publicConfig(string $appUrl, ?AdminBase $adminBase = null): array
    {
        $this->ensureDefaults();
        $google = $this->google();
        $telegram = $this->telegram();
        $oidc = $this->oidc();
        $secretConfigured = $google['clientSecret'] !== '';
        $tokenConfigured = $telegram['botToken'] !== '';
        $oidcSecretConfigured = $oidc['clientSecret'] !== '';
        $base = $adminBase ?? AdminBase::default();

        return [
            'google' => [
                'enabled' => $google['enabled'],
                'clientId' => $google['clientId'],
                'clientSecretConfigured' => $secretConfigured,
                'clientSecretMasked' => $secretConfigured ? $this->mask($google['clientSecret']) : null,
                'redirectUri' => rtrim($appUrl, '/') . $base->apiPrefix() . '/auth/google/callback',
            ],
            'telegram' => [
                'enabled' => $telegram['enabled'],
                'botUsername' => $telegram['botUsername'],
                'botTokenConfigured' => $tokenConfigured,
                'botTokenMasked' => $tokenConfigured ? $this->mask($telegram['botToken']) : null,
            ],
            'oidc' => [
                'enabled' => $oidc['enabled'],
                'issuer' => $oidc['issuer'],
                'clientId' => $oidc['clientId'],
                'clientSecretConfigured' => $oidcSecretConfigured,
                'clientSecretMasked' => $oidcSecretConfigured ? $this->mask($oidc['clientSecret']) : null,
                'scopes' => $oidc['scopes'],
                'claimEmail' => $oidc['claimEmail'],
                'claimSub' => $oidc['claimSub'],
                'authorizationEndpoint' => $oidc['authorizationEndpoint'],
                'tokenEndpoint' => $oidc['tokenEndpoint'],
                'userinfoEndpoint' => $oidc['userinfoEndpoint'],
                'redirectUri' => rtrim($appUrl, '/') . $base->apiPrefix() . '/auth/oidc/callback',
            ],
        ];
    }

    /**
     * @return array{
     *   google: array{enabled: bool, clientId: string},
     *   telegram: array{enabled: bool, botUsername: string},
     *   oidc: array{enabled: bool, label: string}
     * }
     */
    public function publicProviders(): array
    {
        $this->ensureDefaults();
        $google = $this->google();
        $telegram = $this->telegram();
        $oidc = $this->oidc();
        $googleReady = $google['enabled'] && $google['clientId'] !== '' && $google['clientSecret'] !== '';
        $telegramReady = $telegram['enabled'] && $telegram['botUsername'] !== '' && $telegram['botToken'] !== '';
        $oidcReady = $oidc['enabled']
            && $oidc['clientId'] !== ''
            && $oidc['clientSecret'] !== ''
            && ($oidc['issuer'] !== ''
                || ($oidc['authorizationEndpoint'] !== ''
                    && $oidc['tokenEndpoint'] !== ''
                    && $oidc['userinfoEndpoint'] !== ''));

        return [
            'google' => [
                'enabled' => $googleReady,
                'clientId' => $googleReady ? $google['clientId'] : '',
            ],
            'telegram' => [
                'enabled' => $telegramReady,
                'botUsername' => $telegramReady ? $telegram['botUsername'] : '',
            ],
            'oidc' => [
                'enabled' => $oidcReady,
                'label' => 'SSO',
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
     *   },
     *   oidc: array{
     *     enabled: bool,
     *     issuer: string,
     *     clientId: string,
     *     clientSecretConfigured: bool,
     *     clientSecretMasked: string|null,
     *     scopes: string,
     *     claimEmail: string,
     *     claimSub: string,
     *     authorizationEndpoint: string,
     *     tokenEndpoint: string,
     *     userinfoEndpoint: string,
     *     redirectUri: string
     *   }
     * }
     */
    public function update(array $payload, string $appUrl, ?AdminBase $adminBase = null): array
    {
        $this->ensureDefaults();
        if (isset($payload['google']) && \is_array($payload['google'])) {
            $current = $this->google();
            $incoming = $payload['google'];
            if (\array_key_exists('enabled', $incoming)) {
                $current['enabled'] = (bool) $incoming['enabled'];
            }
            if (isset($incoming['clientId']) && \is_string($incoming['clientId'])) {
                $current['clientId'] = trim($incoming['clientId']);
            }
            if (isset($incoming['clientSecret']) && \is_string($incoming['clientSecret']) && $incoming['clientSecret'] !== '') {
                $current['clientSecret'] = trim($incoming['clientSecret']);
            }
            if ($current['enabled'] && ($current['clientId'] === '' || $current['clientSecret'] === '')) {
                throw new InvalidArgumentException('Google client ID and secret are required when enabled');
            }
            $this->settings->set(self::GOOGLE_KEY, $current);
        }
        if (isset($payload['telegram']) && \is_array($payload['telegram'])) {
            $current = $this->telegram();
            $incoming = $payload['telegram'];
            if (\array_key_exists('enabled', $incoming)) {
                $current['enabled'] = (bool) $incoming['enabled'];
            }
            if (isset($incoming['botUsername']) && \is_string($incoming['botUsername'])) {
                $current['botUsername'] = ltrim(trim($incoming['botUsername']), '@');
            }
            if (isset($incoming['botToken']) && \is_string($incoming['botToken']) && $incoming['botToken'] !== '') {
                $current['botToken'] = trim($incoming['botToken']);
            }
            if ($current['enabled'] && ($current['botUsername'] === '' || $current['botToken'] === '')) {
                throw new InvalidArgumentException('Telegram bot username and token are required when enabled');
            }
            $this->settings->set(self::TELEGRAM_KEY, $current);
        }
        if (isset($payload['oidc']) && \is_array($payload['oidc'])) {
            $current = $this->oidc();
            $incoming = $payload['oidc'];
            if (\array_key_exists('enabled', $incoming)) {
                $current['enabled'] = (bool) $incoming['enabled'];
            }
            foreach (['issuer', 'clientId', 'scopes', 'claimEmail', 'claimSub', 'authorizationEndpoint', 'tokenEndpoint', 'userinfoEndpoint'] as $key) {
                if (isset($incoming[$key]) && \is_string($incoming[$key])) {
                    $current[$key] = trim($incoming[$key]);
                }
            }
            if (isset($incoming['clientSecret']) && \is_string($incoming['clientSecret']) && $incoming['clientSecret'] !== '') {
                $current['clientSecret'] = trim($incoming['clientSecret']);
            }
            if ($current['scopes'] === '') {
                $current['scopes'] = 'openid email profile';
            }
            if ($current['claimEmail'] === '') {
                $current['claimEmail'] = 'email';
            }
            if ($current['claimSub'] === '') {
                $current['claimSub'] = 'sub';
            }
            if ($current['enabled']) {
                if ($current['clientId'] === '' || $current['clientSecret'] === '') {
                    throw new InvalidArgumentException('OIDC client ID and secret are required when enabled');
                }
                $hasIssuer = $current['issuer'] !== '';
                $hasEndpoints = $current['authorizationEndpoint'] !== ''
                    && $current['tokenEndpoint'] !== ''
                    && $current['userinfoEndpoint'] !== '';
                if (!$hasIssuer && !$hasEndpoints) {
                    throw new InvalidArgumentException(
                        'OIDC issuer or authorization/token/userinfo endpoints are required when enabled',
                    );
                }
            }
            $this->settings->set(self::OIDC_KEY, $current);
        }

        return $this->publicConfig($appUrl, $adminBase);
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
     * @return OidcConfig
     */
    public function oidcDefaults(): array
    {
        return [
            'enabled' => false,
            'issuer' => '',
            'clientId' => '',
            'clientSecret' => '',
            'scopes' => 'openid email profile',
            'claimEmail' => 'email',
            'claimSub' => 'sub',
            'authorizationEndpoint' => '',
            'tokenEndpoint' => '',
            'userinfoEndpoint' => '',
        ];
    }

    /**
     * @return GoogleConfig
     */
    private function readGoogle(mixed $stored): array
    {
        $defaults = $this->googleDefaults();
        if (!\is_array($stored)) {
            return $defaults;
        }

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'clientId' => \is_string($stored['clientId'] ?? null) ? trim($stored['clientId']) : '',
            'clientSecret' => \is_string($stored['clientSecret'] ?? null) ? $stored['clientSecret'] : '',
        ];
    }

    /**
     * @return TelegramConfig
     */
    private function readTelegram(mixed $stored): array
    {
        $defaults = $this->telegramDefaults();
        if (!\is_array($stored)) {
            return $defaults;
        }

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'botUsername' => \is_string($stored['botUsername'] ?? null)
                ? ltrim(trim($stored['botUsername']), '@')
                : '',
            'botToken' => \is_string($stored['botToken'] ?? null) ? $stored['botToken'] : '',
        ];
    }

    /**
     * @return OidcConfig
     */
    private function readOidc(mixed $stored): array
    {
        $defaults = $this->oidcDefaults();
        if (!\is_array($stored)) {
            return $defaults;
        }

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'issuer' => \is_string($stored['issuer'] ?? null) ? rtrim(trim($stored['issuer']), '/') : '',
            'clientId' => \is_string($stored['clientId'] ?? null) ? trim($stored['clientId']) : '',
            'clientSecret' => \is_string($stored['clientSecret'] ?? null) ? $stored['clientSecret'] : '',
            'scopes' => \is_string($stored['scopes'] ?? null) && trim($stored['scopes']) !== ''
                ? trim($stored['scopes'])
                : 'openid email profile',
            'claimEmail' => \is_string($stored['claimEmail'] ?? null) && trim($stored['claimEmail']) !== ''
                ? trim($stored['claimEmail'])
                : 'email',
            'claimSub' => \is_string($stored['claimSub'] ?? null) && trim($stored['claimSub']) !== ''
                ? trim($stored['claimSub'])
                : 'sub',
            'authorizationEndpoint' => \is_string($stored['authorizationEndpoint'] ?? null)
                ? trim($stored['authorizationEndpoint'])
                : '',
            'tokenEndpoint' => \is_string($stored['tokenEndpoint'] ?? null)
                ? trim($stored['tokenEndpoint'])
                : '',
            'userinfoEndpoint' => \is_string($stored['userinfoEndpoint'] ?? null)
                ? trim($stored['userinfoEndpoint'])
                : '',
        ];
    }

    private function mask(string $secret): string
    {
        $len = \strlen($secret);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }

        return '••••' . substr($secret, -4);
    }
}
