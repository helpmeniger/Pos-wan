<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers;

use Addons\Pay\GatewayResult;

/**
 * Prestataire SIMULÉ pour les tests : aucune somme n'est débitée, tout est marqué TEST.
 * config.scenario : success, decline, pending (réussit après config.pending_polls vérifications), pending_forever,
 * network_loss (le prestataire encaisse mais la réponse se perd), invalid (réponse positive avec un montant faux),
 * refund_decline (remboursement refusé), refund_pending (remboursement confirmé à la vérification suivante).
 * L'état du « prestataire » est conservé dans storage/pay/simulation-<id>.json.
 */
class SimulationDriver extends AbstractDriver
{
    public const MARK = 'TEST – AUCUN ENCAISSEMENT RÉEL';

    public static function info(): array
    {
        return ['code' => 'simulation', 'label' => 'Simulation (TEST)', 'kind' => 'simulation', 'transports' => ['none'], 'verified' => false,
            'notes' => 'Prestataire fictif pour tester la caisse et les remboursements. ' . self::MARK . '.'];
    }

    public static function capabilities(): array
    {
        return ['testConnection', 'initiate', 'getStatus', 'cancel', 'void', 'refund', 'getRefundStatus', 'webhook'];
    }

    protected function stateName(): string
    {
        return 'simulation-' . (int) ($this->target['id'] ?? 0);
    }

    protected function sc(): string
    {
        return (string) ($this->config['scenario'] ?? 'success');
    }

    private function state(?callable $change = null): array
    {
        $dir = STORAGE_PATH . '/pay';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $h = fopen($dir . '/' . $this->stateName() . '.json', 'c+');
        flock($h, LOCK_EX);
        $s = json_decode(stream_get_contents($h) ?: '', true) ?: ['n' => 0, 'tx' => [], 'refunds' => []];
        if ($change) {
            $s = $change($s);
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, json_encode($s));
        }
        flock($h, LOCK_UN);
        fclose($h);
        return $s;
    }

    public function testConnection(): GatewayResult
    {
        return GatewayResult::make(GatewayResult::OK, 'Simulation : prestataire de test joignable (' . self::MARK . ').');
    }

    private function data(array $t): array
    {
        return ['provider_reference' => $t['ref'], 'authorization_code' => $t['auth'] ?? null, 'amount' => $t['amount'], 'currency' => $t['currency'],
            'fees' => round($t['amount'] * 0.01, 2), 'simulation' => true];
    }

    public function initiate(array $p): GatewayResult
    {
        $req = json_encode($p, JSON_UNESCAPED_UNICODE);
        $sc = $this->sc();
        $key = (string) $p['idempotency_key'];
        $s = $this->state(function (array $s) use ($p, $key, $sc) {
            if (!isset($s['tx'][$key])) { // idempotence côté prestataire
                $s['n']++;
                $status = match ($sc) { 'decline' => 'declined', 'pending', 'pending_forever' => 'pending', default => 'succeeded' };
                $s['tx'][$key] = ['ref' => 'SIMU-' . date('Ymd') . '-' . str_pad((string) $s['n'], 6, '0', STR_PAD_LEFT), 'status' => $status,
                    'amount' => (float) $p['amount'], 'currency' => $p['currency'], 'polls' => 0, 'auth' => $status === 'succeeded' ? strtoupper(substr(md5($key), 0, 6)) : null];
            }
            return $s;
        });
        $t = $s['tx'][$key];
        if ($sc === 'network_loss') {
            return GatewayResult::make(GatewayResult::TIMEOUT, 'Simulation : connexion perdue après l\'envoi de la demande.', [], $req);
        }
        $d = $this->data($t);
        if ($sc === 'invalid') {
            $d['amount'] = $t['amount'] + 1000; // montant incohérent : le moteur doit refuser
        }
        $resp = json_encode($d);
        return match ($t['status']) {
            'succeeded' => GatewayResult::make(GatewayResult::SUCCEEDED, 'Simulation : paiement accepté (' . self::MARK . ').', $d, $req, $resp),
            'declined' => GatewayResult::make(GatewayResult::DECLINED, 'Simulation : paiement refusé (fonds insuffisants).', $d, $req, $resp),
            default => GatewayResult::make(GatewayResult::PENDING, 'Simulation : en attente de validation par le client.', $d + ['payer_hint' => 'Validez sur le téléphone'], $req, $resp),
        };
    }

    public function getStatus(array $tx): GatewayResult
    {
        $key = (string) $tx['idempotency_key'];
        $polls = max(0, (int) ($this->config['pending_polls'] ?? 2));
        $s = $this->state(function (array $s) use ($key, $polls) {
            if (isset($s['tx'][$key]) && $s['tx'][$key]['status'] === 'pending' && $this->sc() === 'pending') {
                $s['tx'][$key]['polls']++;
                if ($s['tx'][$key]['polls'] >= $polls) {
                    $s['tx'][$key]['status'] = 'succeeded';
                    $s['tx'][$key]['auth'] = strtoupper(substr(md5($key), 0, 6));
                }
            }
            return $s;
        });
        $t = $s['tx'][$key] ?? null;
        if (!$t) {
            return GatewayResult::make(GatewayResult::NOT_FOUND, 'Simulation : transaction inconnue du prestataire.');
        }
        $d = $this->data($t);
        return GatewayResult::make($t['status'], 'Simulation : état « ' . $t['status'] . ' ».', $d, null, json_encode($d));
    }

    public function cancel(array $tx): GatewayResult
    {
        $key = (string) $tx['idempotency_key'];
        $s = $this->state(function (array $s) use ($key) {
            if (isset($s['tx'][$key]) && in_array($s['tx'][$key]['status'], ['pending', 'succeeded'], true)) {
                $s['tx'][$key]['status'] = 'cancelled';
            }
            return $s;
        });
        $t = $s['tx'][$key] ?? null;
        return $t && $t['status'] === 'cancelled' ? GatewayResult::make(GatewayResult::CANCELLED, 'Simulation : paiement annulé.', $this->data($t))
            : GatewayResult::make(GatewayResult::DECLINED, 'Simulation : annulation impossible.');
    }

    public function refund(array $tx, array $r): GatewayResult
    {
        $req = json_encode($r, JSON_UNESCAPED_UNICODE);
        if ($this->sc() === 'refund_decline') {
            return GatewayResult::make(GatewayResult::DECLINED, 'Simulation : remboursement refusé par le prestataire.', [], $req);
        }
        $key = (string) $r['idempotency_key'];
        $s = $this->state(function (array $s) use ($key, $r) {
            $s['refunds'][$key] ??= ['ref' => 'SIMU-RMB-' . substr(md5($key), 0, 8), 'status' => $this->sc() === 'refund_pending' ? 'pending' : 'refunded', 'amount' => (float) $r['amount']];
            return $s;
        });
        $f = $s['refunds'][$key];
        return GatewayResult::make($f['status'] === 'refunded' ? GatewayResult::REFUNDED : GatewayResult::PENDING, 'Simulation : remboursement ' . ($f['status'] === 'refunded' ? 'effectué' : 'en cours') . '.',
            ['provider_reference' => $f['ref'], 'amount' => $f['amount'], 'simulation' => true], $req, json_encode($f));
    }

    public function getRefundStatus(array $tx, array $refund): GatewayResult
    {
        $key = (string) $refund['idempotency_key'];
        $s = $this->state(function (array $s) use ($key) {
            if (isset($s['refunds'][$key])) {
                $s['refunds'][$key]['status'] = 'refunded';
            }
            return $s;
        });
        $f = $s['refunds'][$key] ?? null;
        return $f ? GatewayResult::make(GatewayResult::REFUNDED, 'Simulation : remboursement effectué.', ['provider_reference' => $f['ref'], 'amount' => $f['amount'], 'simulation' => true])
            : GatewayResult::make(GatewayResult::NOT_FOUND, 'Simulation : remboursement inconnu.');
    }

    public function parseWebhook(string $body, array $headers): ?array
    {
        $j = json_decode($body, true);
        return is_array($j) && !empty($j['reference']) ? ['reference' => (string) $j['reference'], 'status' => (string) ($j['status'] ?? ''), 'amount' => $j['amount'] ?? null,
            'currency' => $j['currency'] ?? null, 'event_id' => (string) ($j['event_id'] ?? '')] : null;
    }
}
