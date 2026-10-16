<?php

declare(strict_types=1);

namespace App\Services\Notifications;

interface MailerInterface
{
    public function send(string $to, string $subject, string $textBody): bool;
}
