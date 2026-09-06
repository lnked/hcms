<?php

declare(strict_types=1);

namespace Cms\Mail;

final class MailgunTransport implements MailTransport
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $domain,
        private readonly string $region = 'us',
    ) {
    }

    public function send(MailMessage $message): void
    {
        $base = $this->region === 'eu'
            ? 'https://api.eu.mailgun.net'
            : 'https://api.mailgun.net';
        $url = $base . '/v3/' . rawurlencode($this->domain) . '/messages';

        $fields = [
            'from' => $message->fromHeader(),
            'to' => $message->to,
            'subject' => $message->subject,
            'html' => $message->html,
        ];
        if ($message->text !== null && $message->text !== '') {
            $fields['text'] = $message->text;
        }
        $body = http_build_query($fields);

        [$status, $responseBody] = MailHttp::post(
            $url,
            $body,
            [
                'Authorization: Basic ' . base64_encode('api:' . $this->apiKey),
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'User-Agent: hcms-mailer',
            ],
            'Mailgun',
        );

        if ($status >= 200 && $status < 300) {
            return;
        }

        $decoded = json_decode($responseBody, true);
        $errorMessage = is_array($decoded)
            ? (MailHttp::jsonErrorMessage($decoded) ?? ('Mailgun request failed with HTTP ' . $status))
            : ('Mailgun request failed with HTTP ' . $status);
        throw new MailProviderException($errorMessage, $status >= 400 && $status < 600 ? $status : 502);
    }
}
