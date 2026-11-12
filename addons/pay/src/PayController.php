<?php
declare(strict_types=1);

namespace Addons\Pay;

use App\Controllers\Controller;
use App\Core\DB;
use App\Core\Export;
use App\Core\HttpException;
use App\Core\Request;
use Addons\Pay\PayAdmin as A;

final class PayController extends Controller
{
    private function show(string $view, string $title, string $tab, array $data = []): never
    {
        view('pay::' . $view, ['title' => $title, 'tab' => $tab] + $data);
    }

    private function upload(string $field): ?array
    {
        $f = $_FILES[$field] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || (!is_uploaded_file($f['tmp_name']) && PHP_SAPI !== 'cli')) {
            return null;
        }
        return $f;
    }

    // ------------------------------------------------------------------ 1. tableau de bord

    public function dashboard(): void
    {
        [$from, $to] = date_range();
        $p = ["$from 00:00:00", "$to 23:59:59"];
        $k = DB::one("SELECT COUNT(*) n, SUM(status IN ('succeeded','refunded','partially_refunded')) ok, SUM(status = 'declined') ko, SUM(status IN ('pending','processing','to_verify')) wait,
                COALESCE(SUM(CASE WHEN status IN ('succeeded','refunded','partially_refunded') THEN amount END),0) total, COALESCE(SUM(refunded_amount),0) refunded, COALESCE(SUM(fees),0) fees
            FROM payment_transactions WHERE is_test = 0 AND created_at BETWEEN ? AND ?", $p);
        $test = (int) DB::value('SELECT COUNT(*) FROM payment_transactions WHERE is_test = 1 AND created_at BETWEEN ? AND ?', $p);
        $ok = "t.status IN ('succeeded','refunded','partially_refunded')";
        $byProvider = DB::all("SELECT COALESCE(c.name, pt.name, t.driver) label, COUNT(*) n, COALESCE(SUM(CASE WHEN $ok THEN t.amount END),0) total
            FROM payment_transactions t LEFT JOIN payment_connectors c ON c.id = t.connector_id LEFT JOIN payment_terminals pt ON pt.id = t.terminal_id
            WHERE t.is_test = 0 AND t.created_at BETWEEN ? AND ? GROUP BY label ORDER BY total DESC", $p);
        $byMethod = DB::all("SELECT m.label, COUNT(*) n, COALESCE(SUM(CASE WHEN $ok THEN t.amount END),0) total FROM payment_transactions t JOIN payment_methods m ON m.id = t.method_id
            WHERE t.is_test = 0 AND t.created_at BETWEEN ? AND ? GROUP BY m.id ORDER BY total DESC", $p);
        $terminals = DB::all("SELECT pt.name, pt.status, COUNT(t.id) n, SUM($ok) ok,
                (SELECT ROUND(AVG(l.duration_ms)) FROM payment_transaction_logs l WHERE l.terminal_id = pt.id AND l.action = 'initiate' AND l.created_at BETWEEN ? AND ?) ms
            FROM payment_terminals pt LEFT JOIN payment_transactions t ON t.terminal_id = pt.id AND t.created_at BETWEEN ? AND ? GROUP BY pt.id ORDER BY pt.name", array_merge($p, $p));
        $warnings = [];
        if (!DB::value('SELECT 1 FROM payment_methods WHERE active = 1')) {
            $warnings[] = 'Aucun moyen de paiement électronique actif : le bouton « Paiement électronique » n\'apparaît pas en caisse.';
        }
        if (DB::value("SELECT 1 FROM payment_connectors WHERE active = 1 AND environment = 'test'")) {
            $warnings[] = 'Des connecteurs actifs sont en environnement TEST : leurs transactions ne sont jamais comptées comme des encaissements réels.';
        }
        if ($n = (int) DB::value("SELECT COUNT(*) FROM payment_transactions WHERE status = 'succeeded' AND sale_id IS NULL AND is_test = 0 AND created_at < NOW() - INTERVAL 1 HOUR")) {
            $warnings[] = "$n paiement(s) réussi(s) sans vente depuis plus d'une heure : à rattacher ou à rembourser (Transactions).";
        }
        $this->show('dashboard', 'Marwa Pay Connect', 'dashboard', compact('from', 'to', 'k', 'test', 'byProvider', 'byMethod', 'terminals', 'warnings'));
    }

    // ------------------------------------------------------------------ 2. prestataires

    public function providers(): void
    {
        $edit = Request::int('modifier') ? DB::one('SELECT * FROM payment_providers WHERE id = ?', [Request::int('modifier')]) : null;
        $this->show('providers', 'Fournisseurs de paiement', 'providers', ['edit' => $edit,
            'rows' => DB::all('SELECT p.*, (SELECT COUNT(*) FROM payment_connectors c WHERE c.provider_id = p.id) connectors FROM payment_providers p ORDER BY p.active DESC, p.name')]);
    }

    public function providerSave(int $id = 0): void
    {
        $this->attempt(fn() => A::saveProvider($_POST, $id ?: null), '/pay/prestataires' . ($id ? "?modifier=$id" : ''));
        flash('success', 'Prestataire enregistré.');
        redirect('/pay/prestataires');
    }

    // ------------------------------------------------------------------ 3. connecteurs (API)

    public function connectors(): void
    {
        $this->show('connectors', 'Gestion des API', 'api', ['rows' => DB::all('SELECT c.*, p.name provider, s.name store,
                (SELECT COUNT(*) FROM payment_transactions t WHERE t.connector_id = c.id) used FROM payment_connectors c
                LEFT JOIN payment_providers p ON p.id = c.provider_id LEFT JOIN stores s ON s.id = c.store_id ORDER BY c.active DESC, c.name')]);
    }

    public function connectorForm(int $id = 0): void
    {
        $c = $id ? $this->attempt(fn() => A::connector($id), '/pay/connecteurs') : null;
        $this->show('connector', $c ? 'API : ' . $c['name'] : 'Nouvelle connexion API', 'api', ['c' => $c, 'drivers' => DriverRegistry::gateways(),
            'providers' => DB::all('SELECT id, name FROM payment_providers WHERE active = 1 ORDER BY name'), 'stores' => DB::all('SELECT id, name FROM stores WHERE active = 1 ORDER BY name'),
            'hints' => $c ? Secrets::hints('connector', $id) : [], 'certs' => $c ? A::certificates($id) : [],
            'webhook' => $c ? DB::one('SELECT id, created_at, last_received_at FROM payment_webhooks WHERE connector_id = ?', [$id]) : null]);
    }

    public function connectorSave(int $id = 0): void
    {
        $new = $this->attempt(fn() => A::saveConnector($_POST, $id ?: null), $id ? "/pay/connecteurs/$id" : '/pay/connecteurs/nouveau');
        flash('success', 'Connexion enregistrée (inactive tant que vous ne l\'activez pas). Testez-la avant de l\'activer.');
        redirect("/pay/connecteurs/$new");
    }

    public function connectorTest(int $id): void
    {
        $r = $this->attempt(fn() => A::test('connector', $id), "/pay/connecteurs/$id");
        flash($r->isOk() ? 'success' : 'danger', ($r->isOk() ? 'Test réussi : ' : 'Échec du test (' . $r->code . ') : ') . $r->message);
        redirect("/pay/connecteurs/$id");
    }

    public function connectorToggle(int $id): void
    {
        $on = $this->attempt(fn() => A::toggleConnector($id), "/pay/connecteurs/$id");
        flash('success', $on ? 'Connexion activée.' : 'Connexion désactivée.');
        redirect("/pay/connecteurs/$id");
    }

    public function connectorDelete(int $id): void
    {
        $this->attempt(fn() => A::deleteConnector($id), "/pay/connecteurs/$id");
        flash('success', 'Connexion supprimée.');
        redirect('/pay/connecteurs');
    }

    public function secretsSave(int $id): void
    {
        $type = Request::str('owner') === 'terminal' ? 'terminal' : 'connector';
        $back = $type === 'terminal' ? "/pay/terminaux/$id" : "/pay/connecteurs/$id";
        $n = $this->attempt(fn() => A::saveSecrets($type, $id, (array) ($_POST['secret'] ?? []), (array) ($_POST['clear'] ?? [])), $back);
        flash('success', $n ? "$n secret(s) mis à jour (chiffrés, jamais réaffichés)." : 'Aucun secret modifié.');
        redirect($back);
    }

    public function certificateUpload(int $id): void
    {
        $f = $this->upload('file') ?? $this->fail('Choisissez un fichier PEM.', "/pay/connecteurs/$id");
        $this->attempt(fn() => A::saveCertificate($id, Request::str('kind'), $f['tmp_name']), "/pay/connecteurs/$id");
        flash('success', 'Certificat enregistré hors du dossier public.');
        redirect("/pay/connecteurs/$id");
    }

    public function webhookCreate(int $id): void
    {
        $w = $this->attempt(fn() => A::createWebhook($id), "/pay/connecteurs/$id");
        flash('warning', 'Adresse de notification : ' . url('/pay/webhook/' . $w['id'], ['t' => $w['token']]) . ' — secret de signature (affiché une seule fois) : ' . $w['secret']);
        redirect("/pay/connecteurs/$id");
    }

    public function openapiImport(int $id): void
    {
        $f = $this->upload('openapi') ?? $this->fail('Choisissez un fichier OpenAPI (JSON).', "/pay/connecteurs/$id");
        $o = $this->attempt(fn() => A::importOpenApi($id, (string) file_get_contents($f['tmp_name'])), "/pay/connecteurs/$id");
        flash('success', count($o['operations']) . ' opération(s) lue(s). Les correspondances restent à établir d\'après la documentation officielle.');
        redirect("/pay/connecteurs/$id");
    }

    // ------------------------------------------------------------------ 5. moyens de paiement

    public function methods(): void
    {
        $edit = Request::int('modifier') ? DB::one('SELECT * FROM payment_methods WHERE id = ?', [Request::int('modifier')]) : null;
        $this->show('methods', 'Moyens de paiement', 'methods', ['edit' => $edit,
            'rows' => DB::all('SELECT m.*, c.name connector, c.environment, c.active connector_active FROM payment_methods m LEFT JOIN payment_connectors c ON c.id = m.connector_id ORDER BY m.active DESC, m.sort, m.label'),
            'connectors' => DB::all('SELECT id, name, environment, active FROM payment_connectors ORDER BY name'), 'registers' => DB::all('SELECT id, name FROM cash_registers WHERE active = 1 ORDER BY name'),
            'currencies' => DB::all('SELECT code, name FROM payment_currencies WHERE active = 1 ORDER BY code')]);
    }

    public function methodSave(int $id = 0): void
    {
        $this->attempt(fn() => A::saveMethod($_POST, $id ?: null), '/pay/moyens' . ($id ? "?modifier=$id" : ''));
        flash('success', 'Moyen de paiement enregistré (activez-le pour le proposer en caisse).');
        redirect('/pay/moyens');
    }

    public function methodToggle(int $id): void
    {
        $on = $this->attempt(fn() => A::toggleMethod($id), '/pay/moyens');
        flash('success', $on ? 'Moyen activé : proposé dans « Paiement électronique ».' : 'Moyen désactivé.');
        redirect('/pay/moyens');
    }

    // ------------------------------------------------------------------ 6. transactions

    public function transactions(): void
    {
        [$from, $to] = date_range();
        $w = ['t.created_at BETWEEN ? AND ?'];
        $p = ["$from 00:00:00", "$to 23:59:59"];
        foreach (['register_id' => 'caisse', 'user_id' => 'utilisateur', 'connector_id' => 'prestataire', 'terminal_id' => 'terminal', 'method_id' => 'moyen'] as $col => $q) {
            if (Request::int($q)) {
                $w[] = "t.$col = ?";
                $p[] = Request::int($q);
            }
        }
        if (isset(PayEngine::STATUSES[$st = Request::str('statut')])) {
            $w[] = 't.status = ?';
            $p[] = $st;
        }
        if (($q = Request::str('q')) !== '') {
            $w[] = '(t.number LIKE ? OR t.provider_reference LIKE ? OR t.authorization_code LIKE ? OR s.number LIKE ?)';
            array_push($p, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        $test = Request::str('test');
        if ($test !== 'tous') {
            $w[] = 't.is_test = ?';
            $p[] = $test === '1' ? 1 : 0;
        }
        $sql = 'FROM payment_transactions t JOIN payment_methods m ON m.id = t.method_id LEFT JOIN payment_connectors c ON c.id = t.connector_id LEFT JOIN payment_terminals pt ON pt.id = t.terminal_id
            LEFT JOIN sales s ON s.id = t.sale_id LEFT JOIN customers cu ON cu.id = t.customer_id LEFT JOIN cash_registers r ON r.id = t.register_id LEFT JOIN users u ON u.id = t.user_id WHERE ' . implode(' AND ', $w);
        $sel = 't.*, m.label method, c.name connector, pt.name terminal, s.number sale_number, cu.name customer, r.name register, u.full_name cashier';
        if (Export::requested() && can('pay.reports')) {
            Export::download('transactions-electroniques', 'Historique des paiements', ['Vente', 'Transaction', 'Client', 'Montant', 'Devise', 'Moyen', 'Prestataire', 'Terminal', 'Date', 'Référence', 'Autorisation', 'Statut', 'Remboursé', 'Frais', 'TEST'],
                array_map(fn($r) => [$r['sale_number'], $r['number'], $r['customer'], money($r['amount'], false), $r['currency'], $r['method'], $r['connector'], $r['terminal'], fdate($r['created_at']), $r['provider_reference'],
                    $r['authorization_code'], PayEngine::STATUSES[$r['status']], money($r['refunded_amount'], false), $r['fees'] !== null ? money($r['fees'], false) : '', (int) $r['is_test'] ? 'TEST' : ''], DB::all("SELECT $sel $sql ORDER BY t.id DESC LIMIT 50000", $p)),
                ['period' => Export::period($from, $to)]);
        }
        $this->show('transactions', 'Historique des paiements', 'transactions', ['p' => paginate($sql, $p, $sel, 't.id DESC', 30), 'from' => $from, 'to' => $to,
            'registers' => DB::all('SELECT id, name FROM cash_registers ORDER BY name'), 'users' => DB::all('SELECT id, full_name FROM users ORDER BY full_name'),
            'connectors' => DB::all('SELECT id, name FROM payment_connectors ORDER BY name'), 'terminals' => DB::all('SELECT id, name FROM payment_terminals ORDER BY name')]);
    }

    public function transaction(int $id): void
    {
        $t = DB::one('SELECT t.*, m.label method, m.sale_method, c.name connector, pt.name terminal, s.number sale_number, cu.name customer, r.name register, u.full_name cashier
            FROM payment_transactions t JOIN payment_methods m ON m.id = t.method_id LEFT JOIN payment_connectors c ON c.id = t.connector_id LEFT JOIN payment_terminals pt ON pt.id = t.terminal_id
            LEFT JOIN sales s ON s.id = t.sale_id LEFT JOIN customers cu ON cu.id = t.customer_id LEFT JOIN cash_registers r ON r.id = t.register_id LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?', [$id]);
        if (!$t) {
            throw new HttpException(404);
        }
        $target = $t['terminal_id'] ? DB::one('SELECT * FROM payment_terminals WHERE id = ?', [$t['terminal_id']]) : DB::one('SELECT * FROM payment_connectors WHERE id = ?', [$t['connector_id']]);
        $this->show('transaction', 'Transaction ' . $t['number'], 'transactions', ['t' => $t, 'refunds' => DB::all('SELECT f.*, u.full_name requester, a.full_name approver FROM payment_refunds f
                LEFT JOIN users u ON u.id = f.requested_by LEFT JOIN users a ON a.id = f.authorized_by WHERE f.transaction_id = ? ORDER BY f.id', [$id]),
            'canRefund' => $target && DriverRegistry::supports($target, 'refund'), 'canCancel' => $target && DriverRegistry::supports($target, $t['status'] === 'succeeded' ? 'void' : 'cancel'),
            'logs' => can('pay.logs') ? DB::all('SELECT * FROM payment_transaction_logs WHERE transaction_id = ? ORDER BY id', [$id]) : []]);
    }

    public function refresh(int $id): void
    {
        $t = $this->attempt(fn() => PayEngine::refresh($id), "/pay/transactions/$id");
        flash('info', 'État : ' . PayEngine::STATUSES[$t['status']] . '. ' . ($t['message'] ?? ''));
        redirect("/pay/transactions/$id");
    }

    public function cancel(int $id): void
    {
        $this->attempt(fn() => PayEngine::cancel($id), "/pay/transactions/$id");
        flash('success', 'Annulation confirmée par le prestataire.');
        redirect("/pay/transactions/$id");
    }

    public function refund(int $id): void
    {
        $sup = Request::str('sup_username') !== '' ? ['username' => Request::str('sup_username'), 'secret' => (string) ($_POST['sup_secret'] ?? '')] : null;
        $f = $this->attempt(fn() => PayEngine::refund($id, Request::str('amount'), Request::str('reason'), Request::str('request_key'), $sup), "/pay/transactions/$id");
        $msg = ['succeeded' => 'Remboursement confirmé par le prestataire.', 'pending' => 'Remboursement transmis : en attente de confirmation du prestataire.',
            'failed' => 'Remboursement refusé ou impossible : ' . $f['message'], 'to_verify' => 'Remboursement à vérifier (réponse incomplète) : ' . $f['message']][$f['status']];
        flash($f['status'] === 'succeeded' ? 'success' : ($f['status'] === 'failed' ? 'danger' : 'warning'), $msg);
        redirect("/pay/transactions/$id");
    }

    // ------------------------------------------------------------------ 7. remboursements

    public function refunds(): void
    {
        [$from, $to] = date_range();
        $sql = 'FROM payment_refunds f JOIN payment_transactions t ON t.id = f.transaction_id LEFT JOIN users u ON u.id = f.requested_by LEFT JOIN users a ON a.id = f.authorized_by WHERE f.created_at BETWEEN ? AND ?';
        $p = ["$from 00:00:00", "$to 23:59:59"];
        $sel = 'f.*, t.number tx_number, t.is_test, u.full_name requester, a.full_name approver';
        if (Export::requested() && can('pay.reports')) {
            Export::download('remboursements', 'Remboursements', ['Remboursement', 'Transaction', 'Montant', 'Devise', 'Motif', 'Statut', 'Référence', 'Demandé par', 'Autorisé par', 'Date'],
                array_map(fn($r) => [$r['number'], $r['tx_number'], money($r['amount'], false), $r['currency'], $r['reason'], $r['status'], $r['provider_reference'], $r['requester'], $r['approver'], fdate($r['created_at'])],
                    DB::all("SELECT $sel $sql ORDER BY f.id DESC", $p)), ['period' => Export::period($from, $to)]);
        }
        $this->show('refunds', 'Remboursements', 'refunds', ['p' => paginate($sql, $p, $sel, 'f.id DESC', 30), 'from' => $from, 'to' => $to]);
    }

    public function refundRefresh(int $id): void
    {
        $f = PayEngine::refreshRefund($id);
        flash('info', 'Remboursement : ' . ($f['status'] ?? '?') . '. ' . ($f['message'] ?? ''));
        redirect('/pay/remboursements');
    }

    // ------------------------------------------------------------------ 8. rapprochement

    public function reconciliation(): void
    {
        $batch = Request::str('lot');
        $w = $batch !== '' ? 'WHERE r.batch = ?' : (Request::str('vue') === 'tout' ? '' : "WHERE r.resolved = 0 AND r.result <> 'matched'");
        $p = paginate("FROM payment_reconciliation r LEFT JOIN payment_transactions t ON t.id = r.transaction_id $w", $batch !== '' ? [$batch] : [], 'r.*, t.number tx_number, t.status tx_status', 'r.id DESC', 50);
        $this->show('reconciliation', 'Rapprochement bancaire', 'reconciliation', ['p' => $p, 'batch' => $batch, 'connectors' => DB::all('SELECT id, name FROM payment_connectors ORDER BY name'),
            'batches' => DB::all("SELECT batch, MIN(source_name) src, MIN(created_at) at, COUNT(*) n, SUM(result = 'matched') ok FROM payment_reconciliation GROUP BY batch ORDER BY at DESC LIMIT 10")]);
    }

    public function reconciliationImport(): void
    {
        $f = $this->upload('file') ?? $this->fail('Choisissez le relevé (CSV).', '/pay/rapprochement');
        $r = $this->attempt(fn() => Reconciliation::import((string) file_get_contents($f['tmp_name']), Request::int('connector_id') ?: null, (string) $f['name']), '/pay/rapprochement');
        flash('info', sprintf('Relevé importé : %d concordance(s) (dont %d confirmation(s)), %d écart(s) de montant, %d écart(s) de statut, %d absente(s) chez nous, %d absente(s) du relevé.',
            $r['matched'], $r['confirmed'], $r['amount_mismatch'], $r['status_mismatch'], $r['missing_local'], $r['missing_provider']));
        redirect('/pay/rapprochement', ['lot' => $r['batch']]);
    }

    public function reconciliationResolve(int $id): void
    {
        $this->attempt(fn() => Reconciliation::resolve($id, Request::str('note')), '/pay/rapprochement');
        flash('success', 'Écart marqué comme traité.');
        redirect('/pay/rapprochement');
    }

    // ------------------------------------------------------------------ 9. rapports

    public const REPORTS = ['jour' => 'Paiements journaliers', 'mois' => 'Paiements mensuels', 'prestataire' => 'Par fournisseur', 'moyen' => 'Par moyen de paiement',
        'caisse' => 'Par caisse', 'etablissement' => 'Par établissement', 'echecs' => 'Transactions échouées', 'remboursements' => 'Remboursements', 'rapprochement' => 'Rapprochement'];

    public function reports(): void
    {
        [$from, $to] = date_range();
        $type = isset(self::REPORTS[Request::str('rapport')]) ? Request::str('rapport') : 'jour';
        $p = ["$from 00:00:00", "$to 23:59:59"];
        $ok = "t.status IN ('succeeded','refunded','partially_refunded')";
        $agg = "COUNT(*) n, SUM($ok) ok, SUM(t.status = 'declined') ko, COALESCE(SUM(CASE WHEN $ok THEN t.amount END),0) total, COALESCE(SUM(CASE WHEN $ok THEN t.fees END),0) fees, COALESCE(SUM(t.refunded_amount),0) refunded";
        $base = 'FROM payment_transactions t JOIN payment_methods m ON m.id = t.method_id LEFT JOIN payment_connectors c ON c.id = t.connector_id LEFT JOIN payment_terminals pt ON pt.id = t.terminal_id
            LEFT JOIN cash_registers r ON r.id = t.register_id LEFT JOIN stores s ON s.id = t.store_id WHERE t.is_test = 0 AND t.created_at BETWEEN ? AND ?';
        [$headers, $rows] = match ($type) {
            'jour', 'mois', 'prestataire', 'moyen', 'caisse', 'etablissement' => [
                [['jour' => 'Jour', 'mois' => 'Mois', 'prestataire' => 'Fournisseur', 'moyen' => 'Moyen', 'caisse' => 'Caisse', 'etablissement' => 'Établissement'][$type], 'Transactions', 'Réussies', 'Refusées', 'Montant encaissé', 'Frais', 'Remboursé'],
                array_map(fn($r) => [$r['k'], (int) $r['n'], (int) $r['ok'], (int) $r['ko'], money($r['total'], false), money($r['fees'], false), money($r['refunded'], false)],
                    DB::all('SELECT ' . ['jour' => 'DATE(t.created_at)', 'mois' => "DATE_FORMAT(t.created_at, '%Y-%m')", 'prestataire' => 'COALESCE(c.name, pt.name)', 'moyen' => 'm.label', 'caisse' => 'r.name', 'etablissement' => 's.name'][$type]
                        . " k, $agg $base GROUP BY k ORDER BY k", $p))],
            'echecs' => [['Transaction', 'Date', 'Moyen', 'Montant', 'Statut', 'Message'],
                array_map(fn($r) => [$r['number'], fdate($r['created_at']), $r['label'], money($r['amount'], false), PayEngine::STATUSES[$r['status']], $r['message']],
                    DB::all("SELECT t.*, m.label $base AND t.status IN ('declined','failed','expired','cancelled','to_verify') ORDER BY t.id DESC LIMIT 5000", $p))],
            'remboursements' => [['Remboursement', 'Transaction', 'Date', 'Montant', 'Statut', 'Motif'],
                array_map(fn($r) => [$r['number'], $r['tx'], fdate($r['created_at']), money($r['amount'], false), $r['status'], $r['reason']],
                    DB::all('SELECT f.*, t.number tx FROM payment_refunds f JOIN payment_transactions t ON t.id = f.transaction_id WHERE t.is_test = 0 AND f.created_at BETWEEN ? AND ? ORDER BY f.id DESC', $p))],
            'rapprochement' => [['Résultat', 'Lignes', 'Montant', 'Non traités'],
                array_map(fn($r) => [$r['result'], (int) $r['n'], money($r['amt'], false), (int) $r['open']],
                    DB::all('SELECT result, COUNT(*) n, COALESCE(SUM(amount),0) amt, SUM(resolved = 0) open FROM payment_reconciliation WHERE created_at BETWEEN ? AND ? GROUP BY result', $p))],
        };
        if (Export::requested()) {
            Export::download('marwa-pay-' . $type, self::REPORTS[$type], $headers, $rows, ['period' => Export::period($from, $to)]);
        }
        $this->show('reports', 'Rapports', 'reports', compact('from', 'to', 'type', 'headers', 'rows'));
    }

    // ------------------------------------------------------------------ 10. journaux

    public function logs(): void
    {
        $w = ['1 = 1'];
        $pr = [];
        if (($c = Request::str('resultat')) !== '') {
            $w[] = 'l.result_code = ?';
            $pr[] = $c;
        }
        $p = paginate('FROM payment_transaction_logs l LEFT JOIN payment_transactions t ON t.id = l.transaction_id LEFT JOIN payment_connectors c ON c.id = l.connector_id LEFT JOIN payment_terminals pt ON pt.id = l.terminal_id WHERE ' . implode(' AND ', $w),
            $pr, 'l.id, l.transaction_id, l.action, l.result_code, l.http_status, l.duration_ms, l.message, l.created_at, t.number, COALESCE(c.name, pt.name) target', 'l.id DESC', 40);
        $this->show('logs', 'Journaux techniques', 'logs', ['p' => $p]);
    }

    public function logDetail(int $id): void
    {
        $this->show('log', 'Échange n° ' . $id, 'logs', ['l' => $this->findOr404('payment_transaction_logs', $id)]);
    }

    // ------------------------------------------------------------------ 11. paramètres

    public function settings(): void
    {
        $this->show('settings', 'Paramètres', 'settings', ['s' => array_merge(A::SETTINGS, array_column(DB::all('SELECT skey, value FROM payment_settings'), 'value', 'skey')),
            'currencies' => DB::all('SELECT * FROM payment_currencies ORDER BY code'), 'rates' => DB::all('SELECT r.*, u.full_name FROM payment_exchange_rates r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.id DESC LIMIT 50'),
            'shop' => PayEngine::shopCurrency()]);
    }

    public function settingsSave(): void
    {
        $this->attempt(fn() => A::saveSettings($_POST), '/pay/parametres');
        flash('success', 'Paramètres enregistrés.');
        redirect('/pay/parametres');
    }

    public function currencyAdd(): void
    {
        $this->attempt(fn() => A::addCurrency(Request::str('code'), Request::str('name'), Request::int('decimals', 2)), '/pay/parametres');
        flash('success', 'Devise ajoutée.');
        redirect('/pay/parametres');
    }

    public function rateAdd(): void
    {
        $this->attempt(fn() => A::addRate($_POST), '/pay/parametres');
        flash('success', 'Taux enregistré (utilisé à partir de sa date).');
        redirect('/pay/parametres');
    }
}
