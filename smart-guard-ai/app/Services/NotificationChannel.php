<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Interface pour les canaux de notification externes facultatifs.
 * Exemple : class EmailChannel implements NotificationChannel { ... } puis
 * NotificationService::register(new EmailChannel()) dans config/notifications.php.
 * Attention : les SMS / WhatsApp passent par des fournisseurs généralement PAYANTS.
 */
interface NotificationChannel
{
    public function send(?int $storeId, string $type, string $title, ?string $body, string $severity): void;
}
