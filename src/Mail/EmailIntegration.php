<?php

declare(strict_types=1);

namespace Cms\Mail;

use Cms\Core\Settings;
use InvalidArgumentException;

/**
 * Reads/writes integrations.email in cms_settings.
 *
 * @phpstan-type ProviderKeys array{apiKey: string}
 * @phpstan-type MailgunKeys array{apiKey: string, domain: string, region: string}
 * @phpstan-type EmailConfig array{
 *   provider: string,
 *   enabled: bool,
 *   fromEmail: string,
 *   fromName: string,
 *   dailyQuota: int,
 *   allowedRecipientDomains: list<string>,
 *   resend: ProviderKeys,
 *   postmark: ProviderKeys,
 *   mailgun: MailgunKeys
 * }
 */
final class EmailIntegration
{
    public const SETTING_KEY = 'integrations.email';

    /** @var list<string> */
    public const PROVIDERS = ['resend', 'postmark', 'mailgun'];

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @return EmailConfig
     */
    public function raw(): array
    {
        $stored = $this->settings->get(self::SETTING_KEY);
        if (!is_array($stored)) {
            return $this->defaults();
        }

        $defaults = $this->defaults();
        $provider = is_string($stored['provider'] ?? null) ? $stored['provider'] : 'resend';
        if (!in_array($provider, self::PROVIDERS, true)) {
            $provider = 'resend';
        }

        $resend = $this->readProviderKeys($stored, 'resend', $defaults['resend']);
        $postmark = $this->readProviderKeys($stored, 'postmark', $defaults['postmark']);
        $mailgun = $this->readMailgunKeys($stored, $defaults['mailgun']);

        if (
            is_string($stored['apiKey'] ?? null)
            && $stored['apiKey'] !== ''
            && $resend['apiKey'] === ''
            && $postmark['apiKey'] === ''
            && $mailgun['apiKey'] === ''
        ) {
            if ($provider === 'mailgun') {
                $mailgun['apiKey'] = $stored['apiKey'];
            } elseif ($provider === 'postmark') {
                $postmark['apiKey'] = $stored['apiKey'];
            } else {
                $resend['apiKey'] = $stored['apiKey'];
            }
        }

        $domains = [];
        if (is_array($stored['allowedRecipientDomains'] ?? null)) {
            foreach ($stored['allowedRecipientDomains'] as $domain) {
                if (is_string($domain) && trim($domain) !== '') {
                    $domains[] = strtolower(trim($domain));
                }
            }
        }

        return [
            'provider' => $provider,
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'fromEmail' => is_string($stored['fromEmail'] ?? null) ? $stored['fromEmail'] : '',
            'fromName' => is_string($stored['fromName'] ?? null) ? $stored['fromName'] : '',
            'dailyQuota' => max(0, (int) ($stored['dailyQuota'] ?? 100)),
            'allowedRecipientDomains' => array_values(array_unique($domains)),
            'resend' => $resend,
            'postmark' => $postmark,
            'mailgun' => $mailgun,
        ];
    }

    /**
     * @return array{
     *   provider: string,
     *   enabled: bool,
     *   fromEmail: string,
     *   fromName: string,
     *   dailyQuota: int,
     *   allowedRecipientDomains: list<string>,
     *   apiKeyConfigured: bool,
     *   apiKeyMasked: string|null,
     *   mailgunDomain: string,
     *   mailgunRegion: string,
     *   providers: array{
     *     resend: array{apiKeyConfigured: bool, apiKeyMasked: string|null},
     *     postmark: array{apiKeyConfigured: bool, apiKeyMasked: string|null},
     *     mailgun: array{apiKeyConfigured: bool, apiKeyMasked: string|null}
     *   }
     * }
     */
    public function publicConfig(): array
    {
        $config = $this->raw();
        $providers = [
            'resend' => $this->publicProviderKey($config['resend']['apiKey'], 'resend'),
            'postmark' => $this->publicProviderKey($config['postmark']['apiKey'], 'postmark'),
            'mailgun' => $this->publicProviderKey($config['mailgun']['apiKey'], 'mailgun'),
        ];
        $active = $providers[$config['provider']];

        return [
            'provider' => $config['provider'],
            'enabled' => $config['enabled'],
            'fromEmail' => $config['fromEmail'],
            'fromName' => $config['fromName'],
            'dailyQuota' => $config['dailyQuota'],
            'allowedRecipientDomains' => $config['allowedRecipientDomains'],
            'apiKeyConfigured' => $active['apiKeyConfigured'],
            'apiKeyMasked' => $active['apiKeyMasked'],
            'mailgunDomain' => $config['mailgun']['domain'],
            'mailgunRegion' => $config['mailgun']['region'],
            'providers' => $providers,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   provider: string,
     *   enabled: bool,
     *   fromEmail: string,
     *   fromName: string,
     *   dailyQuota: int,
     *   allowedRecipientDomains: list<string>,
     *   apiKeyConfigured: bool,
     *   apiKeyMasked: string|null,
     *   mailgunDomain: string,
     *   mailgunRegion: string,
     *   providers: array{
     *     resend: array{apiKeyConfigured: bool, apiKeyMasked: string|null},
     *     postmark: array{apiKeyConfigured: bool, apiKeyMasked: string|null},
     *     mailgun: array{apiKeyConfigured: bool, apiKeyMasked: string|null}
     *   }
     * }
     */
    public function update(array $payload): array
    {
        $current = $this->raw();

        if (array_key_exists('enabled', $payload)) {
            if (!is_bool($payload['enabled'])) {
                throw new InvalidArgumentException('enabled must be a boolean');
            }
            $current['enabled'] = $payload['enabled'];
        }

        if (array_key_exists('provider', $payload)) {
            if (!is_string($payload['provider']) || $payload['provider'] === '') {
                throw new InvalidArgumentException('provider is required');
            }
            if (!in_array($payload['provider'], self::PROVIDERS, true)) {
                throw new InvalidArgumentException('Unsupported provider');
            }
            $current['provider'] = $payload['provider'];
        }

        if (array_key_exists('fromEmail', $payload)) {
            if (!is_string($payload['fromEmail'])) {
                throw new InvalidArgumentException('fromEmail must be a string');
            }
            $fromEmail = trim($payload['fromEmail']);
            if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('fromEmail is invalid');
            }
            $current['fromEmail'] = $fromEmail;
        }

        if (array_key_exists('fromName', $payload)) {
            if (!is_string($payload['fromName'])) {
                throw new InvalidArgumentException('fromName must be a string');
            }
            $current['fromName'] = trim($payload['fromName']);
        }

        if (array_key_exists('dailyQuota', $payload)) {
            if (!is_int($payload['dailyQuota']) && !(is_string($payload['dailyQuota']) && ctype_digit($payload['dailyQuota']))) {
                throw new InvalidArgumentException('dailyQuota must be an integer');
            }
            $current['dailyQuota'] = max(0, (int) $payload['dailyQuota']);
        }

        if (array_key_exists('allowedRecipientDomains', $payload)) {
            if (!is_array($payload['allowedRecipientDomains'])) {
                throw new InvalidArgumentException('allowedRecipientDomains must be an array');
            }
            $domains = [];
            foreach ($payload['allowedRecipientDomains'] as $domain) {
                if (!is_string($domain)) {
                    continue;
                }
                $domain = strtolower(trim($domain));
                if ($domain !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) === 1) {
                    $domains[] = $domain;
                }
            }
            $current['allowedRecipientDomains'] = array_values(array_unique($domains));
        }

        if (array_key_exists('apiKey', $payload)) {
            if (!is_string($payload['apiKey'])) {
                throw new InvalidArgumentException('apiKey must be a string');
            }
            $apiKey = trim($payload['apiKey']);
            if ($apiKey !== '') {
                match ($current['provider']) {
                    'postmark' => $current['postmark']['apiKey'] = $apiKey,
                    'mailgun' => $current['mailgun']['apiKey'] = $apiKey,
                    default => $current['resend']['apiKey'] = $apiKey,
                };
            }
        }

        if (array_key_exists('mailgunDomain', $payload)) {
            if (!is_string($payload['mailgunDomain'])) {
                throw new InvalidArgumentException('mailgunDomain must be a string');
            }
            $current['mailgun']['domain'] = trim($payload['mailgunDomain']);
        }

        if (array_key_exists('mailgunRegion', $payload)) {
            if (!is_string($payload['mailgunRegion'])) {
                throw new InvalidArgumentException('mailgunRegion must be a string');
            }
            $region = strtolower(trim($payload['mailgunRegion']));
            if (!in_array($region, ['us', 'eu'], true)) {
                throw new InvalidArgumentException('mailgunRegion must be us or eu');
            }
            $current['mailgun']['region'] = $region;
        }

        $this->settings->set(self::SETTING_KEY, [
            'provider' => $current['provider'],
            'enabled' => $current['enabled'],
            'fromEmail' => $current['fromEmail'],
            'fromName' => $current['fromName'],
            'dailyQuota' => $current['dailyQuota'],
            'allowedRecipientDomains' => $current['allowedRecipientDomains'],
            'resend' => $current['resend'],
            'postmark' => $current['postmark'],
            'mailgun' => $current['mailgun'],
        ]);

        return $this->publicConfig();
    }

    /**
     * @return EmailConfig
     */
    public function defaults(): array
    {
        return [
            'provider' => 'resend',
            'enabled' => false,
            'fromEmail' => '',
            'fromName' => '',
            'dailyQuota' => 100,
            'allowedRecipientDomains' => [],
            'resend' => ['apiKey' => ''],
            'postmark' => ['apiKey' => ''],
            'mailgun' => ['apiKey' => '', 'domain' => '', 'region' => 'us'],
        ];
    }

    public function ensureDefaults(): void
    {
        if ($this->settings->get(self::SETTING_KEY) !== null) {
            return;
        }
        $this->settings->set(self::SETTING_KEY, $this->defaults());
    }

    /**
     * @param EmailConfig $config
     */
    public function activeApiKey(array $config): string
    {
        return match ($config['provider']) {
            'postmark' => $config['postmark']['apiKey'],
            'mailgun' => $config['mailgun']['apiKey'],
            default => $config['resend']['apiKey'],
        };
    }

    public function assertRecipientAllowed(string $to): void
    {
        $config = $this->raw();
        $domains = $config['allowedRecipientDomains'];
        if ($domains === []) {
            return;
        }
        $parts = explode('@', strtolower($to));
        $domain = $parts[1] ?? '';
        if ($domain === '' || !in_array($domain, $domains, true)) {
            throw new InvalidArgumentException('Recipient domain is not allowed');
        }
    }

    /**
     * @param array<string, mixed> $stored
     * @param ProviderKeys $fallback
     * @return ProviderKeys
     */
    private function readProviderKeys(array $stored, string $key, array $fallback): array
    {
        $bucket = $stored[$key] ?? null;
        if (!is_array($bucket)) {
            return $fallback;
        }

        return [
            'apiKey' => is_string($bucket['apiKey'] ?? null) ? $bucket['apiKey'] : '',
        ];
    }

    /**
     * @param array<string, mixed> $stored
     * @param MailgunKeys $fallback
     * @return MailgunKeys
     */
    private function readMailgunKeys(array $stored, array $fallback): array
    {
        $bucket = $stored['mailgun'] ?? null;
        if (!is_array($bucket)) {
            return $fallback;
        }
        $region = is_string($bucket['region'] ?? null) ? strtolower($bucket['region']) : 'us';
        if (!in_array($region, ['us', 'eu'], true)) {
            $region = 'us';
        }

        return [
            'apiKey' => is_string($bucket['apiKey'] ?? null) ? $bucket['apiKey'] : '',
            'domain' => is_string($bucket['domain'] ?? null) ? $bucket['domain'] : '',
            'region' => $region,
        ];
    }

    /**
     * @return array{apiKeyConfigured: bool, apiKeyMasked: string|null}
     */
    private function publicProviderKey(string $apiKey, string $provider): array
    {
        $configured = $apiKey !== '';

        return [
            'apiKeyConfigured' => $configured,
            'apiKeyMasked' => $configured ? $this->maskApiKey($apiKey, $provider) : null,
        ];
    }

    private function maskApiKey(string $apiKey, string $provider): string
    {
        $len = strlen($apiKey);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }
        $prefix = '';
        $rest = $apiKey;
        if ($provider === 'resend' && str_starts_with($apiKey, 're_')) {
            $prefix = 're_';
            $rest = substr($apiKey, 3);
        }
        $last = substr($rest, -4);

        return $prefix . '••••' . $last;
    }
}
