<?php

declare(strict_types=1);

namespace Cms\Mail;

use Cms\Core\Settings;
use InvalidArgumentException;

final class Mailer
{
    public function __construct(
        private readonly Settings $settings,
        private readonly EmailIntegration $email,
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

        $appName = $this->settings->string('app.name', 'HCMS');
        $fromName = $config['fromName'] !== '' ? $config['fromName'] : $appName;
        $providerLabel = match ($config['provider']) {
            'postmark' => 'Postmark',
            'mailgun' => 'Mailgun',
            default => 'Resend',
        };

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

    private function transport(): MailTransport
    {
        $config = $this->email->raw();
        if (!$config['enabled']) {
            throw new InvalidArgumentException('Email integration is disabled');
        }

        $apiKey = $this->email->activeApiKey($config);
        if ($apiKey === '') {
            throw new InvalidArgumentException('API key is not configured');
        }

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
}
