<?php

declare(strict_types=1);

namespace Cms\Mail;

final class MailMessage
{
    public function __construct(
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly ?string $text = null,
    ) {
    }

    public function fromHeader(): string
    {
        $name = trim($this->fromName);
        if ($name === '') {
            return $this->fromEmail;
        }

        return \sprintf('%s <%s>', $name, $this->fromEmail);
    }
}
