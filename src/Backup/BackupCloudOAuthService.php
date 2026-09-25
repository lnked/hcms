<?php

declare(strict_types=1);

namespace Cms\Backup;

use Cms\Auth\OAuthService;
use Cms\Core\AdminBase;
use RuntimeException;

/**
 * OAuth2 connect flow for backup cloud providers (separate from login OAuth).
 */
final class BackupCloudOAuthService
{
    private readonly AdminBase $adminBase;

    public function __construct(
        private readonly BackupRemoteSettings $settings,
        private readonly BackupHttpClient $http,
        private readonly string $appUrl,
        private readonly string $appSecret,
        ?AdminBase $adminBase = null,
    ) {
        $this->adminBase = $adminBase ?? AdminBase::default();
    }

    public function redirectUri(string $provider): string
    {
        return rtrim($this->appUrl, '/')
            . $this->adminBase->apiPrefix()
            . '/backups/cloud/' . $provider . '/callback';
    }

    public function authorizeUrl(string $provider): string
    {
        $config = $this->settings->raw();
        if (!\in_array($provider, BackupRemoteSettings::OAUTH_PROVIDERS, true)) {
            throw new RuntimeException('Unknown OAuth provider: ' . $provider);
        }
        $p = $config[$provider];
        if ($p['clientId'] === '' || $p['clientSecret'] === '') {
            throw new RuntimeException('Configure client ID and secret first');
        }

        $state = OAuthService::signState(['intent' => 'backup', 'userId' => $provider], $this->appSecret);

        return match ($provider) {
            'google' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id' => $p['clientId'],
                'redirect_uri' => $this->redirectUri('google'),
                'response_type' => 'code',
                'scope' => 'https://www.googleapis.com/auth/drive.file',
                'access_type' => 'offline',
                'prompt' => 'consent',
                'state' => $state,
            ], '', '&', PHP_QUERY_RFC3986),
            'yandex' => 'https://oauth.yandex.ru/authorize?' . http_build_query([
                'response_type' => 'code',
                'client_id' => $p['clientId'],
                'redirect_uri' => $this->redirectUri('yandex'),
                'force_confirm' => 'yes',
                'state' => $state,
            ], '', '&', PHP_QUERY_RFC3986),
            'dropbox' => 'https://www.dropbox.com/oauth2/authorize?' . http_build_query([
                'client_id' => $p['clientId'],
                'redirect_uri' => $this->redirectUri('dropbox'),
                'response_type' => 'code',
                'token_access_type' => 'offline',
                'state' => $state,
            ], '', '&', PHP_QUERY_RFC3986),
        };
    }

    /**
     * Exchange code, store refresh token, return account label.
     */
    public function handleCallback(string $provider, string $code, string $state): string
    {
        try {
            $payload = OAuthService::parseState($state, $this->appSecret);
        } catch (\Cms\Auth\OAuthException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
        if (($payload['intent'] ?? '') !== 'backup' || ($payload['userId'] ?? '') !== $provider) {
            throw new RuntimeException('Invalid OAuth state');
        }
        if (!\in_array($provider, BackupRemoteSettings::OAUTH_PROVIDERS, true)) {
            throw new RuntimeException('Unknown provider');
        }

        $config = $this->settings->raw()[$provider];
        [$tokenUrl, $body] = match ($provider) {
            'google' => [
                'https://oauth2.googleapis.com/token',
                [
                    'code' => $code,
                    'client_id' => $config['clientId'],
                    'client_secret' => $config['clientSecret'],
                    'redirect_uri' => $this->redirectUri('google'),
                    'grant_type' => 'authorization_code',
                ],
            ],
            'yandex' => [
                'https://oauth.yandex.ru/token',
                [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'client_id' => $config['clientId'],
                    'client_secret' => $config['clientSecret'],
                ],
            ],
            'dropbox' => [
                'https://api.dropboxapi.com/oauth2/token',
                [
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'client_id' => $config['clientId'],
                    'client_secret' => $config['clientSecret'],
                    'redirect_uri' => $this->redirectUri('dropbox'),
                ],
            ],
        };

        $res = $this->http->request(
            'POST',
            $tokenUrl,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($body),
        );
        $json = json_decode($res['body'], true);
        if ($res['status'] >= 400 || !\is_array($json)) {
            throw new RuntimeException('Token exchange failed: HTTP ' . $res['status']);
        }

        $refresh = \is_string($json['refresh_token'] ?? null) ? $json['refresh_token'] : '';
        if ($refresh === '' && \is_string($json['access_token'] ?? null)) {
            // Yandex may return long-lived access without refresh depending on app type
            $refresh = (string) $json['access_token'];
        }
        if ($refresh === '') {
            throw new RuntimeException('No refresh token returned — revoke app access and reconnect with consent');
        }

        $label = $this->fetchAccountLabel($provider, (string) ($json['access_token'] ?? $refresh));
        $this->settings->setOauthTokens($provider, $refresh, $label);

        return $label;
    }

    public function completeRedirectUrl(string $provider, bool $ok, string $message = ''): string
    {
        $base = rtrim($this->appUrl, '/') . $this->adminBase->path('/settings/backups');
        $query = http_build_query([
            'section' => 'cloud',
            'connected' => $ok ? $provider : '',
            'error' => $ok ? '' : ($message !== '' ? $message : 'connect_failed'),
        ]);

        return $base . '?' . $query;
    }

    private function fetchAccountLabel(string $provider, string $accessToken): string
    {
        try {
            if ($provider === 'google') {
                $res = $this->http->request(
                    'GET',
                    'https://www.googleapis.com/oauth2/v2/userinfo',
                    ['Authorization' => 'Bearer ' . $accessToken],
                );
                $json = json_decode($res['body'], true);
                if (\is_array($json) && isset($json['email']) && \is_string($json['email'])) {
                    return $json['email'];
                }
            }
            if ($provider === 'dropbox') {
                $res = $this->http->request(
                    'POST',
                    'https://api.dropboxapi.com/2/users/get_current_account',
                    ['Authorization' => 'Bearer ' . $accessToken],
                );
                $json = json_decode($res['body'], true);
                if (\is_array($json) && isset($json['email']) && \is_string($json['email'])) {
                    return $json['email'];
                }
            }
            if ($provider === 'yandex') {
                $res = $this->http->request(
                    'GET',
                    'https://login.yandex.ru/info?format=json',
                    ['Authorization' => 'OAuth ' . $accessToken],
                );
                $json = json_decode($res['body'], true);
                if (\is_array($json) && isset($json['default_email']) && \is_string($json['default_email'])) {
                    return $json['default_email'];
                }
                if (\is_array($json) && isset($json['login']) && \is_string($json['login'])) {
                    return $json['login'];
                }
            }
        } catch (RuntimeException) {
            // ignore — label optional
        }

        return $provider;
    }
}
