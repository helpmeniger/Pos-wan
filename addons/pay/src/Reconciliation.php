<?php
declare(strict_types=1);

namespace Addons\Pay;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Rapprochement des paiements avec un relevé du prestataire ou de la banque (CSV).
 * Colonnes reconnues (en-tête, ordre libre) : reference, amount, currency, date, status.
 * Seule une ligne de relevé dont le montant concorde peut confirmer une transaction « À vérifier » (TPE autonome…).
 */
final class Reconciliation
{
    private const ALIASES = ['reference' => ['reference', 'ref', 'référence', 'transaction', 'id', 'autorisation', 'authorization'], 'amount' => ['amount', 'montant'],
        'currency' => ['currency', 'devise'], 'date' => ['date', 'op_date', 'date_operation'], 'status' => ['status', 'statut', 'etat', 'état']];
    public const OK_STATUSES = ['', 'success', 'succeeded', 'paid', 'ok', 'accepted', 'réussi', 'reussi', 'approved', 'approuvé', 'complete', 'completed'];

    public static function import(string $csv, ?int $connectorId, string $sourceName): array
    {
        if (strlen($csv) > 5_000_000) {
            throw new BusinessException('Fichier trop volumineux (5 Mo au plus).');
        }
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        if (count($lines) < 2) {
            throw new BusinessException('Relevé vide : une ligne d\'en-tête et au moins une opération sont attendues.');
        }
        $sep = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $head = array_map(fn($h) => mb_strtolower(trim((string) $h)), str_getcsv($lines[0], $sep));
        $col = [];
        foreach (self::ALIASES as $k => $names) {
            foreach ($head as $i => $h) {
                if (in_array($h, $names, true)) {
                    $col[$k] = $i;
                    break;
                }
            }
        }
        if (!isset($col['reference'], $col['amount'])) {
            throw new BusinessException('Colonnes « reference » et « amount » (ou « référence », « montant ») obligatoires.');
        }
        $batch = date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $stats = ['matched' => 0, 'amount_mismatch' => 0, 'status_mismatch' => 0, 'missing_local' => 0, 'missing_provider' => 0, 'confirmed' => 0];
        $seen = [];
        $dates = [];
        for ($n = 1; $n < count($lines) && $n <= 20000; $n++) {
            if (trim($lines[$n]) === '') {
                continue;
            }
            $row = str_getcsv($lines[$n], $sep);
            $ref = mb_substr(trim((string) ($row[$col['reference']] ?? '')), 0, 120);
            $amt = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string) ($row[$col['amount']] ?? ''));
            if ($ref === '' || !is_numeric($amt)) {
                continue;
            }
            $amt = round((float) $amt, 2);
            $cur = isset($col['currency']) ? strtoupper(substr(trim((string) ($row[$col['currency']] ?? '')), 0, 3)) : null;
            $ds = isset($col['date']) ? trim((string) ($row[$col['date']] ?? '')) : '';
            $date = $ds !== '' && ($ts = strtotime(str_replace('/', '-', $ds))) ? date('Y-m-d', $ts) : null;
            if ($date) {
                $dates[] = $date;
            }
            $ext = isset($col['status']) ? mb_strtolower(trim((string) ($row[$col['status']] ?? ''))) : '';
            $tx = DB::one('SELECT * FROM payment_transactions WHERE (provider_reference = ? OR authorization_code = ? OR number = ?)' . ($connectorId ? ' AND (connector_id = ? OR terminal_id IS NOT NULL)' : '') . ' ORDER BY id DESC LIMIT 1',
                $connectorId ? [$ref, $ref, $ref, $connectorId] : [$ref, $ref, $ref]);
            $result = 'missing_local';
            if ($tx) {
                $seen[] = (int) $tx['id'];
                $expected = (float) ($tx['provider_amount'] ?? $tx['amount']);
                if (abs($expected - $amt) > 0.009) {
                    $result = 'amount_mismatch';
                } elseif (!in_array($ext, self::OK_STATUSES, true)) {
                    $result = 'status_mismatch';
                } elseif (in_array($tx['status'], ['succeeded', 'refunded', 'partially_refunded'], true)) {
                    $result = 'matched';
                } elseif ($tx['status'] === 'to_verify' || $tx['status'] === 'expired') {
                    // Relevé du prestataire concordant : la transaction est confirmée
                    DB::run("UPDATE payment_transactions SET status = 'succeeded', verified_by = 'reconciliation', succeeded_at = COALESCE(succeeded_at, NOW()), message = ?, updated_at = NOW() WHERE id = ? AND status IN ('to_verify','expired')",
                        ['Confirmé par rapprochement (' . mb_substr($sourceName, 0, 80) . ', ligne ' . ($n + 1) . ').', $tx['id']]);
                    Audit::log('pay.reconciled', 'payment_transaction', (int) $tx['id'], ['batch' => $batch, 'line' => $n + 1]);
                    $result = 'matched';
                    $stats['confirmed']++;
                } else {
                    $result = 'status_mismatch';
                }
            }
            $stats[$result]++;
            DB::insert('payment_reconciliation', ['batch' => $batch, 'connector_id' => $connectorId, 'source_name' => mb_substr($sourceName, 0, 160), 'line_no' => $n + 1,
                'provider_reference' => $ref, 'amount' => $amt, 'currency' => $cur ?: null, 'op_date' => $date, 'external_status' => mb_substr($ext, 0, 40) ?: null,
                'transaction_id' => $tx['id'] ?? null, 'result' => $result, 'created_by' => Auth::id()]);
        }
        // Paiements réussis chez nous mais absents du relevé (sur la période couverte)
        if ($dates) {
            $w = "status IN ('succeeded','refunded','partially_refunded') AND is_test = 0 AND DATE(COALESCE(succeeded_at, created_at)) BETWEEN ? AND ?";
            $p = [min($dates), max($dates)];
            if ($connectorId) {
                $w .= ' AND connector_id = ?';
                $p[] = $connectorId;
            }
            foreach (DB::all("SELECT id, provider_reference, provider_amount, provider_currency, DATE(COALESCE(succeeded_at, created_at)) d FROM payment_transactions WHERE $w", $p) as $t) {
                if (!in_array((int) $t['id'], $seen, true)) {
                    DB::insert('payment_reconciliation', ['batch' => $batch, 'connector_id' => $connectorId, 'source_name' => mb_substr($sourceName, 0, 160), 'provider_reference' => $t['provider_reference'],
                        'amount' => $t['provider_amount'], 'currency' => $t['provider_currency'], 'op_date' => $t['d'], 'transaction_id' => $t['id'], 'result' => 'missing_provider', 'created_by' => Auth::id()]);
                    $stats['missing_provider']++;
                }
            }
        }
        Audit::log('pay.reconciliation', null, null, ['batch' => $batch, 'source' => $sourceName] + $stats);
        return ['batch' => $batch] + $stats;
    }

    public static function resolve(int $id, string $note): void
    {
        if (mb_strlen(trim($note)) < 3) {
            throw new BusinessException('Indiquez comment l\'écart a été traité.');
        }
        DB::run('UPDATE payment_reconciliation SET resolved = 1, note = ? WHERE id = ?', [mb_substr(trim($note), 0, 255), $id]);
        Audit::log('pay.reconciliation_resolve', 'payment_reconciliation', $id, ['note' => $note]);
    }
}
