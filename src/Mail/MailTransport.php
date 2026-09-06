<?php

declare(strict_types=1);

namespace Cms\Mail;

interface MailTransport
{
    public function send(MailMessage $message): void;
}
