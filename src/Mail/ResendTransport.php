<?php

declare(strict_types=1);

namespace Cms\Mail;

use RuntimeException;

final class ResendTransport implements MailTransport
{
    private const ENDPOINT = 'https://api.resend.com/emails';

    public function __construct(private readonly string $apiKey)
    {
    }

    public function send(MailMessage $message): void
    {
        $payload = [
            'from' => $message->fromHeader(),
            'to' => [$message->to],
            'subject' => $message->subject,
            'html' => $message->html,
        ];
        if ($message->text !== null && $message->text !== '') {
            $payload['text'] = $message->text;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Failed to encode Resend payload');
        }

        [$status, $responseBody] = MailHttp::post(
            self::ENDPOINT,
            $body,
            [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: hcms-mailer',
            ],
            'Resend',
        );

        if ($status >= 200 && $status < 300) {
            return;
        }

        $decoded = json_decode($responseBody, true);
        $errorMessage = is_array($decoded)
            ? (MailHttp::jsonErrorMessage($decoded) ?? ('Resend request failed with HTTP ' . $status))
            : ('Resend request failed with HTTP ' . $status);
        throw new MailProviderException($errorMessage, $status >= 400 && $status < 600 ? $status : 502);
    }
}
