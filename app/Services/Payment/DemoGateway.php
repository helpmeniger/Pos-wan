<?php
declare(strict_types=1);

namespace App\Services\Payment;

use App\Core\Config;
use App\Core\Settings;
use App\Core\Str;

/**
 * Passerelle de DÉMONSTRATION. Aucun argent réel n'est déplacé.
 * Elle reproduit fidèlement le cycle d'un vrai prestataire : référence externe,
 * page de paiement, notification serveur signée HMAC-SHA256, idempotence.
 */
final class DemoGateway implements PaymentGateway
{
    public function code(): string
    {
        return 'demo';
    }

    public static function secret(): string
    {
        $s = (string) Config::get('services.payments.demo_secret', '');
        if (strlen($s) < 16 || $s === 'changez-moi') {
            throw new \RuntimeException('PAYMENT_DEMO_SECRET doit être défini (au moins 16 caractères) dans .env.');
        }
        return $s;
    }

    public function initiate(array $payment, array $order): array
    {
        return [
            'external_ref' => 'DEMO-' . strtoupper(Str::token(9)),
            'redirect_url' => url('paiement/demo/' . $payment['uuid']),
            'instructions' => 'Paiement simulé : aucun débit réel ne sera effectué.',
        ];
    }

    public static function sign(string $body): string
    {
        return hash_hmac('sha256', $body, self::secret());
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $sig = (string) ($headers['x-marwa-signature'] ?? '');
        return $sig !== '' && hash_equals(self::sign($rawBody), $sig);
    }

    public function parseWebhook(string $rawBody): array
    {
        $d = json_decode($rawBody, true) ?: [];
        $amount = (int) ($d['amount'] ?? 0);
        return [
            'event_id' => (string) ($d['event_id'] ?? ''),
            'external_ref' => (string) ($d['external_ref'] ?? ''),
            'status' => ($d['status'] ?? '') === 'succeeded' ? 'succeeded' : 'failed',
            'amount' => $amount,
            'fee' => (int) round($amount * Settings::int('payments.demo_fee_bp', 100) / 10000),
        ];
    }

    /** Construit la notification signée envoyée par le simulateur (page /paiement/demo). */
    public static function buildWebhook(string $externalRef, int $amount, bool $success): array
    {
        $body = json_encode([
            'event_id' => 'evt_' . Str::token(12),
            'external_ref' => $externalRef,
            'status' => $success ? 'succeeded' : 'failed',
            'amount' => $amount,
            'currency' => 'XOF',
            'created' => time(),
        ]);
        return [$body, self::sign((string) $body)];
    }
}
