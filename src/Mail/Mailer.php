<?php

declare(strict_types=1);

namespace Cms\Mail;

use Cms\Auth\RateLimiter;
use Cms\Auth\RateLimitStore;
use Cms\Core\Settings;
use InvalidArgumentException;

final class Mailer
{
    public function __construct(
        private readonly Settings $settings,
        private readonly EmailIntegration $email,
        private readonly ?RateLimitStore $rateLimitStore = null,
    ) {
    }

    public function send(MailMessage $message): void
    {
        $this->transport()->send($message);
    }

    public function sendTest(string $to): void
    {
        $to = trim($to);
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('to must be a valid email');
        }

        $config = $this->requireReadyConfig();
        $appName = $this->settings->string('app.name', 'HCMS');
        $fromName = $config['fromName'] !== '' ? $config['fromName'] : $appName;
        $providerLabel = $this->providerLabel($config['provider']);

        $this->send(new MailMessage(
            fromEmail: $config['fromEmail'],
            fromName: $fromName,
            to: $to,
            subject: $appName . ' — test email',
            html: '<p>This is a test email from <strong>' . htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>.</p>'
                . '<p>If you received this, the ' . htmlspecialchars($providerLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' integration is working.</p>',
            text: 'This is a test email from ' . $appName . '. If you received this, the ' . $providerLabel . ' integration is working.',
        ));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{subject?: string, html?: string, text?: string} $defaults
     * @return array{ok: true, provider: string, to: string}
     */
    public function sendIntegration(array $payload, array $defaults = [], bool $allowFromOverride = true, ?int $tokenId = null): array
    {
        $config = $this->requireReadyConfig();

        $to = isset($payload['to']) && is_string($payload['to']) ? trim($payload['to']) : '';
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('to must be a valid email');
        }

        $this->email->assertRecipientAllowed($to);
        $this->assertDailyQuota($tokenId);

        $vars = [];
        if (isset($payload['vars']) && is_array($payload['vars'])) {
            foreach ($payload['vars'] as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                if (is_scalar($value) || $value === null) {
                    $vars[$key] = (string) ($value ?? '');
                }
            }
        }

        $subject = $this->pickAndRender($payload, 'subject', $defaults['subject'] ?? '', $vars);
        $html = $this->pickAndRender($payload, 'html', $defaults['html'] ?? '', $vars);
        $text = $this->pickAndRender($payload, 'text', $defaults['text'] ?? '', $vars);

        if ($subject === '') {
            throw new InvalidArgumentException('subject is required');
        }
        if ($html === '' && $text === '') {
            throw new InvalidArgumentException('html or text is required');
        }

        $fromEmail = $config['fromEmail'];
        $fromName = $config['fromName'] !== '' ? $config['fromName'] : $this->settings->string('app.name', 'HCMS');
        if ($allowFromOverride) {
            if (isset($payload['fromEmail']) && is_string($payload['fromEmail']) && trim($payload['fromEmail']) !== '') {
                $overrideFrom = trim($payload['fromEmail']);
                if (filter_var($overrideFrom, FILTER_VALIDATE_EMAIL) === false) {
                    throw new InvalidArgumentException('fromEmail is invalid');
                }
                $fromEmail = $overrideFrom;
            }
            if (isset($payload['fromName']) && is_string($payload['fromName']) && trim($payload['fromName']) !== '') {
                $fromName = trim($payload['fromName']);
            }
        }

        $this->send(new MailMessage(
            fromEmail: $fromEmail,
            fromName: $fromName,
            to: $to,
            subject: $subject,
            html: $html !== '' ? $html : '<pre>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>',
            text: $text !== '' ? $text : null,
        ));

        return [
            'ok' => true,
            'provider' => $config['provider'],
            'to' => $to,
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
     *   resend: array{apiKey: string},
     *   postmark: array{apiKey: string},
     *   mailgun: array{apiKey: string, domain: string, region: string}
     * }
     */
    private function requireReadyConfig(): array
    {
        $config = $this->email->raw();
        if (!$config['enabled']) {
            throw new InvalidArgumentException('Email integration is disabled');
        }
        if ($this->email->activeApiKey($config) === '') {
            throw new InvalidArgumentException('API key is not configured');
        }
        if ($config['fromEmail'] === '') {
            throw new InvalidArgumentException('fromEmail is required');
        }
        if ($config['provider'] === 'mailgun' && $config['mailgun']['domain'] === '') {
            throw new InvalidArgumentException('mailgunDomain is required');
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $vars
     */
    private function pickAndRender(array $payload, string $key, string $default, array $vars): string
    {
        $value = isset($payload[$key]) && is_string($payload[$key]) ? $payload[$key] : $default;

        return $this->renderTemplate($value, $vars);
    }

    /**
     * @param array<string, string> $vars
     */
    private function renderTemplate(string $template, array $vars): string
    {
        if ($template === '' || $vars === []) {
            return $template;
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static fn (array $m): string => $vars[$m[1]] ?? $m[0],
            $template,
        );
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'postmark' => 'Postmark',
            'mailgun' => 'Mailgun',
            default => 'Resend',
        };
    }

    private function transport(): MailTransport
    {
        $config = $this->requireReadyConfig();
        $apiKey = $this->email->activeApiKey($config);

        return match ($config['provider']) {
            'resend' => new ResendTransport($apiKey),
            'postmark' => new PostmarkTransport($apiKey),
            'mailgun' => $this->mailgunTransport($config, $apiKey),
            default => throw new InvalidArgumentException('Unsupported email provider'),
        };
    }

    /**
     * @param array{
     *   provider: string,
     *   enabled: bool,
     *   fromEmail: string,
     *   fromName: string,
     *   dailyQuota: int,
     *   allowedRecipientDomains: list<string>,
     *   resend: array{apiKey: string},
     *   postmark: array{apiKey: string},
     *   mailgun: array{apiKey: string, domain: string, region: string}
     * } $config
     */
    private function mailgunTransport(array $config, string $apiKey): MailgunTransport
    {
        $domain = $config['mailgun']['domain'];
        if ($domain === '') {
            throw new InvalidArgumentException('mailgunDomain is required');
        }

        return new MailgunTransport($apiKey, $domain, $config['mailgun']['region']);
    }

    private function assertDailyQuota(?int $tokenId): void
    {
        $quota = $this->email->raw()['dailyQuota'];
        if ($quota <= 0 || $this->rateLimitStore === null) {
            return;
        }
        $limiter = new RateLimiter($this->rateLimitStore, 86400, $quota);
        $bucket = 'email:day:' . ($tokenId !== null ? 'token:' . $tokenId : 'global');
        if (!$limiter->hit($bucket)) {
            throw new InvalidArgumentException('Daily email send quota exceeded');
        }
    }
}
