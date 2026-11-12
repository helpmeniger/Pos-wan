<?php
declare(strict_types=1);

namespace Addons\Pay;

use App\Core\DB;

/**
 * Notifications des prestataires : jeton dans l'adresse + signature vérifiée par le connecteur
 * (HMAC du corps avec le secret du prestataire ou celui du webhook). Une notification dédoublonnée
 * déclenche une vérification serveur à serveur quand le prestataire le permet.
 */
final class WebhookController
{
    public function receive(int $id): void
    {
        $out = function (int $code, array $data): never {
            http_response_code($code);
            header('Content-Type: application/json');
            echo json_encode($data);
            exit;
        };
        $w = PayEngine::enabled() ? DB::one('SELECT * FROM payment_webhooks WHERE id = ? AND active = 1', [$id]) : null;
        if (!$w || !hash_equals((string) $w['token'], (string) ($_GET['t'] ?? ''))) {
            $out(404, ['ok' => false]);
        }
        $connector = DB::one('SELECT * FROM payment_connectors WHERE id = ? AND active = 1', [$w['connector_id']]);
        if (!$connector) {
            $out(404, ['ok' => false]);
        }
        $body = (string) file_get_contents('php://input', false, null, 0, 1_000_000);
        $headers = function_exists('getallheaders') ? (array) getallheaders() : [];
        $driver = DriverRegistry::make($connector);
        if (!method_exists($driver, 'webhookSignatureValid') || !$driver->webhookSignatureValid($body, $headers, Secrets::decrypt((string) $w['secret_enc']))) {
            PayEngine::log(null, null, (int) $connector['id'], null, 'webhook', 'auth_failed', 401, null, 'Signature de notification invalide.');
            $out(401, ['ok' => false, 'error' => 'signature']);
        }
        DB::run('UPDATE payment_webhooks SET last_received_at = NOW() WHERE id = ?', [$id]);
        $r = PayEngine::webhook($connector, $body, $headers);
        $out(200, ['ok' => true] + $r);
    }
}
