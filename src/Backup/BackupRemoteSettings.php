<?php

declare(strict_types=1);

namespace Cms\Backup;

use Cms\Core\Settings;
use InvalidArgumentException;

/**
 * Reads/writes backups.remote in cms_settings.
 *
 * @phpstan-type OauthProvider array{
 *   enabled: bool,
 *   clientId: string,
 *   clientSecret: string,
 *   refreshToken: string,
 *   accountLabel: string
 * }
 * @phpstan-type SftpConfig array{
 *   enabled: bool,
 *   host: string,
 *   port: int,
 *   username: string,
 *   auth: string,
 *   password: string,
 *   privateKey: string,
 *   passphrase: string,
 *   remotePath: string,
 *   insecureHostKey: bool,
 *   timeoutSec: int
 * }
 * @phpstan-type RemoteConfig array{
 *   retention: int,
 *   google: OauthProvider,
 *   yandex: OauthProvider,
 *   dropbox: OauthProvider,
 *   sftp: SftpConfig
 * }
 */
final class BackupRemoteSettings
{
    public const SETTING_KEY = 'backups.remote';

    /** @var list<string> */
    public const OAUTH_PROVIDERS = ['google', 'yandex', 'dropbox'];

    /** @var list<string> */
    public const ALL_PROVIDERS = ['google', 'yandex', 'dropbox', 'sftp'];

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @return RemoteConfig
     */
    public function raw(): array
    {
        $stored = $this->settings->get(self::SETTING_KEY);
        $defaults = $this->defaults();
        if (!\is_array($stored)) {
            return $defaults;
        }

        return [
            'retention' => max(1, min(100, (int) ($stored['retention'] ?? $defaults['retention']))),
            'google' => $this->readOauth($stored, 'google', $defaults['google']),
            'yandex' => $this->readOauth($stored, 'yandex', $defaults['yandex']),
            'dropbox' => $this->readOauth($stored, 'dropbox', $defaults['dropbox']),
            'sftp' => $this->readSftp($stored, $defaults['sftp']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicConfig(string $appUrl, string $apiPrefix): array
    {
        $config = $this->raw();
        $providers = [];
        foreach (self::OAUTH_PROVIDERS as $id) {
            $p = $config[$id];
            $providers[$id] = [
                'enabled' => $p['enabled'],
                'clientId' => $p['clientId'],
                'clientSecretConfigured' => $p['clientSecret'] !== '',
                'clientSecretMasked' => $this->mask($p['clientSecret']),
                'connected' => $p['refreshToken'] !== '',
                'accountLabel' => $p['accountLabel'],
                'redirectUri' => rtrim($appUrl, '/') . $apiPrefix . '/backups/cloud/' . $id . '/callback',
            ];
        }

        $sftp = $config['sftp'];
        $providers['sftp'] = [
            'enabled' => $sftp['enabled'],
            'host' => $sftp['host'],
            'port' => $sftp['port'],
            'username' => $sftp['username'],
            'auth' => $sftp['auth'],
            'passwordConfigured' => $sftp['password'] !== '',
            'passwordMasked' => $this->mask($sftp['password']),
            'privateKeyConfigured' => $sftp['privateKey'] !== '',
            'privateKeyMasked' => $this->mask($sftp['privateKey']),
            'passphraseConfigured' => $sftp['passphrase'] !== '',
            'remotePath' => $sftp['remotePath'],
            'insecureHostKey' => $sftp['insecureHostKey'],
            'timeoutSec' => $sftp['timeoutSec'],
            'connected' => $sftp['enabled']
                && $sftp['host'] !== ''
                && $sftp['username'] !== ''
                && (
                    ($sftp['auth'] === 'password' && $sftp['password'] !== '')
                    || ($sftp['auth'] === 'privateKey' && $sftp['privateKey'] !== '')
                ),
        ];

        return [
            'retention' => $config['retention'],
            'providers' => $providers,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(array $payload): void
    {
        $current = $this->raw();

        if (\array_key_exists('retention', $payload)) {
            $current['retention'] = max(1, min(100, (int) $payload['retention']));
        }

        foreach (self::OAUTH_PROVIDERS as $id) {
            if (!isset($payload[$id]) || !\is_array($payload[$id])) {
                continue;
            }
            $patch = $payload[$id];
            if (\array_key_exists('enabled', $patch)) {
                $current[$id]['enabled'] = (bool) $patch['enabled'];
            }
            if (isset($patch['clientId']) && \is_string($patch['clientId'])) {
                $current[$id]['clientId'] = trim($patch['clientId']);
            }
            if (isset($patch['clientSecret']) && \is_string($patch['clientSecret']) && $patch['clientSecret'] !== '') {
                $current[$id]['clientSecret'] = $patch['clientSecret'];
            }
        }

        if (isset($payload['sftp']) && \is_array($payload['sftp'])) {
            $patch = $payload['sftp'];
            if (\array_key_exists('enabled', $patch)) {
                $current['sftp']['enabled'] = (bool) $patch['enabled'];
            }
            if (isset($patch['host']) && \is_string($patch['host'])) {
                $current['sftp']['host'] = trim($patch['host']);
            }
            if (\array_key_exists('port', $patch)) {
                $port = (int) $patch['port'];
                $current['sftp']['port'] = $port > 0 && $port <= 65535 ? $port : 22;
            }
            if (isset($patch['username']) && \is_string($patch['username'])) {
                $current['sftp']['username'] = trim($patch['username']);
            }
            if (isset($patch['auth']) && \is_string($patch['auth'])) {
                $auth = $patch['auth'] === 'privateKey' ? 'privateKey' : 'password';
                $current['sftp']['auth'] = $auth;
            }
            if (isset($patch['password']) && \is_string($patch['password']) && $patch['password'] !== '') {
                $current['sftp']['password'] = $patch['password'];
            }
            if (isset($patch['privateKey']) && \is_string($patch['privateKey']) && $patch['privateKey'] !== '') {
                $current['sftp']['privateKey'] = $patch['privateKey'];
            }
            if (isset($patch['passphrase']) && \is_string($patch['passphrase'])) {
                // allow explicit clear with empty string when key provided alongside
                $current['sftp']['passphrase'] = $patch['passphrase'];
            }
            if (isset($patch['remotePath']) && \is_string($patch['remotePath'])) {
                $path = trim($patch['remotePath']);
                $current['sftp']['remotePath'] = $path !== '' ? $path : '/hcms-backups';
            }
            if (\array_key_exists('insecureHostKey', $patch)) {
                $current['sftp']['insecureHostKey'] = (bool) $patch['insecureHostKey'];
            }
            if (\array_key_exists('timeoutSec', $patch)) {
                $current['sftp']['timeoutSec'] = max(30, min(3600, (int) $patch['timeoutSec']));
            }
        }

        $this->settings->set(self::SETTING_KEY, $current);
    }

    public function setOauthTokens(string $provider, string $refreshToken, string $accountLabel): void
    {
        if (!\in_array($provider, self::OAUTH_PROVIDERS, true)) {
            throw new InvalidArgumentException('Unknown OAuth provider: ' . $provider);
        }
        $current = $this->raw();
        $current[$provider]['refreshToken'] = $refreshToken;
        $current[$provider]['accountLabel'] = $accountLabel;
        $current[$provider]['enabled'] = true;
        $this->settings->set(self::SETTING_KEY, $current);
    }

    public function disconnect(string $provider): void
    {
        if (!\in_array($provider, self::ALL_PROVIDERS, true)) {
            throw new InvalidArgumentException('Unknown provider: ' . $provider);
        }
        $current = $this->raw();
        if ($provider === 'sftp') {
            $current['sftp']['enabled'] = false;
            $current['sftp']['password'] = '';
            $current['sftp']['privateKey'] = '';
            $current['sftp']['passphrase'] = '';
        } else {
            $current[$provider]['refreshToken'] = '';
            $current[$provider]['accountLabel'] = '';
        }
        $this->settings->set(self::SETTING_KEY, $current);
    }

    /**
     * @return RemoteConfig
     */
    public function defaults(): array
    {
        $oauth = [
            'enabled' => false,
            'clientId' => '',
            'clientSecret' => '',
            'refreshToken' => '',
            'accountLabel' => '',
        ];

        return [
            'retention' => 10,
            'google' => $oauth,
            'yandex' => $oauth,
            'dropbox' => $oauth,
            'sftp' => [
                'enabled' => false,
                'host' => '',
                'port' => 22,
                'username' => '',
                'auth' => 'password',
                'password' => '',
                'privateKey' => '',
                'passphrase' => '',
                'remotePath' => '/hcms-backups',
                'insecureHostKey' => false,
                'timeoutSec' => 120,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $stored
     * @param OauthProvider $defaults
     * @return OauthProvider
     */
    private function readOauth(array $stored, string $key, array $defaults): array
    {
        $block = isset($stored[$key]) && \is_array($stored[$key]) ? $stored[$key] : [];

        return [
            'enabled' => (bool) ($block['enabled'] ?? $defaults['enabled']),
            'clientId' => \is_string($block['clientId'] ?? null) ? trim($block['clientId']) : '',
            'clientSecret' => \is_string($block['clientSecret'] ?? null) ? $block['clientSecret'] : '',
            'refreshToken' => \is_string($block['refreshToken'] ?? null) ? $block['refreshToken'] : '',
            'accountLabel' => \is_string($block['accountLabel'] ?? null) ? $block['accountLabel'] : '',
        ];
    }

    /**
     * @param array<string, mixed> $stored
     * @param SftpConfig $defaults
     * @return SftpConfig
     */
    private function readSftp(array $stored, array $defaults): array
    {
        $block = isset($stored['sftp']) && \is_array($stored['sftp']) ? $stored['sftp'] : [];
        $auth = \is_string($block['auth'] ?? null) && $block['auth'] === 'privateKey' ? 'privateKey' : 'password';
        $port = (int) ($block['port'] ?? $defaults['port']);

        return [
            'enabled' => (bool) ($block['enabled'] ?? $defaults['enabled']),
            'host' => \is_string($block['host'] ?? null) ? trim($block['host']) : '',
            'port' => $port > 0 && $port <= 65535 ? $port : 22,
            'username' => \is_string($block['username'] ?? null) ? trim($block['username']) : '',
            'auth' => $auth,
            'password' => \is_string($block['password'] ?? null) ? $block['password'] : '',
            'privateKey' => \is_string($block['privateKey'] ?? null) ? $block['privateKey'] : '',
            'passphrase' => \is_string($block['passphrase'] ?? null) ? $block['passphrase'] : '',
            'remotePath' => \is_string($block['remotePath'] ?? null) && trim($block['remotePath']) !== ''
                ? trim($block['remotePath'])
                : $defaults['remotePath'],
            'insecureHostKey' => (bool) ($block['insecureHostKey'] ?? false),
            'timeoutSec' => max(30, min(3600, (int) ($block['timeoutSec'] ?? $defaults['timeoutSec']))),
        ];
    }

    private function mask(string $secret): ?string
    {
        if ($secret === '') {
            return null;
        }
        $len = \strlen($secret);
        if ($len <= 4) {
            return '••••';
        }

        return '••••' . substr($secret, -4);
    }
}
