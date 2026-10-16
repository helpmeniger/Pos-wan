<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;

/**
 * Indicateurs et rapports calculés exclusivement à partir des données de la base
 * (commandes, commissions, paiements, grand livre). Aucun chiffre n'est codé en dur.
 *
 * Définitions :
 *  - CA brut des services : total payé par les clients sur les commandes réglées (statut « Paiement confirmé » ou suites).
 *  - Commissions acquises : crédits nets des comptes 4000 (commissions), 4100 (frais), 4300 (abonnements).
 *  - Commissions encaissées : commissions + frais sur commandes payées électroniquement
 *    + reversements de commissions reçus des partenaires (espèces) + règlements entreprises, plafonnés aux montants acquis.
 *  - Marge estimée : revenus MARWA − promotions financées − frais de paiement − remboursements à charge − écarts de caisse.
 *  - Bénéfice estimé : marge − dépenses opérationnelles saisies (hors impôts non saisis).
 */
final class ReportService
{
    public const SETTLED = ['paid', 'disputed', 'refund_pending', 'refunded'];

    public const TYPES = [
        'period' => 'Activité par période',
        'city' => 'Rapport par ville',
        'merchant' => 'Rapport par restaurant / commerce',
        'driver' => 'Rapport par chauffeur',
        'courier' => 'Rapport par livreur',
        'payment_method' => 'Rapport par mode de paiement',
        'commissions' => 'Rapport des commissions',
        'cash' => 'Rapport de caisse (espèces)',
        'unpaid' => 'Rapport des impayés',
        'refunds' => 'Rapport des remboursements',
        'payouts' => 'Versements et reversements',
        'expenses' => 'Dépenses opérationnelles',
    ];

    public static function filters(array $q): array
    {
        $v = Validator::make($q, [
            'from' => 'nullable|date', 'to' => 'nullable|date',
            'granularity' => 'nullable|in:day,week,month,year',
            'city_id' => 'nullable|int', 'service_type' => 'nullable|in:taxi,food,parcel,document,errand,shop,urgent,pro',
            'provider_id' => 'nullable|int', 'merchant_id' => 'nullable|int',
        ]);
        $v['from'] ??= date('Y-m-01');
        $v['to'] ??= date('Y-m-d');
        if ($v['from'] > $v['to']) {
            throw \App\Core\ValidationException::field('from', 'La date de début doit précéder la date de fin.');
        }
        $v['granularity'] ??= 'day';
        $v['_from'] = $v['from'] . ' 00:00:00';
        $v['_to'] = $v['to'] . ' 23:59:59';
        return $v;
    }

    /** Clause WHERE commune sur les commandes (alias o). */
    private static function orderScope(array $f, string $dateCol = 'o.settled_at'): array
    {
        $w = ["$dateCol BETWEEN :from AND :to"];
        $p = ['from' => $f['_from'], 'to' => $f['_to']];
        foreach (['city_id', 'service_type', 'provider_id', 'merchant_id'] as $k) {
            if (!empty($f[$k])) {
                $w[] = "o.$k = :$k";
                $p[$k] = $f[$k];
            }
        }
        return [implode(' AND ', $w), $p];
    }

    private static function ledgerSum(array $codes, array $f, string $side = 'credit'): int
    {
        [$in, $p] = Database::in($codes, 'acc');
        $expr = $side === 'credit' ? 'COALESCE(SUM(e.credit),0) - COALESCE(SUM(e.debit),0)' : 'COALESCE(SUM(e.debit),0) - COALESCE(SUM(e.credit),0)';
        return (int) Database::value("SELECT $expr FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id WHERE a.code IN ($in) AND e.occurred_at BETWEEN :from AND :to",
            $p + ['from' => $f['_from'], 'to' => $f['_to']]);
    }

    public static function dashboard(array $f): array
    {
        [$w, $p] = self::orderScope($f);
        [$wc, $pc] = self::orderScope($f, 'o.created_at');
        $settled = "o.status IN ('" . implode("','", self::SETTLED) . "')";

        $fin = Database::first("SELECT COUNT(*) n, COALESCE(SUM(o.total),0) gmv, COALESCE(SUM(o.total + o.discount),0) gross_before_discount FROM orders o WHERE $w AND $settled", $p);
        $ops = Database::first("SELECT COUNT(*) created,
                SUM(o.status IN ('completed','delivered','returned','paid','disputed','refund_pending','refunded')) finished,
                SUM(o.status IN ('cancelled','refused')) cancelled,
                SUM(o.status = 'expired') expired,
                COUNT(DISTINCT o.customer_id) active_clients,
                AVG(CASE WHEN o.picked_up_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, o.created_at, o.picked_up_at) END) avg_pickup_s,
                AVG(CASE WHEN o.flow <> 'ride' AND o.completed_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, o.created_at, o.completed_at) END) avg_delivery_s
              FROM orders o WHERE $wc", $pc);
        $open = (int) Database::value("SELECT COUNT(*) FROM orders WHERE status IN ('" . implode("','", OrderService::OPEN_STATUSES) . "')");
        $today = (int) Database::value('SELECT COUNT(*) FROM orders WHERE created_at >= CURDATE()');

        $revenueCommission = self::ledgerSum(['4000'], $f);
        $revenueFees = self::ledgerSum(['4100'], $f);
        $revenueSubs = self::ledgerSum(['4300'], $f);
        $revenue = $revenueCommission + $revenueFees + $revenueSubs;
        $vat = self::ledgerSum(['2400'], $f);
        $promo = self::ledgerSum(['5000'], $f, 'debit');
        $fees = self::ledgerSum(['5100'], $f, 'debit');
        $expenses = self::ledgerSum(['5200'], $f, 'debit');
        $refundsMarwa = self::ledgerSum(['5300'], $f, 'debit');
        $cashAdj = self::ledgerSum(['5400'], $f, 'debit');

        $electronicCollected = (int) Database::value(
            "SELECT COALESCE(SUM(c.commission),0) FROM commissions c JOIN orders o ON o.id = c.order_id
              WHERE $w AND o.payment_method IN ('demo_mobile_money','mobile_money','card')", $p)
            + (int) Database::value("SELECT COALESCE(SUM(o.booking_fee + o.service_fee),0) FROM orders o WHERE $w AND $settled AND o.payment_method IN ('demo_mobile_money','mobile_money','card')", $p);
        $remitted = (int) Database::value("SELECT COALESCE(SUM(amount),0) FROM payouts WHERE direction = 'from_partner' AND status = 'paid' AND paid_at BETWEEN :from AND :to", ['from' => $f['_from'], 'to' => $f['_to']]);
        $businessPaid = (int) Database::value("SELECT COALESCE(SUM(e.credit),0) FROM ledger_entries e JOIN ledger_transactions t ON t.id = e.transaction_id JOIN ledger_accounts a ON a.id = e.account_id
                                              WHERE t.kind = 'business_payment' AND a.owner_type = 'business' AND e.occurred_at BETWEEN :from AND :to", ['from' => $f['_from'], 'to' => $f['_to']]);
        $collectedCommissions = min($revenue + $vat, $electronicCollected + $remitted + $businessPaid);

        $balances = Database::first("SELECT COALESCE(SUM(CASE WHEN balance > 0 AND owner_type <> 'business' THEN balance END),0) owed_to_partners,
                                            COALESCE(SUM(CASE WHEN balance < 0 AND owner_type <> 'business' THEN -balance END),0) owed_by_partners,
                                            COALESCE(SUM(CASE WHEN balance < 0 AND owner_type = 'business' THEN -balance END),0) business_receivables
                                       FROM partner_balances");
        $pendingPayments = Database::first("SELECT COUNT(*) n, COALESCE(SUM(amount),0) amount FROM payments WHERE status = 'pending'");
        $unpaidFinished = Database::first("SELECT COUNT(*) n, COALESCE(SUM(total),0) amount FROM orders WHERE status IN ('completed','delivered','returned') AND payment_status <> 'paid'");
        $refunds = (int) Database::value("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE status = 'completed' AND completed_at BETWEEN :from AND :to", ['from' => $f['_from'], 'to' => $f['_to']]);
        $margin = $revenue - $promo - $fees - $refundsMarwa - $cashAdj;

        $series = Database::select("SELECT DATE(o.settled_at) d, COUNT(*) orders, SUM(o.total) gmv,
                                           COALESCE(SUM((SELECT SUM(c.commission) FROM commissions c WHERE c.order_id = o.id)),0) commission
                                      FROM orders o WHERE $w AND $settled GROUP BY DATE(o.settled_at) ORDER BY d", $p);
        $byService = Database::select("SELECT o.service_type, COUNT(*) orders, SUM(o.total) gmv FROM orders o WHERE $w AND $settled GROUP BY o.service_type ORDER BY gmv DESC", $p);

        return [
            'period' => ['from' => $f['from'], 'to' => $f['to']],
            'users' => [
                'clients_total' => (int) Database::value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code IN ('client','business') AND u.status <> 'deleted'"),
                'clients_active' => (int) $ops['active_clients'],
                'drivers_verified' => (int) Database::value("SELECT COUNT(*) FROM provider_profiles WHERE provider_type = 'driver' AND verification_status = 'verified'"),
                'couriers_verified' => (int) Database::value("SELECT COUNT(*) FROM provider_profiles WHERE provider_type = 'courier' AND verification_status = 'verified'"),
                'merchants_verified' => (int) Database::value("SELECT COUNT(*) FROM merchants WHERE status = 'verified'"),
                'providers_online' => (int) Database::value('SELECT COUNT(*) FROM provider_profiles WHERE is_online = 1'),
                'pending_validations' => (int) Database::value("SELECT (SELECT COUNT(*) FROM provider_profiles WHERE verification_status = 'pending') + (SELECT COUNT(*) FROM merchants WHERE status = 'pending') + (SELECT COUNT(*) FROM businesses WHERE status = 'pending')"),
            ],
            'operations' => [
                'orders_today' => $today,
                'in_progress' => $open,
                'created' => (int) $ops['created'],
                'finished' => (int) $ops['finished'],
                'cancelled' => (int) $ops['cancelled'],
                'expired' => (int) $ops['expired'],
                'avg_pickup_minutes' => $ops['avg_pickup_s'] !== null ? round((float) $ops['avg_pickup_s'] / 60, 1) : null,
                'avg_delivery_minutes' => $ops['avg_delivery_s'] !== null ? round((float) $ops['avg_delivery_s'] / 60, 1) : null,
                'open_complaints' => (int) Database::value("SELECT COUNT(*) FROM complaints WHERE status IN ('open','in_progress')"),
                'open_alerts' => (int) Database::value("SELECT COUNT(*) FROM emergency_alerts WHERE status <> 'closed'"),
            ],
            'finance' => [
                'settled_orders' => (int) $fin['n'],
                'gross_revenue' => (int) $fin['gmv'],
                'gross_before_discount' => (int) $fin['gross_before_discount'],
                'commissions_earned' => $revenueCommission,
                'fees_earned' => $revenueFees,
                'subscriptions_earned' => $revenueSubs,
                'marwa_revenue' => $revenue,
                'vat_collected' => $vat,
                'commissions_collected' => $collectedCommissions,
                'owed_to_partners' => (int) $balances['owed_to_partners'],
                'owed_by_partners' => (int) $balances['owed_by_partners'],
                'business_receivables' => (int) $balances['business_receivables'],
                'pending_payments' => ['count' => (int) $pendingPayments['n'], 'amount' => (int) $pendingPayments['amount']],
                'unpaid_finished_orders' => ['count' => (int) $unpaidFinished['n'], 'amount' => (int) $unpaidFinished['amount']],
                'promotions' => $promo,
                'payment_fees' => $fees,
                'refunds_total' => $refunds,
                'refunds_marwa' => $refundsMarwa,
                'cash_adjustments' => $cashAdj,
                'operating_expenses' => $expenses,
                'margin' => $margin,
                'profit_estimate' => $margin - $expenses,
            ],
            'series' => array_map(static fn ($r) => ['date' => $r['d'], 'orders' => (int) $r['orders'], 'gmv' => (int) $r['gmv'], 'commission' => (int) $r['commission']], $series),
            'by_service' => array_map(static fn ($r) => ['service_type' => $r['service_type'], 'orders' => (int) $r['orders'], 'gmv' => (int) $r['gmv']], $byService),
            'ledger_check' => LedgerService::trialBalance()['balanced'],
        ];
    }

    public static function run(string $type, array $f): array
    {
        if (!isset(self::TYPES[$type])) {
            throw HttpException::notFound('Rapport inconnu.');
        }
        [$w, $p] = self::orderScope($f);
        $settled = "o.status IN ('" . implode("','", self::SETTLED) . "')";
        $sub = 'Du ' . date('d/m/Y', strtotime($f['from'])) . ' au ' . date('d/m/Y', strtotime($f['to']));
        $money = ['orders', 'gmv', 'commission', 'fees', 'partner_net', 'discount'];
        $report = ['title' => self::TYPES[$type], 'subtitle' => $sub, 'type' => $type];

        switch ($type) {
            case 'period':
                $fmt = ['day' => '%Y-%m-%d', 'week' => '%x-S%v', 'month' => '%Y-%m', 'year' => '%Y'][$f['granularity']];
                $rows = Database::select("SELECT DATE_FORMAT(o.settled_at, '$fmt') label, COUNT(*) orders, SUM(o.total) gmv, SUM(o.discount) discount,
                        SUM(o.booking_fee + o.service_fee) fees,
                        COALESCE(SUM((SELECT SUM(c.commission) FROM commissions c WHERE c.order_id = o.id)),0) commission,
                        COALESCE(SUM((SELECT SUM(c.partner_net) FROM commissions c WHERE c.order_id = o.id)),0) partner_net
                      FROM orders o WHERE $w AND $settled GROUP BY label ORDER BY label", $p);
                $report['title'] .= ' (' . ['day' => 'jour', 'week' => 'semaine', 'month' => 'mois', 'year' => 'année'][$f['granularity']] . ')';
                $report['columns'] = ['label' => 'Période', 'orders' => 'Commandes', 'gmv' => 'CA brut', 'discount' => 'Réductions', 'commission' => 'Commissions', 'fees' => 'Frais MARWA', 'partner_net' => 'Dû aux partenaires'];
                break;
            case 'city':
                $rows = Database::select("SELECT COALESCE(ci.name, 'Non définie') label, COUNT(*) orders, SUM(o.total) gmv,
                        COALESCE(SUM((SELECT SUM(c.commission) FROM commissions c WHERE c.order_id = o.id)),0) commission, SUM(o.booking_fee + o.service_fee) fees
                      FROM orders o LEFT JOIN cities ci ON ci.id = o.city_id WHERE $w AND $settled GROUP BY label ORDER BY gmv DESC", $p);
                $report['columns'] = ['label' => 'Ville', 'orders' => 'Commandes', 'gmv' => 'CA brut', 'commission' => 'Commissions', 'fees' => 'Frais MARWA'];
                break;
            case 'merchant':
                $rows = Database::select("SELECT m.name label, COUNT(*) orders, SUM(o.items_subtotal) gmv, COALESCE(SUM(c.commission),0) commission, COALESCE(SUM(c.partner_net),0) partner_net,
                        (SELECT COALESCE(SUM(credit) - SUM(debit),0) FROM ledger_entries le JOIN ledger_accounts la ON la.id = le.account_id WHERE la.owner_type = 'merchant' AND la.owner_id = m.id) balance
                      FROM orders o JOIN merchants m ON m.id = o.merchant_id LEFT JOIN commissions c ON c.order_id = o.id AND c.party = 'merchant'
                      WHERE $w AND $settled GROUP BY m.id, m.name ORDER BY gmv DESC", $p);
                $report['columns'] = ['label' => 'Commerce', 'orders' => 'Commandes', 'gmv' => 'Ventes articles', 'commission' => 'Commission MARWA', 'partner_net' => 'Net commerce', 'balance' => 'Solde actuel'];
                break;
            case 'driver':
            case 'courier':
                $rows = Database::select("SELECT u.full_name label, COUNT(*) orders, COALESCE(SUM(c.base_amount),0) gmv, COALESCE(SUM(c.commission),0) commission, COALESCE(SUM(c.partner_net),0) partner_net,
                        pp.rating_avg rating,
                        (SELECT COALESCE(SUM(credit) - SUM(debit),0) FROM ledger_entries le JOIN ledger_accounts la ON la.id = le.account_id WHERE la.owner_type = 'provider' AND la.owner_id = u.id) balance
                      FROM orders o JOIN commissions c ON c.order_id = o.id AND c.party = 'provider'
                      JOIN users u ON u.id = c.party_user_id JOIN provider_profiles pp ON pp.user_id = u.id
                      WHERE $w AND $settled AND pp.provider_type = :ptype GROUP BY u.id, u.full_name, pp.rating_avg ORDER BY gmv DESC", $p + ['ptype' => $type]);
                foreach ($rows as &$r) {
                    $r['rating'] = round((float) $r['rating'], 2);
                }
                unset($r);
                $report['columns'] = ['label' => $type === 'driver' ? 'Chauffeur' : 'Livreur', 'orders' => 'Commandes', 'gmv' => 'Montant des prestations', 'commission' => 'Commission MARWA', 'partner_net' => 'Net prestataire', 'rating' => 'Note', 'balance' => 'Solde actuel'];
                break;
            case 'payment_method':
                $rows = Database::select("SELECT o.payment_method label, COUNT(*) orders, SUM(o.total) gmv,
                        COALESCE(SUM((SELECT SUM(fee_amount) FROM payments pm WHERE pm.order_id = o.id)),0) fees
                      FROM orders o WHERE $w AND $settled GROUP BY o.payment_method ORDER BY gmv DESC", $p);
                $labels = ['cash' => 'Espèces', 'demo_mobile_money' => 'Mobile Money (démo)', 'mobile_money' => 'Mobile Money', 'card' => 'Carte bancaire', 'business_account' => 'Compte entreprise'];
                foreach ($rows as &$r) {
                    $r['label'] = $labels[$r['label']] ?? $r['label'];
                }
                unset($r);
                $report['columns'] = ['label' => 'Mode de paiement', 'orders' => 'Commandes', 'gmv' => 'Montant encaissé', 'fees' => 'Frais de paiement'];
                break;
            case 'commissions':
                $rows = Database::select("SELECT o.reference label, DATE_FORMAT(o.settled_at, '%d/%m/%Y') date, o.service_type, c.party,
                        CASE c.party WHEN 'merchant' THEN m.name ELSE u.full_name END partner,
                        c.model, ROUND(c.percent_bp / 100, 2) pct, c.base_amount gmv, c.commission, c.vat_amount, c.partner_net
                      FROM commissions c JOIN orders o ON o.id = c.order_id LEFT JOIN merchants m ON m.id = o.merchant_id LEFT JOIN users u ON u.id = c.party_user_id
                      WHERE $w ORDER BY o.settled_at", $p);
                $svc = ['taxi' => 'Taxi', 'pro' => 'Transport pro', 'food' => 'Repas', 'shop' => 'Commerce', 'parcel' => 'Colis', 'document' => 'Documents', 'errand' => 'Courses', 'urgent' => 'Urgent'];
                foreach ($rows as &$r) {
                    $r['pct'] = (float) $r['pct'];
                    $r['service_type'] = $svc[$r['service_type']] ?? $r['service_type'];
                    $r['party'] = $r['party'] === 'merchant' ? 'Commerce' : 'Prestataire';
                }
                unset($r);
                $report['columns'] = ['label' => 'Commande', 'date' => 'Date', 'service_type' => 'Service', 'party' => 'Partie', 'partner' => 'Partenaire', 'model' => 'Modèle', 'pct' => '%', 'gmv' => 'Base', 'commission' => 'Commission', 'vat_amount' => 'dont TVA', 'partner_net' => 'Net partenaire'];
                break;
            case 'cash':
                $rows = Database::select("SELECT o.reference label, DATE_FORMAT(pm.created_at, '%d/%m/%Y %H:%i') date,
                        CASE pm.collector_type WHEN 'merchant' THEN m.name ELSE u.full_name END collector,
                        pm.amount expected, pm.declared_amount declared, pm.verified_amount verified,
                        (COALESCE(pm.declared_amount, 0) - pm.amount) gap, pm.status
                      FROM payments pm JOIN orders o ON o.id = pm.order_id LEFT JOIN users u ON u.id = pm.collector_id AND pm.collector_type = 'provider'
                      LEFT JOIN merchants m ON m.id = pm.collector_id AND pm.collector_type = 'merchant'
                      WHERE pm.method = 'cash' AND pm.created_at BETWEEN :from AND :to ORDER BY pm.created_at", ['from' => $f['_from'], 'to' => $f['_to']]);
                foreach ($rows as &$r) {
                    $r['status'] = ['declared' => 'Déclaré', 'confirmed' => 'Vérifié', 'refunded' => 'Remboursé', 'partially_refunded' => 'Remb. partiel'][$r['status']] ?? $r['status'];
                }
                unset($r);
                $report['columns'] = ['label' => 'Commande', 'date' => 'Date', 'collector' => 'Collecteur', 'expected' => 'Attendu', 'declared' => 'Déclaré', 'verified' => 'Vérifié', 'gap' => 'Écart', 'status' => 'Statut'];
                $money = ['expected', 'declared', 'verified', 'gap'];
                break;
            case 'unpaid':
                $rows = array_merge(
                    array_map(static fn ($r) => ['label' => $r['reference'], 'kind' => 'Commande terminée non payée', 'party' => $r['full_name'], 'amount' => (int) $r['total'], 'since' => date('d/m/Y', strtotime((string) $r['completed_at']))],
                        Database::select("SELECT o.reference, o.total, o.completed_at, u.full_name FROM orders o JOIN users u ON u.id = o.customer_id WHERE o.status IN ('completed','delivered','returned') AND o.payment_status <> 'paid' ORDER BY o.completed_at")),
                    array_map(static fn ($r) => ['label' => $r['name'], 'kind' => $r['owner_type'] === 'business' ? 'Créance entreprise' : 'Commissions à reverser par le partenaire', 'party' => $r['name'], 'amount' => -(int) $r['balance'], 'since' => ''],
                        Database::select('SELECT * FROM partner_balances WHERE balance < 0 ORDER BY balance'))
                );
                $report['subtitle'] = 'Situation au ' . date('d/m/Y H:i');
                $report['columns'] = ['label' => 'Référence', 'kind' => 'Nature', 'party' => 'Débiteur', 'amount' => 'Montant dû', 'since' => 'Depuis'];
                $money = ['amount'];
                break;
            case 'refunds':
                $rows = Database::select("SELECT o.reference label, DATE_FORMAT(r.created_at, '%d/%m/%Y') date, r.amount, r.reason, r.borne_by, r.status
                      FROM refunds r JOIN orders o ON o.id = r.order_id WHERE r.created_at BETWEEN :from AND :to ORDER BY r.created_at", ['from' => $f['_from'], 'to' => $f['_to']]);
                foreach ($rows as &$r) {
                    $r['borne_by'] = ['marwa' => 'MARWA', 'provider' => 'Prestataire', 'merchant' => 'Commerce'][$r['borne_by']];
                }
                unset($r);
                $report['columns'] = ['label' => 'Commande', 'date' => 'Date', 'amount' => 'Montant', 'reason' => 'Motif', 'borne_by' => 'À la charge de', 'status' => 'Statut'];
                $money = ['amount'];
                break;
            case 'payouts':
                $rows = Database::select("SELECT DATE_FORMAT(po.requested_at, '%d/%m/%Y') label, u.full_name partner, po.partner_type, po.direction, po.amount, po.method, po.status, po.reference
                      FROM payouts po JOIN users u ON u.id = po.partner_user_id WHERE po.requested_at BETWEEN :from AND :to ORDER BY po.requested_at", ['from' => $f['_from'], 'to' => $f['_to']]);
                foreach ($rows as &$r) {
                    $r['direction'] = $r['direction'] === 'to_partner' ? 'Versement au partenaire' : 'Reversement à MARWA';
                }
                unset($r);
                $report['columns'] = ['label' => 'Date', 'partner' => 'Partenaire', 'partner_type' => 'Type', 'direction' => 'Sens', 'amount' => 'Montant', 'method' => 'Moyen', 'status' => 'Statut', 'reference' => 'Référence'];
                $money = ['amount'];
                break;
            case 'expenses':
                $rows = Database::select("SELECT DATE_FORMAT(expense_date, '%d/%m/%Y') label, category, description, amount, paid_from FROM expenses WHERE expense_date BETWEEN :f AND :t ORDER BY expense_date",
                    ['f' => $f['from'], 't' => $f['to']]);
                $report['columns'] = ['label' => 'Date', 'category' => 'Catégorie', 'description' => 'Description', 'amount' => 'Montant', 'paid_from' => 'Payé par'];
                $money = ['amount'];
                break;
        }
        foreach ($rows as &$r) {
            foreach ($r as $k => $val) {
                if (is_string($val) && is_numeric($val) && !in_array($k, ['label', 'date'], true) && preg_match('/^-?\d+$/', $val)) {
                    $r[$k] = (int) $val;
                }
            }
        }
        unset($r);
        $report['rows'] = $rows;
        $totals = ['label' => 'TOTAL'];
        foreach (array_keys($report['columns']) as $col) {
            if ($col !== 'label' && (in_array($col, $money, true) || in_array($col, ['balance', 'vat_amount', 'expected', 'declared', 'verified', 'amount', 'gmv', 'commission', 'partner_net', 'fees', 'orders'], true))) {
                $totals[$col] = array_sum(array_map(static fn ($r) => (int) ($r[$col] ?? 0), $rows));
            }
        }
        $report['totals'] = $rows ? $totals : [];
        return $report;
    }
}
