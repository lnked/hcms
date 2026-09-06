<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Core\Settings;

final class CaptchaVerifier
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function isConfigured(): bool
    {
        return $this->provider() !== null && $this->secret() !== '';
    }

    public function siteKey(): string
    {
        $raw = $this->settings->get('security.captcha');
        if (!is_array($raw)) {
            return '';
        }

        return is_string($raw['siteKey'] ?? null) ? trim($raw['siteKey']) : '';
    }

    public function provider(): ?string
    {
        $raw = $this->settings->get('security.captcha');
        if (!is_array($raw)) {
            return null;
        }
        $provider = is_string($raw['provider'] ?? null) ? strtolower(trim($raw['provider'])) : '';
        if (!in_array($provider, ['turnstile', 'hcaptcha'], true)) {
            return null;
        }
        if (!(bool) ($raw['enabled'] ?? false)) {
            return null;
        }

        return $provider;
    }

    public function secret(): string
    {
        $raw = $this->settings->get('security.captcha');
        if (!is_array($raw)) {
            return '';
        }

        return is_string($raw['secretKey'] ?? null) ? trim($raw['secretKey']) : '';
    }

    public function verify(string $token, string $ip): bool
    {
        $token = trim($token);
        $provider = $this->provider();
        $secret = $this->secret();
        if ($provider === null || $secret === '' || $token === '') {
            return false;
        }

        $endpoint = $provider === 'hcaptcha'
            ? 'https://hcaptcha.com/siteverify'
            : 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

        $payload = http_build_query([
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($endpoint, false, $context);
        if ($body === false) {
            return false;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return false;
        }

        return (bool) ($decoded['success'] ?? false);
    }

    /**
     * Public config for admin/login UI (no secrets).
     *
     * @return array{enabled: bool, provider: string|null, siteKey: string}
     */
    public function publicConfig(): array
    {
        $provider = $this->provider();

        return [
            'enabled' => $provider !== null && $this->siteKey() !== '',
            'provider' => $provider,
            'siteKey' => $this->siteKey(),
        ];
    }
}
