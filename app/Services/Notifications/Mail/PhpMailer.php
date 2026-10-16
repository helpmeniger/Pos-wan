<?php

declare(strict_types=1);

namespace App\Services\Notifications\Mail;

use App\Services\Notifications\MailerInterface;

/** Envoi via la fonction mail() de PHP (sendmail / relais SMTP configuré dans php.ini). */
final class PhpMailer implements MailerInterface
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function send(string $to, string $subject, string $textBody): bool
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $from = (string) $this->config['from_address'];
        $name = mb_encode_mimeheader((string) $this->config['from_name'], 'UTF-8');
        $headers = [
            'From' => "{$name} <{$from}>",
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'MIME-Version' => '1.0',
        ];
        return mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $textBody, $headers);
    }
}
