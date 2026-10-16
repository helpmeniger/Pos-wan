<?php

declare(strict_types=1);

namespace App\Services\Notifications\Mail;

use App\Services\Notifications\MailerInterface;

/** Développement : les e-mails sont écrits dans storage/logs/mail.log au lieu d'être envoyés. */
final class LogMailer implements MailerInterface
{
    public function __construct(private readonly string $dir)
    {
    }

    public function send(string $to, string $subject, string $textBody): bool
    {
        @mkdir($this->dir, 0750, true);
        $entry = sprintf("==== %s\nÀ : %s\nObjet : %s\n\n%s\n\n", date('d/m/Y H:i:s'), $to, $subject, $textBody);
        return file_put_contents($this->dir . '/mail.log', $entry, FILE_APPEND | LOCK_EX) !== false;
    }
}
