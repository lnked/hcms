<?php

declare(strict_types=1);

namespace Cms\Mail;

use RuntimeException;

final class PostmarkTransport implements MailTransport
{
    private const ENDPOINT = 'https://api.postmarkapp.com/email';

    public function __construct(private readonly string $serverToken)
    {
    }

    public function send(MailMessage $message): void
    {
        $payload = [
            'From' => $message->fromHeader(),
            'To' => $message->to,
            'Subject' => $message->subject,
            'HtmlBody' => $message->html,
            'MessageStream' => 'outbound',
        ];
        if ($message->text !== null && $message->text !== '') {
            $payload['TextBody'] = $message->text;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Failed to encode Postmark payload');
        }

        [$status, $responseBody] = MailHttp::post(
            self::ENDPOINT,
            $body,
            [
                'X-Postmark-Server-Token: ' . $this->serverToken,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: hcms-mailer',
            ],
            'Postmark',
        );

        if ($status >= 200 && $status < 300) {
            return;
        }

        $decoded = json_decode($responseBody, true);
        $errorMessage = is_array($decoded)
            ? (MailHttp::jsonErrorMessage($decoded) ?? ('Postmark request failed with HTTP ' . $status))
            : ('Postmark request failed with HTTP ' . $status);
        throw new MailProviderException($errorMessage, $status >= 400 && $status < 600 ? $status : 502);
    }
}
