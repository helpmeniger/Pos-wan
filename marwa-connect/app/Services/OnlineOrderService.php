<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;
use RuntimeException;

/**
 * Achat de tickets en ligne. Le paiement n'est JAMAIS considéré comme confirmé sur la base
 * d'une redirection navigateur : uniquement via webhook signé (HMAC) ou confirmation
 * manuelle d'un opérateur habilité après vérification de la référence chez le prestataire.
 */
final class OnlineOrderService
{
    public static function methodConfig(array $method): array
    {
        return $method['config_enc'] ? (json_decode(Crypto::decrypt($method['config_enc']), true) ?: []) : [];
    }

    /** @return array{id:int, reference:string, token:string} */
    public static function create(int $offerId, int $routerId, int $methodId, string $phone, ?string $ip): array
    {
        $offer = Database::fetch("SELECT * FROM offers WHERE id = :id AND status = 'active' AND is_public = 1", ['id' => $offerId]);
        $method = Database::fetch('SELECT * FROM payment_methods WHERE id = :id AND is_active = 1 AND available_online = 1', ['id' => $methodId]);
        $router = Database::fetch("SELECT r.* FROM routers r JOIN offer_router orr ON orr.router_id = r.id AND orr.offer_id = :o WHERE r.id = :r AND r.status = 'active' AND r.deleted_at IS NULL", ['o' => $offerId, 'r' => $routerId]);
        if (!$offer || !$method || !$router) {
            throw new RuntimeException('Offre, point d\'accès ou moyen de paiement indisponible.');
        }
        $stock = (int) Database::value("SELECT COUNT(*) FROM tickets WHERE offer_id = :o AND router_id = :r AND status = 'available' AND on_router = 1 AND reseller_id IS NULL", ['o' => $offerId, 'r' => $routerId]);
        if ($stock === 0) {
            throw new RuntimeException('Ce forfait est momentanément épuisé sur ce point d\'accès.');
        }
        $recent = (int) Database::value("SELECT COUNT(*) FROM online_orders WHERE customer_phone = :p AND status IN ('created','awaiting_payment') AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)", ['p' => $phone]);
        if ($recent >= 3) {
            throw new RuntimeException('Trop de commandes en attente pour ce numéro. Finalisez un paiement en cours.');
        }
        $token = Crypto::token(24);
        $ref = 'WEB' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
        $id = Database::insert('online_orders', [
            'reference' => $ref, 'offer_id' => $offerId, 'router_id' => $routerId, 'customer_phone' => $phone,
            'amount' => $offer['price'], 'payment_method_id' => $methodId, 'status' => 'awaiting_payment',
            'access_token' => hash('sha256', $token), 'ip_address' => $ip, 'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        return ['id' => $id, 'reference' => $ref, 'token' => $token];
    }

    public static function findByToken(string $reference, string $token): ?array
    {
        $o = Database::fetch(
            'SELECT oo.*, o.name AS offer_name, o.validity_value, o.validity_unit, o.time_limit_seconds, o.data_limit_bytes, pm.name AS method_name, pm.merchant_number, pm.instructions,
                    r.wifi_ssid, r.hotspot_dns_name, r.name AS router_name
               FROM online_orders oo JOIN offers o ON o.id = oo.offer_id JOIN payment_methods pm ON pm.id = oo.payment_method_id JOIN routers r ON r.id = oo.router_id
              WHERE oo.reference = :r', ['r' => $reference]);
        if (!$o || !hash_equals($o['access_token'], hash('sha256', $token))) {
            return null;
        }
        if ($o['ticket_id']) {
            $t = Database::fetch('SELECT username, password_enc FROM tickets WHERE id = :id', ['id' => $o['ticket_id']]);
            $o['ticket_username'] = $t['username'];
            $o['ticket_password'] = Crypto::decrypt($t['password_enc']);
        }
        return $o;
    }

    /**
     * Confirme le paiement et attribue un ticket (transaction, verrouillage, idempotence).
     */
    public static function confirm(int $orderId, string $providerRef, string $via, ?int $userId = null, ?float $amount = null): void
    {
        $providerRef = trim($providerRef);
        if ($providerRef === '') {
            throw new RuntimeException('Référence de transaction obligatoire.');
        }
        Database::transaction(static function () use ($orderId, $providerRef, $via, $userId, $amount) {
            $o = Database::fetch('SELECT * FROM online_orders WHERE id = :id FOR UPDATE', ['id' => $orderId]);
            if (!$o) {
                throw new RuntimeException('Commande introuvable.');
            }
            if (in_array($o['status'], ['paid', 'delivered'], true)) {
                return; // Déjà traitée (webhook rejoué).
            }
            if ($amount !== null && abs($amount - (float) $o['amount']) > 0.01) {
                throw new RuntimeException('Montant payé différent du montant de la commande.');
            }
            $method = Database::fetch('SELECT * FROM payment_methods WHERE id = :id', ['id' => $o['payment_method_id']]);
            $provider = $method['provider'] ?: $method['code'];
            if (Database::value('SELECT id FROM payments WHERE provider = :p AND provider_reference = :r', ['p' => $provider, 'r' => $providerRef])) {
                throw new RuntimeException('Cette référence de transaction a déjà été utilisée.');
            }
            $ticket = Database::fetch(
                "SELECT * FROM tickets WHERE offer_id = :o AND router_id = :r AND status = 'available' AND on_router = 1 AND reseller_id IS NULL ORDER BY id LIMIT 1 FOR UPDATE",
                ['o' => $o['offer_id'], 'r' => $o['router_id']]);
            if (!$ticket) {
                throw new RuntimeException('Plus aucun ticket disponible pour cette offre : générez du stock puis confirmez à nouveau.');
            }
            $saleId = Database::insert('sales', [
                'reference' => SaleService::reference('W'), 'channel' => 'online', 'cashier_id' => $userId, 'router_id' => $o['router_id'],
                'customer_phone' => $o['customer_phone'], 'subtotal' => $o['amount'], 'total' => $o['amount'],
                'payment_method_id' => $o['payment_method_id'], 'payment_status' => 'paid', 'status' => 'completed', 'notes' => 'Commande ' . $o['reference'],
            ]);
            Database::insert('sale_items', ['sale_id' => $saleId, 'ticket_id' => $ticket['id'], 'offer_id' => $o['offer_id'], 'unit_price' => $o['amount'], 'total' => $o['amount']]);
            Database::update('tickets', ['status' => 'sold', 'sale_id' => $saleId, 'sold_at' => date('Y-m-d H:i:s'), 'customer_phone' => $o['customer_phone']], 'id = :id', ['id' => $ticket['id']]);
            Database::insert('payments', [
                'sale_id' => $saleId, 'online_order_id' => $o['id'], 'payment_method_id' => $o['payment_method_id'], 'provider' => $provider,
                'provider_reference' => $providerRef, 'amount' => $o['amount'], 'phone' => $o['customer_phone'], 'status' => 'confirmed',
                'confirmed_at' => date('Y-m-d H:i:s'), 'confirmed_via' => $via, 'created_by' => $userId,
            ]);
            Database::update('online_orders', ['status' => 'delivered', 'sale_id' => $saleId, 'ticket_id' => $ticket['id'], 'delivered_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $o['id']]);
            AuditService::log('online.confirm', 'online_order', (int) $o['id'], "Paiement {$o['reference']} confirmé ({$via}, réf. {$providerRef})", null, null, $userId);
        });
    }

    /**
     * Webhook prestataire : JSON {event_id, order_reference, provider_reference, status, amount}
     * signé par HMAC-SHA256 du corps brut (en-tête X-Signature) avec le secret du moyen de paiement.
     */
    public static function handleWebhook(string $provider, string $body, ?string $signature, ?string $ip, array $headers): array
    {
        $method = Database::fetch('SELECT * FROM payment_methods WHERE (provider = :p OR code = :c) AND is_active = 1 LIMIT 1', ['p' => $provider, 'c' => $provider]);
        if (!$method) {
            return [404, 'Prestataire inconnu'];
        }
        $secret = (string) (self::methodConfig($method)['webhook_secret'] ?? '');
        $valid = $secret !== '' && is_string($signature) && hash_equals(hash_hmac('sha256', $body, $secret), strtolower(trim($signature)));
        $data = json_decode($body, true);
        $eventId = is_array($data) ? (string) ($data['event_id'] ?? $data['id'] ?? '') : '';
        if ($eventId === '') {
            $eventId = hash('sha256', $body);
        }
        $exists = Database::fetch('SELECT * FROM webhook_events WHERE provider = :p AND event_id = :e', ['p' => $provider, 'e' => $eventId]);
        if ($exists && $exists['processed']) {
            return [200, 'Déjà traité'];
        }
        $wid = $exists['id'] ?? Database::insert('webhook_events', [
            'provider' => $provider, 'event_id' => mb_substr($eventId, 0, 150), 'signature_valid' => $valid ? 1 : 0,
            'payload' => mb_substr($body, 0, 60000), 'headers' => json_encode($headers), 'ip_address' => $ip,
        ]);
        if (!$valid) {
            Logger::warning("Webhook {$provider} rejeté : signature invalide", ['ip' => $ip], 'payments');
            Database::update('webhook_events', ['error' => 'Signature invalide'], 'id = :id', ['id' => $wid]);
            return [401, 'Signature invalide'];
        }
        try {
            $status = strtolower((string) ($data['status'] ?? ''));
            $order = Database::fetch('SELECT id FROM online_orders WHERE reference = :r', ['r' => (string) ($data['order_reference'] ?? '')]);
            if (!$order) {
                throw new RuntimeException('Commande inconnue');
            }
            if (in_array($status, ['success', 'successful', 'paid', 'completed', 'confirmed'], true)) {
                self::confirm((int) $order['id'], (string) ($data['provider_reference'] ?? ''), 'webhook', null, isset($data['amount']) ? (float) $data['amount'] : null);
            } elseif (in_array($status, ['failed', 'cancelled', 'canceled', 'rejected'], true)) {
                Database::query("UPDATE online_orders SET status = 'failed' WHERE id = :id AND status IN ('created','awaiting_payment')", ['id' => $order['id']]);
            }
            Database::update('webhook_events', ['processed' => 1, 'processed_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $wid]);
            return [200, 'OK'];
        } catch (\Throwable $e) {
            Database::update('webhook_events', ['error' => mb_substr($e->getMessage(), 0, 1000)], 'id = :id', ['id' => $wid]);
            return [422, $e->getMessage()];
        }
    }
}
