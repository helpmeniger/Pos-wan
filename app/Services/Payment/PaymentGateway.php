<?php
declare(strict_types=1);

namespace App\Services\Payment;

/**
 * Contrat d'un prestataire de paiement électronique (Mobile Money, carte…).
 * Chaque intégration réelle doit :
 *  - créer une intention de paiement et renvoyer l'URL/les instructions de paiement ;
 *  - vérifier la signature des notifications (webhooks) côté serveur ;
 *  - extraire la référence externe, le statut et le montant confirmés.
 * Aucune intégration réelle n'est livrée : elles nécessitent un contrat marchand.
 */
interface PaymentGateway
{
    public function code(): string;

    /** @return array{external_ref: string, redirect_url: ?string, instructions: ?string} */
    public function initiate(array $payment, array $order): array;

    public function verifyWebhook(string $rawBody, array $headers): bool;

    /** @return array{event_id: string, external_ref: string, status: 'succeeded'|'failed', amount: int, fee: int} */
    public function parseWebhook(string $rawBody): array;
}
