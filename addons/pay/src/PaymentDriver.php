<?php
declare(strict_types=1);

namespace Addons\Pay;

/**
 * Interface commune des connecteurs (API de prestataires) et des pilotes de terminaux (TPE).
 * Une fonction non disponible renvoie GatewayResult::unsupported(), jamais un faux succès.
 * Aucune donnée de carte (numéro complet, CVV, PIN) ne transite par ces méthodes.
 */
interface PaymentDriver
{
    /** @param array $target ligne payment_connectors ou payment_terminals (config_json décodé dans 'config') */
    public function __construct(array $target);

    /** label, kind (gateway|terminal|simulation), transports, verified, skeleton, notes */
    public static function info(): array;

    /** @return string[] testConnection, initiate, getStatus, cancel, void, refund, getRefundStatus, webhook */
    public static function capabilities(): array;

    public function testConnection(): GatewayResult;

    /**
     * Demande de paiement. $p : number, idempotency_key, amount, currency, description, payer_phone, webhook_url, register, store.
     * Données renvoyées possibles : provider_reference, authorization_code, redirect_url, amount, currency, fees, payer_hint.
     */
    public function initiate(array $p): GatewayResult;

    /** État réel chez le prestataire (vérification serveur à serveur). $tx : ligne payment_transactions. */
    public function getStatus(array $tx): GatewayResult;

    public function cancel(array $tx): GatewayResult;

    /** $r : number, idempotency_key, amount, currency, reason */
    public function refund(array $tx, array $r): GatewayResult;

    public function getRefundStatus(array $tx, array $refund): GatewayResult;

    /**
     * Notification reçue (signature déjà vérifiée par le contrôleur) → [reference, status, amount, currency, event_id] ou null.
     */
    public function parseWebhook(string $body, array $headers): ?array;
}
