<?php
declare(strict_types=1);

namespace App\Services\Notification;

interface SmsGateway
{
    public function name(): string;

    /** @throws \RuntimeException en cas d'échec d'envoi */
    public function send(string $phoneE164, string $message): void;
}
