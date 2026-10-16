<?php

declare(strict_types=1);

use App\Core\Env;

return [
    // Envoi d'e-mails : "log" (développement, écrit dans storage/logs/mail.log) ou "mail" (fonction mail() de PHP / sendmail)
    'mail' => [
        'driver' => Env::get('MAIL_DRIVER', 'log'),
        'from_address' => Env::get('MAIL_FROM_ADDRESS', 'no-reply@job-niger.local'),
        'from_name' => Env::get('MAIL_FROM_NAME', 'JOB NIGER'),
    ],
    // Envoi de SMS : "log" (développement) — aucun fournisseur réel n'est configuré
    'sms' => [
        'driver' => Env::get('SMS_DRIVER', 'log'),
        'sender' => Env::get('SMS_SENDER', 'JOBNIGER'),
    ],
];
