<?php
declare(strict_types=1);

namespace Addons\Banques;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Validator;
use App\Services\Sequence;

/**
 * Banques et coffres : l'argent qui sort de la caisse.
 * Chaque opération est une ligne d'historique ; le solde du compte est mis à jour dans la même
 * transaction (compte verrouillé FOR UPDATE). Une opération n'est jamais effacée : elle est
 * annulée avec un motif, ce qui rétablit le solde.
 */
final class BankService
{
    public const KINDS = ['banque' => 'Compte bancaire', 'coffre' => 'Coffre / lieu sûr', 'proprietaire' => 'Argent gardé par le propriétaire', 'mobile' => 'Compte mobile money', 'autre' => 'Autre'];
    public const KIND_ICONS = ['banque' => 'bi-bank', 'coffre' => 'bi-safe', 'proprietaire' => 'bi-person-badge', 'mobile' => 'bi-phone', 'autre' => 'bi-wallet2'];
    public const TYPES = [
        'versement' => 'Versement de clôture de caisse', 'depot' => 'Dépôt', 'retrait' => 'Retrait',
        'virement_sortant' => 'Virement vers un autre compte', 'virement_entrant' => 'Virement reçu d\'un autre compte',
        'frais' => 'Frais bancaires', 'interets' => 'Intérêts', 'ajustement' => 'Ajustement de solde',
    ];
    public const METHODS = ['especes' => 'Espèces', 'cheque' => 'Chèque', 'virement' => 'Virement', 'mobile' => 'Mobile money', 'autre' => 'Autre'];

    // ------------------------------------------------------------------ comptes

    public static function accounts(bool $activeOnly = false): array
    {
        return DB::all('SELECT * FROM bank_accounts' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY active DESC, FIELD(kind, \'banque\', \'coffre\', \'proprietaire\', \'mobile\', \'autre\'), name');
    }

    public static function account(int $id): array
    {
        $a = DB::one('SELECT * FROM bank_accounts WHERE id = ?', [$id]);
        if (!$a) {
            throw new BusinessException('Compte introuvable.');
        }
        return $a;
    }

    /** Création ou modification d'un compte (le solde ne se modifie que par des opérations). */
    public static function saveAccount(array $in, ?int $id = null): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            throw new BusinessException('Nom du compte obligatoire (ex. « BIA Niger — compte courant », « Coffre du magasin »).');
        }
        $kind = (string) ($in['kind'] ?? 'banque');
        if (!isset(self::KINDS[$kind])) {
            throw new BusinessException('Type de compte inconnu.');
        }
        if (DB::value('SELECT id FROM bank_accounts WHERE name = ? AND id <> ?', [$name, (int) $id])) {
            throw new BusinessException('Un compte porte déjà ce nom.');
        }
        $data = ['name' => $name, 'kind' => $kind,
            'bank_name' => mb_substr(trim((string) ($in['bank_name'] ?? '')), 0, 100) ?: null,
            'account_number' => mb_substr(trim((string) ($in['account_number'] ?? '')), 0, 60) ?: null,
            'holder' => mb_substr(trim((string) ($in['holder'] ?? '')), 0, 120) ?: null,
            'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 255) ?: null];
        if ($id) {
            self::account($id);
            $data['active'] = empty($in['active']) ? 0 : 1;
            if (!$data['active'] && abs((float) DB::value('SELECT balance FROM bank_accounts WHERE id = ?', [$id])) > 0.004) {
                throw new BusinessException('Ce compte a encore un solde : videz-le (retrait ou virement) avant de le désactiver.');
            }
            DB::update('bank_accounts', $data, 'id = ?', [$id]);
            Audit::log('banques.compte_modifie', 'bank_account', $id, ['nom' => $name]);
            return $id;
        }
        $opening = self::amount($in['opening_balance'] ?? 0, true);
        return DB::transaction(function () use ($data, $opening) {
            $id = DB::insert('bank_accounts', $data + ['opening_balance' => $opening, 'balance' => 0, 'created_by' => Auth::id()]);
            if ($opening > 0) {
                // Solde de départ : une opération « ajustement » visible dans le relevé
                self::post($id, 'ajustement', 1, $opening, ['description' => 'Solde à l\'ouverture du compte dans POS Marwa', 'method' => 'autre']);
            }
            Audit::log('banques.compte_cree', 'bank_account', $id, ['nom' => $data['name'], 'solde_depart' => $opening]);
            return $id;
        });
    }

    // ------------------------------------------------------------------ opérations

    /** Montant saisi : « 150 000 », « 150000,50 » ; strictement positif sauf $allowZero. */
    public static function amount(mixed $v, bool $allowZero = false): float
    {
        if (is_string($v)) {
            $v = str_replace([' ', "\u{202F}", "\u{00A0}", ','], ['', '', '', '.'], trim($v));
        }
        if ($v === '' || $v === null) {
            $v = 0;
        }
        if (!is_numeric($v)) {
            throw new BusinessException('Montant invalide.');
        }
        $a = round((float) $v, 2);
        if ($a < 0 || (!$allowZero && $a <= 0)) {
            throw new BusinessException('Le montant doit être supérieur à zéro.');
        }
        if ($a > 999999999999) {
            throw new BusinessException('Montant trop élevé.');
        }
        return $a;
    }

    /** Champs libres acceptés d'un formulaire (le reste — séance, virement, numéro — est fixé par le serveur). */
    private static function opts(array $in): array
    {
        return array_intersect_key($in, array_flip(['op_date', 'method', 'reference', 'person', 'description']));
    }

    /** Écrit une opération et met à jour le solde (appelée dans une transaction). */
    private static function post(int $accountId, string $type, int $direction, float $amount, array $o = []): int
    {
        $acc = DB::one('SELECT * FROM bank_accounts WHERE id = ? FOR UPDATE', [$accountId]);
        if (!$acc || !(int) $acc['active']) {
            throw new BusinessException('Compte introuvable ou désactivé.');
        }
        $new = round((float) $acc['balance'] + $direction * $amount, 2);
        if ($new < -0.004 && $direction < 0 && setting('banques_decouvert', '0') !== '1') {
            throw new BusinessException('Solde insuffisant sur « ' . $acc['name'] . ' » : ' . money($acc['balance']) . ' disponible(s).');
        }
        $date = (string) ($o['op_date'] ?? date('Y-m-d'));
        if (!Validator::isDate($date) || $date > date('Y-m-d')) {
            throw new BusinessException('Date d\'opération invalide (pas de date future).');
        }
        $method = (string) ($o['method'] ?? 'especes');
        $id = DB::insert('bank_transactions', [
            'number' => $o['number'] ?? Sequence::next('banque'),
            'account_id' => $accountId, 'type' => $type, 'direction' => $direction, 'amount' => $amount, 'op_date' => $date,
            'method' => isset(self::METHODS[$method]) ? $method : 'autre',
            'reference' => mb_substr(trim((string) ($o['reference'] ?? '')), 0, 80) ?: null,
            'person' => mb_substr(trim((string) ($o['person'] ?? '')), 0, 120) ?: null,
            'description' => mb_substr(trim((string) ($o['description'] ?? '')), 0, 255) ?: null,
            'cash_session_id' => $o['cash_session_id'] ?? null, 'transfer_id' => $o['transfer_id'] ?? null,
            'counter_account_id' => $o['counter_account_id'] ?? null, 'user_id' => Auth::id(),
        ]);
        DB::update('bank_accounts', ['balance' => $new], 'id = ?', [$accountId]);
        return $id;
    }

    /** Dépôt (entrée) ou retrait (sortie), frais, intérêts. */
    public static function operation(string $type, array $in): int
    {
        $dir = ['depot' => 1, 'interets' => 1, 'retrait' => -1, 'frais' => -1][$type] ?? 0;
        if (!$dir) {
            throw new BusinessException('Type d\'opération inconnu.');
        }
        $amount = self::amount($in['amount'] ?? '');
        if ($type === 'retrait' && trim((string) ($in['description'] ?? '')) === '') {
            throw new BusinessException('Indiquez le motif du retrait.');
        }
        return DB::transaction(function () use ($type, $dir, $amount, $in) {
            $id = self::post((int) ($in['account_id'] ?? 0), $type, $dir, $amount, self::opts($in));
            Audit::log('banques.' . $type, 'bank_transaction', $id, ['compte' => (int) $in['account_id'], 'montant' => $amount]);
            return $id;
        });
    }

    /** Virement d'un compte à un autre (ex. coffre → banque) : deux lignes liées. */
    public static function transfer(array $in): int
    {
        $from = (int) ($in['from_account_id'] ?? 0);
        $to = (int) ($in['to_account_id'] ?? 0);
        if (!$from || !$to || $from === $to) {
            throw new BusinessException('Choisissez deux comptes différents.');
        }
        $amount = self::amount($in['amount'] ?? '');
        return DB::transaction(function () use ($from, $to, $amount, $in) {
            // Verrouillage dans l'ordre des identifiants : pas d'interblocage entre deux virements croisés
            DB::all('SELECT id FROM bank_accounts WHERE id IN (?, ?) ORDER BY id FOR UPDATE', [$from, $to]);
            $out = self::post($from, 'virement_sortant', -1, $amount, ['counter_account_id' => $to] + self::opts($in));
            $number = DB::value('SELECT number FROM bank_transactions WHERE id = ?', [$out]);
            $inId = self::post($to, 'virement_entrant', 1, $amount, ['counter_account_id' => $from, 'number' => $number] + self::opts($in));
            DB::run('UPDATE bank_transactions SET transfer_id = ? WHERE id IN (?, ?)', [$out, $out, $inId]);
            Audit::log('banques.virement', 'bank_transaction', $out, ['de' => $from, 'vers' => $to, 'montant' => $amount]);
            return $out;
        });
    }

    /** Correction de solde après un relevé bancaire (responsable, motif obligatoire). */
    public static function adjust(array $in): int
    {
        $accountId = (int) ($in['account_id'] ?? 0);
        $target = self::amount($in['new_balance'] ?? '', true);
        $why = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($why) < 3) {
            throw new BusinessException('Indiquez le motif de l\'ajustement (ex. « relevé BIA du 30/09 »).');
        }
        return DB::transaction(function () use ($accountId, $target, $why, $in) {
            $acc = DB::one('SELECT * FROM bank_accounts WHERE id = ? FOR UPDATE', [$accountId]);
            if (!$acc) {
                throw new BusinessException('Compte introuvable.');
            }
            $diff = round($target - (float) $acc['balance'], 2);
            if (abs($diff) < 0.005) {
                throw new BusinessException('Le solde est déjà de ' . money($target) . '.');
            }
            $id = self::post($accountId, 'ajustement', $diff > 0 ? 1 : -1, abs($diff), ['description' => $why, 'method' => 'autre', 'op_date' => $in['op_date'] ?? null]);
            Audit::log('banques.ajustement', 'bank_transaction', $id, ['compte' => $accountId, 'ancien' => (float) $acc['balance'], 'nouveau' => $target]);
            return $id;
        });
    }

    // ------------------------------------------------------------------ clôtures de caisse

    /** Clôtures de caisse et ce qui reste à verser (compté à la clôture − versements non annulés). */
    public static function sessions(bool $pendingOnly = true, int $limit = 200): array
    {
        $rows = DB::all("SELECT s.id, s.opened_at, s.closed_at, s.opening_amount, s.expected_amount, s.counted_amount, s.difference, r.name AS register_name,
                u.full_name AS cashier, c.full_name AS closed_name,
                COALESCE((SELECT SUM(t.amount) FROM bank_transactions t WHERE t.cash_session_id = s.id AND t.type = 'versement' AND t.cancelled_at IS NULL), 0) AS deposited
                FROM cash_sessions s JOIN cash_registers r ON r.id = s.register_id JOIN users u ON u.id = s.user_id LEFT JOIN users c ON c.id = s.closed_by
                WHERE s.status = 'closed' ORDER BY s.closed_at DESC LIMIT " . max(1, min(1000, $limit)));
        $out = [];
        foreach ($rows as $r) {
            $r['available'] = round((float) ($r['counted_amount'] ?? $r['expected_amount'] ?? 0), 2);
            $r['remaining'] = max(0, round($r['available'] - (float) $r['deposited'], 2));
            if (!$pendingOnly || $r['remaining'] > 0.004) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** Versement de l'argent d'une clôture vers un compte (banque, coffre, propriétaire…). */
    public static function depositSession(int $sessionId, array $in): int
    {
        $amount = self::amount($in['amount'] ?? '');
        return DB::transaction(function () use ($sessionId, $amount, $in) {
            $s = DB::one('SELECT * FROM cash_sessions WHERE id = ? FOR UPDATE', [$sessionId]);
            if (!$s) {
                throw new BusinessException('Session de caisse introuvable.');
            }
            if ($s['status'] !== 'closed') {
                throw new BusinessException('Clôturez d\'abord la caisse : seul l\'argent compté à la clôture peut être versé.');
            }
            $available = round((float) ($s['counted_amount'] ?? $s['expected_amount'] ?? 0), 2);
            $done = (float) DB::value("SELECT COALESCE(SUM(amount), 0) FROM bank_transactions WHERE cash_session_id = ? AND type = 'versement' AND cancelled_at IS NULL", [$sessionId]);
            $remaining = round($available - $done, 2);
            if ($amount > $remaining + 0.004) {
                throw new BusinessException('Montant supérieur à ce qui reste de cette clôture : ' . money(max(0, $remaining)) . ' (compté ' . money($available) . ', déjà versé ' . money($done) . ').');
            }
            $id = self::post((int) ($in['account_id'] ?? 0), 'versement', 1, $amount, ['cash_session_id' => $sessionId,
                'description' => trim((string) ($in['description'] ?? '')) ?: 'Clôture de caisse n° ' . $sessionId . ' du ' . date('d/m/Y', strtotime((string) $s['closed_at']))] + self::opts($in));
            Audit::log('banques.versement', 'bank_transaction', $id, ['session' => $sessionId, 'compte' => (int) $in['account_id'], 'montant' => $amount]);
            return $id;
        });
    }

    // ------------------------------------------------------------------ annulation

    /** Annulation (responsable) : l'opération reste dans l'historique, barrée, et le solde est rétabli. */
    public static function cancel(int $id, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new BusinessException('Indiquez le motif de l\'annulation.');
        }
        DB::transaction(function () use ($id, $reason) {
            $t = DB::one('SELECT * FROM bank_transactions WHERE id = ? FOR UPDATE', [$id]);
            if (!$t) {
                throw new BusinessException('Opération introuvable.');
            }
            if ($t['cancelled_at']) {
                throw new BusinessException('Opération déjà annulée.');
            }
            $legs = $t['transfer_id'] ? DB::all('SELECT * FROM bank_transactions WHERE transfer_id = ? ORDER BY id FOR UPDATE', [$t['transfer_id']]) : [$t];
            $accIds = array_unique(array_map(fn($l) => (int) $l['account_id'], $legs));
            sort($accIds);
            $accounts = [];
            foreach ($accIds as $aid) {
                $accounts[$aid] = DB::one('SELECT * FROM bank_accounts WHERE id = ? FOR UPDATE', [$aid]);
            }
            foreach ($legs as $l) {
                $acc = $accounts[(int) $l['account_id']];
                $new = round((float) $acc['balance'] - (int) $l['direction'] * (float) $l['amount'], 2);
                if ($new < -0.004 && setting('banques_decouvert', '0') !== '1') {
                    throw new BusinessException('Annulation impossible : le solde de « ' . $acc['name'] . ' » deviendrait négatif (l\'argent a déjà été retiré ou viré).');
                }
                $accounts[(int) $l['account_id']]['balance'] = $new;
                DB::update('bank_accounts', ['balance' => $new], 'id = ?', [$l['account_id']]);
                DB::update('bank_transactions', ['cancelled_at' => date('Y-m-d H:i:s'), 'cancelled_by' => Auth::id(), 'cancel_reason' => mb_substr($reason, 0, 255)], 'id = ?', [$l['id']]);
            }
            Audit::log('banques.annulation', 'bank_transaction', $id, ['numero' => $t['number'], 'montant' => (float) $t['amount'], 'motif' => $reason]);
        });
    }

    // ------------------------------------------------------------------ relevés

    /** Relevé d'un compte sur une période, avec solde de début, solde après chaque ligne et totaux. */
    public static function statement(int $accountId, string $from, string $to, string $type = ''): array
    {
        $acc = self::account($accountId);
        // Solde au début : opérations non annulées antérieures à la période
        $before = (float) DB::value('SELECT COALESCE(SUM(direction * amount), 0) FROM bank_transactions WHERE account_id = ? AND cancelled_at IS NULL AND op_date < ?', [$accountId, $from]);
        $rows = DB::all('SELECT t.*, u.full_name AS user_name, cu.full_name AS cancelled_name, ca.name AS counter_name FROM bank_transactions t
                JOIN users u ON u.id = t.user_id LEFT JOIN users cu ON cu.id = t.cancelled_by LEFT JOIN bank_accounts ca ON ca.id = t.counter_account_id
                WHERE t.account_id = ? AND t.op_date BETWEEN ? AND ? ORDER BY t.op_date, t.id', [$accountId, $from, $to]);
        $running = $before;
        $in = $out = 0.0;
        foreach ($rows as &$r) {
            if (!$r['cancelled_at']) {
                $running = round($running + (int) $r['direction'] * (float) $r['amount'], 2);
                if ((int) $r['direction'] > 0) {
                    $in += (float) $r['amount'];
                } else {
                    $out += (float) $r['amount'];
                }
            }
            $r['balance_after'] = $running;
        }
        unset($r);
        if ($type !== '' && isset(self::TYPES[$type])) {
            $rows = array_values(array_filter($rows, fn($r) => $r['type'] === $type));
        }
        return ['account' => $acc, 'rows' => $rows, 'opening' => round($before, 2), 'closing' => $running, 'in' => round($in, 2), 'out' => round($out, 2)];
    }

    /** Toutes les opérations (historique général, filtres). */
    public static function history(array $f, int $limit = 500): array
    {
        $where = ['t.op_date BETWEEN ? AND ?'];
        $params = [$f['from'], $f['to']];
        if (!empty($f['account_id'])) {
            $where[] = 't.account_id = ?';
            $params[] = (int) $f['account_id'];
        }
        if (!empty($f['type']) && isset(self::TYPES[$f['type']])) {
            $where[] = 't.type = ?';
            $params[] = $f['type'];
        }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['q']) . '%';
            $where[] = '(t.number LIKE ? OR t.reference LIKE ? OR t.person LIKE ? OR t.description LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        return DB::all('SELECT t.*, a.name AS account_name, a.kind, u.full_name AS user_name, ca.name AS counter_name FROM bank_transactions t
                JOIN bank_accounts a ON a.id = t.account_id JOIN users u ON u.id = t.user_id LEFT JOIN bank_accounts ca ON ca.id = t.counter_account_id
                WHERE ' . implode(' AND ', $where) . ' ORDER BY t.op_date DESC, t.id DESC LIMIT ' . max(1, min(5000, $limit)), $params);
    }

    public static function transaction(int $id): array
    {
        $t = DB::one('SELECT t.*, a.name AS account_name, a.kind, a.bank_name, a.account_number, u.full_name AS user_name, cu.full_name AS cancelled_name, ca.name AS counter_name
                FROM bank_transactions t JOIN bank_accounts a ON a.id = t.account_id JOIN users u ON u.id = t.user_id
                LEFT JOIN users cu ON cu.id = t.cancelled_by LEFT JOIN bank_accounts ca ON ca.id = t.counter_account_id WHERE t.id = ?', [$id]);
        if (!$t) {
            throw new BusinessException('Opération introuvable.');
        }
        return $t;
    }

    /** Contrôle de cohérence : solde enregistré = somme des opérations non annulées. */
    public static function check(): array
    {
        return DB::all('SELECT a.id, a.name, a.balance, COALESCE(SUM(CASE WHEN t.cancelled_at IS NULL THEN t.direction * t.amount END), 0) AS computed
                FROM bank_accounts a LEFT JOIN bank_transactions t ON t.account_id = a.id GROUP BY a.id, a.name, a.balance HAVING ABS(a.balance - computed) > 0.004');
    }
}
