<?php
declare(strict_types=1);

namespace Addons\Comptabilite;

use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Validator;

/**
 * Écritures proposées à partir de l'activité de POS Marwa : une écriture par source et par journée
 * terminée, enregistrée au brouillard pour contrôle (ou validée directement si demandé).
 * Chaque événement est comptabilisé à sa date : une vente annulée ou retournée plus tard donne une
 * écriture inverse le jour de l'annulation ou du retour. Une journée déjà générée ne l'est jamais
 * deux fois (clé unique origine + date).
 *
 * Hypothèses de comptabilisation (documentées dans le README du module) :
 *  - ventes : encaissements par mode de paiement / ventes HT et TVA facturée ; vente à crédit au
 *    compte client (tiers) ;
 *  - achats : réception = facture fournisseur (marchandises + frais), retour = avoir ;
 *  - recettes des autres modules (clinique, pressing, établissement) : prestations de services ;
 *    salaires de l'établissement : charges de personnel ; entrées / sorties manuelles de caisse :
 *    compte de virements de fonds, à reclasser ;
 *  - banques : versement de clôture = caisse vers banque ; dépôt / retrait sans origine connue :
 *    compte de virements de fonds, à reclasser.
 */
final class Integration
{
    public const SOURCES = [
        'ventes' => ['Ventes, annulations et retours', 'ventes', 'VT', 'bi-cart3'],
        'reglements' => ['Règlements clients et fournisseurs', 'tresorerie', 'CA', 'bi-cash-coin'],
        'achats' => ['Achats (réceptions) et retours fournisseurs', 'achats', 'AC', 'bi-truck'],
        'recettes' => ['Recettes des modules (clinique, pressing, établissement…) et mouvements de caisse', 'tresorerie', 'CA', 'bi-hospital'],
        'depenses' => ['Dépenses et recettes diverses', 'tresorerie', 'CA', 'bi-receipt'],
        'banques' => ['Banques et coffres', 'tresorerie', 'BQ', 'bi-bank'],
    ];

    /** Types de mouvements de caisse traités par une autre source. */
    private const CASH_HANDLED = ['sale', 'sale_cancel', 'refund', 'customer_payment', 'supplier_payment', 'expense', 'income', 'expense_reversal', 'income_reversal'];

    private array $acc = [];
    private array $warnings = [];

    public static function startDate(): string
    {
        $s = (string) setting('compta_generation_depuis', '');
        if (!Validator::isDate($s)) {
            $s = (string) (DB::value('SELECT MIN(date_debut) FROM cpt_exercices') ?: date('Y-m-d'));
        }
        return $s;
    }

    private static function tableExists(string $t): bool
    {
        return (bool) DB::value('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
    }

    /** Dates ayant de l'activité pour une source, entre deux dates. */
    public static function activeDays(string $source, string $from, string $to): array
    {
        $p = fn(int $n) => array_merge(...array_fill(0, $n, [$from, $to . ' 23:59:59']));
        $sql = match ($source) {
            'ventes' => ['SELECT DATE(created_at) d FROM sales WHERE created_at BETWEEN ? AND ? UNION SELECT DATE(cancelled_at) FROM sales WHERE cancelled_at BETWEEN ? AND ?
                UNION SELECT DATE(created_at) FROM sale_returns WHERE created_at BETWEEN ? AND ?', 3],
            'reglements' => ['SELECT DATE(created_at) d FROM customer_transactions WHERE type IN (\'payment\', \'deposit\') AND created_at BETWEEN ? AND ?
                UNION SELECT DATE(created_at) FROM supplier_transactions WHERE type = \'payment\' AND created_at BETWEEN ? AND ?', 2],
            'achats' => ['SELECT DATE(created_at) d FROM supplier_transactions WHERE type IN (\'invoice\', \'return\') AND created_at BETWEEN ? AND ?', 1],
            'recettes' => ['SELECT DATE(created_at) d FROM cash_movements WHERE type NOT IN (\'' . implode("','", self::CASH_HANDLED) . '\') AND created_at BETWEEN ? AND ?', 1],
            'depenses' => ['SELECT expense_date d FROM expenses WHERE expense_date BETWEEN ? AND ? UNION SELECT DATE(created_at) FROM cash_movements WHERE type IN (\'expense_reversal\', \'income_reversal\') AND created_at BETWEEN ? AND ?', 2],
            'banques' => self::tableExists('bank_transactions') ? ['SELECT op_date d FROM bank_transactions WHERE op_date BETWEEN ? AND ? UNION SELECT DATE(cancelled_at) FROM bank_transactions WHERE cancelled_at BETWEEN ? AND ?', 2] : null,
            default => null,
        };
        if (!$sql) {
            return [];
        }
        $days = array_column(DB::all('SELECT DISTINCT d FROM (' . $sql[0] . ') x WHERE d IS NOT NULL ORDER BY d', $p($sql[1])), 'd');
        return array_values(array_filter($days, fn($d) => $d >= $from && $d <= $to));
    }

    /** État par source : journées à générer, déjà générées, en période close. */
    public static function status(): array
    {
        $from = self::startDate();
        $to = date('Y-m-d', strtotime('-1 day'));
        $out = [];
        foreach (self::SOURCES as $src => $def) {
            $done = array_flip(array_column(DB::all('SELECT origine_ref FROM cpt_ecritures WHERE origine = ?', ['pos_' . $src]), 'origine_ref'));
            $pending = $closed = [];
            foreach ($from <= $to ? self::activeDays($src, $from, $to) : [] as $d) {
                if (isset($done[$d])) {
                    continue;
                }
                $ex = DB::one('SELECT statut, verrou_au FROM cpt_exercices WHERE ? BETWEEN date_debut AND date_fin', [$d]);
                if (!$ex || $ex['statut'] === 'cloture' || ($ex['verrou_au'] && $d <= $ex['verrou_au'])) {
                    $closed[] = $d;
                } else {
                    $pending[] = $d;
                }
            }
            $out[$src] = ['label' => $def[0], 'icon' => $def[3], 'pending' => $pending, 'closed' => $closed, 'done' => count($done)];
        }
        return ['from' => $from, 'to' => $to, 'sources' => $out];
    }

    /**
     * Génère les écritures des journées en attente (au plus $max journées par appel).
     * @return array{created:int, errors:array, warnings:array}
     */
    public static function run(array $sources, bool $validate, int $max = 400): array
    {
        $status = self::status();
        $created = 0;
        $errors = $warnings = [];
        foreach ($sources as $src) {
            if (!isset(self::SOURCES[$src])) {
                continue;
            }
            foreach ($status['sources'][$src]['pending'] as $day) {
                if ($created >= $max) {
                    break 2;
                }
                try {
                    $g = new self();
                    $id = $g->generate($src, $day, $validate);
                    $warnings = array_merge($warnings, $g->warnings);
                    if ($id) {
                        $created++;
                    }
                } catch (BusinessException $e) {
                    $errors[] = self::SOURCES[$src][0] . ' du ' . fdate($day, false) . ' : ' . $e->getMessage();
                }
            }
        }
        if ($created) {
            Audit::log('compta.generation', 'cpt_ecriture', null, ['ecritures' => $created, 'sources' => $sources, 'validees' => $validate]);
        }
        return ['created' => $created, 'errors' => $errors, 'warnings' => $warnings];
    }

    /** Construit et enregistre l'écriture d'une source pour une journée. */
    public function generate(string $src, string $day, bool $validate): ?int
    {
        $this->acc = [];
        $label = match ($src) {
            'ventes' => $this->ventes($day),
            'reglements' => $this->reglements($day),
            'achats' => $this->achats($day),
            'recettes' => $this->recettes($day),
            'depenses' => $this->depenses($day),
            'banques' => $this->banques($day),
        };
        if (!$this->acc) {
            return null;
        }
        $lines = [];
        foreach ($this->acc as $k => $a) {
            [$numero, $tiers, $side] = explode('|', $k, 4);
            $lines[] = ['compte' => $numero, 'tiers' => $tiers, 'libelle' => $a['libelle'],
                'debit' => $side === 'D' ? (string) round($a['montant'], 2) : '', 'credit' => $side === 'C' ? (string) round($a['montant'], 2) : '', 'echeance' => ''];
        }
        if (count($lines) > 250) {
            throw new BusinessException('Plus de 250 lignes pour une journée : passez cette journée en plusieurs écritures manuelles ou regroupez les libellés.');
        }
        usort($lines, fn($a, $b) => [$a['debit'] === '' ? 1 : 0, $a['compte']] <=> [$b['debit'] === '' ? 1 : 0, $b['compte']]);
        $def = self::SOURCES[$src];
        $journal = Compta::journalByType($def[1], $def[2]);
        return Compta::save(['journal_id' => $journal['id'], 'date_ecriture' => $day, 'piece' => strtoupper(substr($src, 0, 3)) . '-' . str_replace('-', '', $day),
            'libelle' => $label . ' du ' . fdate($day, false), 'lignes' => $lines], null, $validate, ['origine' => 'pos_' . $src, 'ref' => $day]);
    }

    /** Ajoute un montant (signé : + débit, − crédit) au compte de liaison $key (ou numéro direct). */
    private function add(string $account, float $amount, string $libelle, string $tiers = ''): void
    {
        $amount = round($amount, 2);
        if (abs($amount) < 0.005) {
            return;
        }
        $numero = ctype_digit($account) ? $account : Compta::liaison($account)['numero'];
        $side = $amount > 0 ? 'D' : 'C';
        // Regroupement par compte, tiers, sens et libellé : les encaissements d'une journée font une ligne,
        // chaque opération bancaire ou facture garde la sienne (libellé propre)
        $libelle = mb_substr($libelle, 0, 190);
        $k = $numero . '|' . $tiers . '|' . $side . '|' . $libelle;
        $this->acc[$k] ??= ['montant' => 0.0, 'libelle' => $libelle];
        $this->acc[$k]['montant'] += abs($amount);
    }

    /** Compte de trésorerie d'un mode de paiement. */
    private static function methodKey(?string $m): string
    {
        return match ((string) $m) {
            'mobile_money' => 'mobile',
            'card', 'transfer', 'virement' => 'banque',
            'cheque' => 'cheques',
            default => 'caisse',
        };
    }

    private const METHOD_LABELS = ['caisse' => 'Encaissements espèces', 'banque' => 'Encaissements carte / virement', 'mobile' => 'Encaissements mobile money', 'cheques' => 'Chèques reçus'];

    private function customerTiers(?int $customerId): string
    {
        if (!$customerId) {
            return '';
        }
        return (string) DB::value('SELECT code FROM cpt_tiers WHERE id = ?', [Compta::tiersFor('customer', $customerId)]);
    }

    private function supplierTiers(int $supplierId): string
    {
        return (string) DB::value('SELECT code FROM cpt_tiers WHERE id = ?', [Compta::tiersFor('supplier', $supplierId)]);
    }

    // ------------------------------------------------------------------ sources

    /** Une vente : encaissements par mode (signe $s = +1 vente, −1 annulation). */
    private function sale(array $sale, int $s, string $why): void
    {
        $paid = 0.0;
        foreach (DB::all('SELECT method, SUM(amount) AS a FROM sale_payments WHERE sale_id = ? GROUP BY method', [$sale['id']]) as $p) {
            $a = (float) $p['a'];
            $paid += $a;
            if ($p['method'] === 'credit') {
                $tiers = $this->customerTiers($sale['customer_id'] ? (int) $sale['customer_id'] : null);
                $this->add('clients', $s * $a, $why === 'vente' ? 'Ventes à crédit' : 'Annulations de ventes à crédit', $tiers);
            } else {
                $k = self::methodKey($p['method']);
                $this->add($k, $s * $a, $why === 'vente' ? self::METHOD_LABELS[$k] : 'Remboursements (annulations)');
            }
        }
        $total = (float) $sale['total'];
        $tax = (float) $sale['tax_total'];
        $this->add('ventes', -$s * ($total - $tax), $why === 'vente' ? 'Ventes de marchandises HT' : 'Annulations de ventes HT');
        $this->add('tva_collectee', -$s * $tax, $why === 'vente' ? 'TVA facturée' : 'TVA sur ventes annulées');
        if (abs($paid - $total) >= 0.01) {
            $this->add('virements_internes', $s * ($total - $paid), 'Écart paiements / total vente ' . $sale['number'] . ' à analyser');
            $this->warnings[] = 'Vente ' . $sale['number'] . ' : paiements ' . Compta::num($paid) . ' ≠ total ' . Compta::num($total) . ' ; écart porté au compte de virements de fonds pour analyse.';
        }
    }

    private function ventes(string $day): string
    {
        $end = $day . ' 23:59:59';
        foreach (DB::all('SELECT * FROM sales WHERE created_at BETWEEN ? AND ?', [$day, $end]) as $sale) {
            $this->sale($sale, 1, 'vente');
        }
        foreach (DB::all('SELECT * FROM sales WHERE cancelled_at BETWEEN ? AND ?', [$day, $end]) as $sale) {
            $this->sale($sale, -1, 'annulation');
        }
        foreach (DB::all('SELECT r.*, s.total AS sale_total, s.tax_total AS sale_tax, s.customer_id FROM sale_returns r JOIN sales s ON s.id = r.sale_id WHERE r.created_at BETWEEN ? AND ?', [$day, $end]) as $r) {
            $total = (float) $r['total'];
            $tax = (float) $r['sale_total'] > 0 ? round($total * (float) $r['sale_tax'] / (float) $r['sale_total'], 2) : 0.0;
            $this->add('ventes', $total - $tax, 'Retours de marchandises HT');
            $this->add('tva_collectee', $tax, 'TVA sur retours');
            if ($r['refund_method'] === 'credit_note') {
                $this->add('clients', -$total, 'Avoirs clients (retours)', $this->customerTiers($r['customer_id'] ? (int) $r['customer_id'] : null));
            } else {
                $this->add(self::methodKey($r['refund_method']), -$total, 'Remboursements de retours');
            }
        }
        return 'Ventes';
    }

    private function reglements(string $day): string
    {
        $end = $day . ' 23:59:59';
        foreach (DB::all('SELECT * FROM customer_transactions WHERE type IN (\'payment\', \'deposit\') AND created_at BETWEEN ? AND ?', [$day, $end]) as $t) {
            $a = -(float) $t['amount']; // montant négatif au compte client = argent reçu
            $k = self::methodKey($t['method']);
            $this->add($k, $a, $t['type'] === 'deposit' ? 'Acomptes clients reçus' : 'Règlements clients reçus');
            $this->add('clients', -$a, $t['type'] === 'deposit' ? 'Acompte client' : 'Règlement client', $this->customerTiers((int) $t['customer_id']));
        }
        foreach (DB::all('SELECT * FROM supplier_transactions WHERE type = \'payment\' AND created_at BETWEEN ? AND ?', [$day, $end]) as $t) {
            $a = -(float) $t['amount'];
            $this->add('fournisseurs', $a, 'Règlement fournisseur', $this->supplierTiers((int) $t['supplier_id']));
            $this->add(self::methodKey($t['method']), -$a, 'Règlements fournisseurs');
        }
        return 'Règlements clients et fournisseurs';
    }

    private function achats(string $day): string
    {
        foreach (DB::all('SELECT * FROM supplier_transactions WHERE type IN (\'invoice\', \'return\') AND created_at BETWEEN ? AND ?', [$day, $day . ' 23:59:59']) as $t) {
            $tiers = $this->supplierTiers((int) $t['supplier_id']);
            $a = (float) $t['amount'];
            if ($t['type'] === 'invoice') {
                $r = $t['ref_type'] === 'reception' ? DB::one('SELECT goods_total, extra_costs FROM receptions WHERE id = ?', [$t['ref_id']]) : null;
                $extra = $r ? (float) $r['extra_costs'] : 0.0;
                $this->add('achats', $a - $extra, 'Achats de marchandises');
                $this->add('frais_achats', $extra, 'Frais sur achats');
                $this->add('fournisseurs', -$a, mb_substr((string) $t['note'], 0, 150) ?: 'Facture fournisseur', $tiers);
            } else {
                $this->add('fournisseurs', -$a, mb_substr((string) $t['note'], 0, 150) ?: 'Avoir fournisseur', $tiers);
                $this->add('achats', $a, 'Retours de marchandises aux fournisseurs');
            }
        }
        return 'Achats';
    }

    private function recettes(string $day): string
    {
        foreach (DB::all('SELECT type, method, SUM(amount) AS a FROM cash_movements WHERE type NOT IN (\'' . implode("','", self::CASH_HANDLED) . '\') AND created_at BETWEEN ? AND ? GROUP BY type, method',
            [$day, $day . ' 23:59:59']) as $m) {
            $a = (float) $m['a'];
            $type = (string) $m['type'];
            [$contra, $label] = match (true) {
                str_contains($type, 'salaire') => ['salaires', 'Salaires payés en caisse (' . $type . ')'],
                in_array($type, ['cash_in', 'cash_out'], true) => ['virements_internes', $type === 'cash_in' ? 'Entrées de fonds en caisse (à reclasser)' : 'Sorties de fonds de caisse (à reclasser)'],
                default => ['services', 'Prestations encaissées — ' . str_replace('_', ' ', $type)],
            };
            $this->add(self::methodKey($m['method']), $a, $label);
            $this->add($contra, -$a, $label);
        }
        return 'Recettes et mouvements de caisse';
    }

    private function depenses(string $day): string
    {
        $one = function (array $e, int $s, string $prefix) {
            $cat = $e['category_id'] ? (string) setting('compta_cat_' . (int) $e['category_id'], '') : '';
            $account = $cat !== '' && Compta::compteId($cat) ? $cat : ($e['type'] === 'income' ? 'produits_divers' : 'charges_diverses');
            $label = mb_substr($prefix . ($e['category_name'] ?: ($e['type'] === 'income' ? 'Recette diverse' : 'Dépense')) . ' — ' . $e['description'], 0, 190);
            $a = (float) $e['amount'] * $s;
            if ($e['type'] === 'income') {
                $this->add(self::methodKey($e['method']), $a, $label);
                $this->add($account, -$a, $label);
            } else {
                $this->add($account, $a, $label);
                $this->add(self::methodKey($e['method']), -$a, $label);
            }
        };
        $sql = 'SELECT e.*, c.name AS category_name FROM expenses e LEFT JOIN expense_categories c ON c.id = e.category_id';
        foreach (DB::all($sql . ' WHERE e.expense_date = ?', [$day]) as $e) {
            $one($e, 1, '');
        }
        foreach (DB::all('SELECT ref_id FROM cash_movements WHERE type IN (\'expense_reversal\', \'income_reversal\') AND ref_type = \'expense\' AND created_at BETWEEN ? AND ?', [$day, $day . ' 23:59:59']) as $m) {
            if ($e = DB::one($sql . ' WHERE e.id = ?', [$m['ref_id']])) {
                $one($e, -1, 'Contrepassation : ');
            }
        }
        return 'Dépenses et recettes diverses';
    }

    /** Compte comptable d'un compte du module Banques (paramètre, sinon selon son type). */
    public static function bankAccount(array $b): string
    {
        $n = (string) setting('compta_bank_' . (int) $b['id'], '');
        if ($n !== '' && Compta::compteId($n)) {
            return $n;
        }
        return Compta::liaison(match ($b['kind']) {
            'coffre', 'proprietaire' => 'coffre',
            'mobile' => 'mobile',
            default => 'banque',
        })['numero'];
    }

    private function banques(string $day): string
    {
        $rows = function (string $where, array $p) {
            return DB::all('SELECT t.*, a.name AS account_name, a.kind, a.id AS bank_id FROM bank_transactions t JOIN bank_accounts a ON a.id = t.account_id WHERE ' . $where, $p);
        };
        $one = function (array $t, int $s) {
            $bank = self::bankAccount(['id' => $t['bank_id'], 'kind' => $t['kind']]);
            $a = (float) $t['amount'] * (int) $t['direction'] * $s;
            $label = mb_substr(($s < 0 ? 'Annulation : ' : '') . ($t['description'] ?: str_replace('_', ' ', $t['type'])) . ' — ' . $t['account_name'], 0, 190);
            $contra = match ($t['type']) {
                'versement' => 'caisse',
                'frais' => 'frais_bancaires',
                'interets' => 'interets',
                'ajustement' => $a > 0 ? 'produits_divers' : 'charges_diverses',
                default => 'virements_internes', // dépôt, retrait, virements entre comptes
            };
            $this->add($bank, $a, $label);
            $this->add($contra, -$a, $label);
        };
        foreach ($rows('t.op_date = ?', [$day]) as $t) {
            $one($t, 1);
        }
        foreach ($rows('t.cancelled_at BETWEEN ? AND ?', [$day, $day . ' 23:59:59']) as $t) {
            $one($t, -1);
        }
        return 'Banques et coffres';
    }
}
