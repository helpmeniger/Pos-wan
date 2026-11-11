<?php
declare(strict_types=1);

namespace Addons\Fiscal\Drivers;

use Addons\Fiscal\DriverResult;

/**
 * Connecteur de SIMULATION : reproduit le comportement d'un dispositif pour tester POS Marwa.
 * Aucune valeur fiscale : numéros « SIMU- », documents marqués « NON CERTIFIÉ – TEST ».
 * Scénario (config.scenario) : accept, reject, unreachable, timeout, invalid, auth_fail, lost_response.
 * L'état du faux dispositif (factures déjà reçues) est gardé dans storage/fiscal/simulation-<id>.json
 * pour reproduire la détection des doublons.
 */
final class SimulationDriver extends AbstractDriver
{
    public const MARK = 'NON CERTIFIÉ – TEST';

    public static function info(): array
    {
        return ['code' => 'simulation', 'label' => 'Simulation (test)', 'kind' => 'simulation', 'transports' => ['none'], 'verified' => false,
            'notes' => 'Outil de test sans valeur fiscale. Les documents portent la mention « ' . self::MARK . ' ».'];
    }

    public static function capabilities(): array
    {
        return ['connect', 'testConnection', 'authenticate', 'certifyInvoice', 'getInvoiceStatus', 'getCertificate', 'getDeviceInfo', 'getDailyReport', 'closeDay', 'issueCreditNote'];
    }

    private function scenario(): string
    {
        return (string) ($this->config['scenario'] ?? 'accept');
    }

    private function file(): string
    {
        $dir = STORAGE_PATH . '/fiscal';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        return $dir . '/simulation-' . (int) ($this->connector['id'] ?? 0) . '.json';
    }

    /** Lecture/écriture atomique de l'état du faux dispositif. */
    private function state(?callable $change = null): array
    {
        $h = fopen($this->file(), 'c+');
        flock($h, LOCK_EX);
        $raw = stream_get_contents($h);
        $s = json_decode($raw ?: '', true) ?: ['counter' => 0, 'invoices' => [], 'closed' => []];
        if ($change) {
            $s = $change($s);
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, json_encode($s, JSON_UNESCAPED_UNICODE));
        }
        flock($h, LOCK_UN);
        fclose($h);
        return $s;
    }

    private function down(): ?DriverResult
    {
        return match ($this->scenario()) {
            'unreachable' => DriverResult::fail(DriverResult::UNREACHABLE, 'Simulation : dispositif injoignable.'),
            'timeout' => DriverResult::fail(DriverResult::TIMEOUT, 'Simulation : délai de réponse dépassé.'),
            'auth_fail' => DriverResult::fail(DriverResult::AUTH_FAILED, 'Simulation : authentification refusée.'),
            default => null,
        };
    }

    public function connect(): DriverResult
    {
        return $this->down() ?? DriverResult::ok([], 'Simulation : connecté.');
    }

    public function testConnection(): DriverResult
    {
        return $this->down() ?? DriverResult::ok(['device_id' => 'SIMU-MCF-0001'], 'Simulation : dispositif de test joignable (' . self::MARK . ').');
    }

    public function authenticate(): DriverResult
    {
        return $this->down() ?? DriverResult::ok([], 'Simulation : authentifié.');
    }

    public function getDeviceInfo(): DriverResult
    {
        return $this->down() ?? DriverResult::ok(['device_id' => 'SIMU-MCF-0001', 'serial_number' => 'SIMU-0001', 'model' => 'Dispositif simulé', 'firmware' => 'test', 'simulation' => true]);
    }

    private function sign(array $invoice, string $number): string
    {
        return strtoupper(substr(hash_hmac('sha256', $number . '|' . ($invoice['idempotency_key'] ?? '') . '|' . ($invoice['totals']['ttc'] ?? 0), 'simulation-sans-valeur'), 0, 32));
    }

    public function certifyInvoice(array $invoice): DriverResult
    {
        $req = json_encode($invoice, JSON_UNESCAPED_UNICODE);
        if ($d = $this->down()) {
            return new DriverResult($d->code, $d->message, [], $req);
        }
        if (empty($invoice['idempotency_key']) || empty($invoice['lines'])) {
            return DriverResult::fail(DriverResult::REJECTED, 'Simulation : facture incomplète (lignes ou clé manquantes).', ['reason' => 'incomplete'], $req);
        }
        if ($this->scenario() === 'reject') {
            return DriverResult::fail(DriverResult::REJECTED, 'Simulation : facture refusée par le dispositif (scénario de test).', ['reason' => 'test_reject'], $req, '{"status":"rejected"}');
        }
        if ($this->scenario() === 'invalid') {
            // Réponse « positive » mais sans numéro ni signature : le moteur doit la refuser.
            return new DriverResult(DriverResult::CERTIFIED, 'Simulation : réponse incomplète.', ['status' => 'ok'], $req, '{"status":"ok"}');
        }
        $key = (string) $invoice['idempotency_key'];
        $state = $this->state(function (array $s) use ($invoice, $key) {
            if (!isset($s['invoices'][$key])) {
                $s['counter']++;
                $num = 'SIMU-' . date('Ymd') . '-' . str_pad((string) $s['counter'], 6, '0', STR_PAD_LEFT);
                $s['invoices'][$key] = ['fiscal_number' => $num, 'device_id' => 'SIMU-MCF-0001', 'signature' => $this->sign($invoice, $num),
                    'counters' => $s['counter'] . '/' . $s['counter'], 'certified_at' => date('Y-m-d H:i:s'), 'total' => (float) ($invoice['totals']['ttc'] ?? 0),
                    'type' => $invoice['type'] ?? 'invoice', 'date' => date('Y-m-d')];
            } else {
                $s['invoices'][$key]['duplicate_hits'] = ($s['invoices'][$key]['duplicate_hits'] ?? 0) + 1;
            }
            return $s;
        });
        $c = $state['invoices'][$key];
        if ($this->scenario() === 'lost_response') {
            // Le dispositif a certifié mais la réponse n'est pas arrivée.
            return DriverResult::fail(DriverResult::TIMEOUT, 'Simulation : réponse perdue après certification.', [], $req);
        }
        $data = $c + ['qr_payload' => 'SIMULATION|' . self::MARK . '|' . $c['fiscal_number'] . '|' . $c['signature'], 'simulation' => true, 'duplicate' => isset($c['duplicate_hits'])];
        return new DriverResult(DriverResult::CERTIFIED, 'Simulation : facture acceptée (' . self::MARK . ').', $data, $req, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function issueCreditNote(array $creditNote): DriverResult
    {
        if (empty($creditNote['original']['fiscal_number'])) {
            return DriverResult::fail(DriverResult::REJECTED, 'Simulation : avoir refusé, la facture d\'origine n\'est pas certifiée.');
        }
        return $this->certifyInvoice($creditNote);
    }

    public function getInvoiceStatus(string $idempotencyKey): DriverResult
    {
        if (in_array($this->scenario(), ['unreachable', 'timeout', 'auth_fail'], true)) {
            return $this->down();
        }
        $c = $this->state()['invoices'][$idempotencyKey] ?? null;
        if (!$c) {
            return DriverResult::fail(DriverResult::NOT_FOUND, 'Simulation : facture inconnue du dispositif.');
        }
        $data = $c + ['qr_payload' => 'SIMULATION|' . self::MARK . '|' . $c['fiscal_number'] . '|' . $c['signature'], 'simulation' => true];
        return new DriverResult(DriverResult::CERTIFIED, 'Simulation : facture déjà enregistrée par le dispositif.', $data, null, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function getCertificate(string $fiscalNumber): DriverResult
    {
        foreach ($this->state()['invoices'] as $c) {
            if ($c['fiscal_number'] === $fiscalNumber) {
                return DriverResult::ok($c + ['simulation' => true]);
            }
        }
        return DriverResult::fail(DriverResult::NOT_FOUND, 'Simulation : numéro inconnu.');
    }

    public function getDailyReport(string $date): DriverResult
    {
        $rows = array_filter($this->state()['invoices'], fn($c) => ($c['date'] ?? '') === $date);
        $inv = array_filter($rows, fn($c) => ($c['type'] ?? 'invoice') === 'invoice');
        $cn = array_filter($rows, fn($c) => ($c['type'] ?? '') === 'credit_note');
        return DriverResult::ok(['date' => $date, 'invoices' => count($inv), 'invoices_total' => array_sum(array_column($inv, 'total')),
            'credit_notes' => count($cn), 'credit_notes_total' => array_sum(array_column($cn, 'total')), 'simulation' => true]);
    }

    public function closeDay(string $date): DriverResult
    {
        $r = $this->getDailyReport($date);
        $this->state(function (array $s) use ($date) { $s['closed'][$date] = date('Y-m-d H:i:s'); return $s; });
        return DriverResult::ok($r->data + ['closed' => true], 'Simulation : journée ' . $date . ' clôturée (' . self::MARK . ').');
    }
}
