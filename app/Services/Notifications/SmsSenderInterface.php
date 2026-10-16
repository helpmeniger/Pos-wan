<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/** Contrat des fournisseurs SMS. Aucun fournisseur réel n'est branché tant qu'un contrat n'est pas signé. */
interface SmsSenderInterface
{
    public function send(string $phoneE164, string $message): bool;

    /** false = pilote de développement : les messages ne quittent pas le serveur. */
    public function isRealDelivery(): bool;
}
