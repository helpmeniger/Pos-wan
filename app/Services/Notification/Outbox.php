<?php
declare(strict_types=1);

namespace App\Services\Notification;

/** Pilote de développement : consigne les messages dans storage/logs/outbox.log. */
final class Outbox
{
    public static function write(string $channel, string $to, string $content): void
    {
        $line = sprintf("[%s] %s → %s\n%s\n---\n", date('d/m/Y H:i:s'), $channel, $to, $content);
        @file_put_contents(MARWA_ROOT . '/storage/logs/outbox.log', $line, FILE_APPEND | LOCK_EX);
    }
}
