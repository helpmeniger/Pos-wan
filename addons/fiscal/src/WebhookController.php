<?php
declare(strict_types=1);

namespace Addons\Fiscal;

use App\Core\DB;

/**
 * Réception des notifications d'un service fiscal. Authentification :
 *  - jeton secret dans l'adresse (?t=…) ;
 *  - en-tête X-Fiscal-Signature: sha256=<HMAC-SHA256 du corps avec le secret du webhook>.
 * Le contenu n'est jamais cru sur parole : il déclenche seulement une interrogation du dispositif (FiscalEngine::confirm).
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
        $w = FiscalEngine::enabled() ? DB::one('SELECT * FROM fiscal_webhooks WHERE id = ? AND active = 1', [$id]) : null;
        $token = (string) ($_GET['t'] ?? '');
        if (!$w || !hash_equals((string) $w['token'], $token)) {
            $out(404, ['ok' => false]);
        }
        $body = (string) file_get_contents('php://input', false, null, 0, 1_000_000);
        $sig = (string) ($_SERVER['HTTP_X_FISCAL_SIGNATURE'] ?? '');
        $expected = 'sha256=' . hash_hmac('sha256', $body, Secrets::decrypt((string) $w['secret_enc']));
        if (!hash_equals($expected, $sig)) {
            FiscalEngine::log(null, (int) $w['connector_id'], 'webhook', 'auth_failed', 401, null, 'Signature de webhook invalide.');
            $out(401, ['ok' => false, 'error' => 'signature']);
        }
        DB::run('UPDATE fiscal_webhooks SET last_received_at = NOW() WHERE id = ?', [$id]);
        $j = json_decode($body, true);
        $key = is_array($j) ? (string) ($j['idempotency_key'] ?? '') : '';
        $cert = preg_match('/^[a-f0-9]{64}$/', $key) ? DB::one('SELECT id FROM fiscal_certifications WHERE idempotency_key = ? AND connector_id = ?', [$key, $w['connector_id']]) : null;
        FiscalEngine::log($cert ? (int) $cert['id'] : null, (int) $w['connector_id'], 'webhook', $cert ? 'ok' : 'not_found', 200, null, 'Notification reçue.', null, mb_substr((string) Secrets::redact($body), 0, 20000));
        if (!$cert) {
            $out(200, ['ok' => true, 'matched' => false]);
        }
        $r = FiscalEngine::confirm((int) $cert['id']);
        $out(200, ['ok' => true, 'matched' => true, 'status' => $r['status']]);
    }
}
