<?php

declare(strict_types=1);

namespace App\Services\Notifications\Sms;

use App\Services\Notifications\SmsSenderInterface;

/** Développement : les SMS sont écrits dans storage/logs/sms.log. */
final class LogSmsSender implements SmsSenderInterface
{
    public function __construct(private readonly string $dir)
    {
    }

    public function send(string $phoneE164, string $message): bool
    {
        @mkdir($this->dir, 0750, true);
        $entry = sprintf("[%s] %s : %s\n", date('d/m/Y H:i:s'), $phoneE164, $message);
        return file_put_contents($this->dir . '/sms.log', $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    public function isRealDelivery(): bool
    {
        return false;
    }
}
