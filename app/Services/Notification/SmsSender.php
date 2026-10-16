<?php
declare(strict_types=1);

namespace App\Services\Notification;

use App\Core\Config;
use RuntimeException;

/**
 * Envoi de SMS. Pilotes : « log » (aucun SMS réel, écrit dans storage/logs/outbox.log) et « http »
 * (API HTTP d'un fournisseur, réglée dans .env — voir HttpSmsGateway).
 * Pour un envoi réel, implémenter SmsGateway pour l'agrégateur retenu (opérateurs
 * présents au Niger ou agrégateur international) après contractualisation — les SMS sont payants.
 */
final class SmsSender
{
    private static ?SmsGateway $gateway = null;

    public static function useGateway(?SmsGateway $g): void
    {
        self::$gateway = $g;
    }

    public static function send(string $phone, string $message): string
    {
        if (self::$gateway) {
            self::$gateway->send($phone, $message);
            return self::$gateway->name();
        }
        $driver = (string) Config::get('services.sms.driver', 'log');
        if ($driver === 'log') {
            Outbox::write('SMS', $phone, $message);
            return 'log';
        }
        if ($driver === 'http') {
            (new HttpSmsGateway())->send($phone, $message);
            return 'http';
        }
        throw new RuntimeException("Pilote SMS « $driver » non implémenté. Voir docs/SERVICES_EXTERNES.md.");
    }

    public static function isReal(): bool
    {
        return self::$gateway !== null || Config::get('services.sms.driver', 'log') !== 'log';
    }
}
