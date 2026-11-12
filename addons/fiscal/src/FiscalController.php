<?php
declare(strict_types=1);

namespace Addons\Fiscal;

use App\Controllers\Controller;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Export;
use App\Core\HttpException;
use App\Core\Request;
use Addons\Fiscal\FiscalAdmin as A;

final class FiscalController extends Controller
{
    private function show(string $view, string $title, string $tab, array $data = []): never
    {
        view('fiscal::' . $view, ['title' => $title, 'tab' => $tab] + $data);
    }

    // ------------------------------------------------------------------ 1. tableau de bord

    public function dashboard(): void
    {
        $by = [];
        foreach (DB::all('SELECT status, is_simulation, COUNT(*) n FROM fiscal_certifications GROUP BY status, is_simulation') as $r) {
            $k = $r['status'] . ((int) $r['is_simulation'] ? '_sim' : '');
            $by[$k] = (int) $r['n'];
        }
        $today = DB::one("SELECT SUM(status = 'certified') ok, SUM(status IN ('pending','sending')) wait, SUM(status IN ('rejected','failed')) ko FROM fiscal_certifications WHERE created_at >= CURDATE()");
        $warnings = [];
        $profiles = DB::all('SELECT p.*, c.name AS country, c.configured FROM fiscal_profiles p JOIN fiscal_countries c ON c.id = p.country_id WHERE p.active = 1');
        if (!$profiles) {
            $warnings[] = 'Aucun profil fiscal actif : les ventes ne sont pas transmises (menu « Paramètres fiscaux »).';
        }
        foreach ($profiles as $p) {
            if ($p['environment'] === 'test') {
                $warnings[] = 'Le profil « ' . $p['name'] . ' » est en environnement TEST : aucune facture n\'a de valeur fiscale.';
            }
            if (!(int) $p['configured']) {
                $warnings[] = 'Les règles fiscales de « ' . $p['country'] . ' » ne sont pas configurées d\'après les textes officiels.';
            }
        }
        $this->show('dashboard', 'Fiscal Connect', 'dashboard', ['by' => $by, 'today' => $today, 'warnings' => $warnings, 'profiles' => $profiles,
            'connectors' => DB::all('SELECT * FROM fiscal_connectors ORDER BY active DESC, name'),
            'queue' => (int) DB::value('SELECT COUNT(*) FROM fiscal_pending_queue'),
            'lastErrors' => DB::all("SELECT id, local_number, status, last_error, updated_at FROM fiscal_certifications WHERE status IN ('failed','rejected','pending') AND last_error IS NOT NULL ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 8")]);
    }

    // ------------------------------------------------------------------ 2-3. connecteurs (MCF / API)

    public function connectors(): void
    {
        $type = Request::query('type') === 'api' ? 'api' : 'mcf';
        $kinds = $type === 'api' ? ['api', 'cloud', 'simulation'] : ['mcf', 'simulation'];
        $rows = DB::all('SELECT c.*, d.device_id, d.model, d.last_seen_at, s.name AS store, r.name AS register,
                (SELECT COUNT(*) FROM fiscal_certifications x WHERE x.connector_id = c.id) AS used
            FROM fiscal_connectors c LEFT JOIN fiscal_devices d ON d.connector_id = c.id LEFT JOIN stores s ON s.id = c.store_id LEFT JOIN cash_registers r ON r.id = c.register_id
            WHERE c.kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ') ORDER BY c.environment DESC, c.name', $kinds);
        $this->show('connectors', $type === 'api' ? 'Connexions API' : 'Dispositifs MCF', $type, ['type' => $type, 'rows' => $rows]);
    }

    public function connectorForm(int $id = 0): void
    {
        $c = $id ? $this->attempt(fn() => A::connector($id), '/fiscal/connecteurs') : null;
        $type = Request::query('type') === 'api' || ($c && in_array($c['kind'], ['api', 'cloud'], true)) ? 'api' : 'mcf';
        $this->show('connector', $c ? 'Connecteur : ' . $c['name'] : 'Nouveau connecteur', $type, ['c' => $c, 'type' => $type,
            'drivers' => DriverRegistry::all(), 'stores' => DB::all('SELECT id, name FROM stores WHERE active = 1 ORDER BY name'),
            'registers' => DB::all('SELECT id, name, store_id FROM cash_registers WHERE active = 1 ORDER BY name'),
            'hints' => $c ? Secrets::hints((int) $c['id']) : [], 'certs' => $c ? DB::all('SELECT * FROM fiscal_certificates WHERE connector_id = ? ORDER BY kind', [$c['id']]) : [],
            'webhook' => $c ? DB::one('SELECT id, token, active, last_received_at, created_at FROM fiscal_webhooks WHERE connector_id = ?', [$c['id']]) : null,
            'device' => $c ? DB::one('SELECT * FROM fiscal_devices WHERE connector_id = ?', [$c['id']]) : null]);
    }

    public function connectorSave(int $id = 0): void
    {
        $back = $id ? "/fiscal/connecteurs/$id" : '/fiscal/connecteurs/nouveau?type=' . (Request::str('kind') === 'mcf' ? 'mcf' : 'api');
        $new = $this->attempt(fn() => A::saveConnector($_POST, $id ?: null), $back);
        flash('success', 'Connecteur enregistré. Testez-le, puis activez-le.');
        redirect("/fiscal/connecteurs/$new");
    }

    public function connectorTest(int $id): void
    {
        $r = $this->attempt(fn() => A::testConnector($id), "/fiscal/connecteurs/$id");
        flash($r->isOk() ? 'success' : 'danger', ($r->isOk() ? 'Test réussi : ' : 'Échec du test (' . $r->code . ') : ') . $r->message);
        redirect("/fiscal/connecteurs/$id");
    }

    public function connectorToggle(int $id): void
    {
        $on = $this->attempt(fn() => A::toggleConnector($id), "/fiscal/connecteurs/$id");
        flash('success', $on ? 'Connecteur activé.' : 'Connecteur désactivé.');
        redirect("/fiscal/connecteurs/$id");
    }

    public function connectorDefault(int $id): void
    {
        $this->attempt(fn() => A::setDefault($id), "/fiscal/connecteurs/$id");
        flash('success', 'Connecteur par défaut de son environnement.');
        redirect("/fiscal/connecteurs/$id");
    }

    public function connectorDelete(int $id): void
    {
        $c = $this->attempt(fn() => A::connector($id), '/fiscal');
        $this->attempt(fn() => A::deleteConnector($id), "/fiscal/connecteurs/$id");
        flash('success', 'Connecteur supprimé.');
        redirect('/fiscal/connecteurs', ['type' => in_array($c['kind'], ['api', 'cloud'], true) ? 'api' : 'mcf']);
    }

    public function secretsSave(int $id): void
    {
        $n = $this->attempt(fn() => A::saveSecrets($id, (array) ($_POST['secret'] ?? []), (array) ($_POST['clear'] ?? [])), "/fiscal/connecteurs/$id");
        flash('success', $n ? "$n secret(s) mis à jour (chiffrés, non réaffichés)." : 'Aucun secret modifié.');
        redirect("/fiscal/connecteurs/$id");
    }

    public function openapiImport(int $id): void
    {
        $f = $_FILES['openapi'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) && PHP_SAPI !== 'cli') {
            $this->fail('Choisissez un fichier OpenAPI (JSON).', "/fiscal/connecteurs/$id");
        }
        $o = $this->attempt(fn() => A::importOpenApi($id, (string) file_get_contents($f['tmp_name'])), "/fiscal/connecteurs/$id");
        flash('success', count($o['operations']) . ' opération(s) lue(s). Les correspondances des champs fiscaux restent à établir d\'après la documentation officielle.');
        redirect("/fiscal/connecteurs/$id");
    }

    public function webhookCreate(int $id): void
    {
        $w = $this->attempt(fn() => A::createWebhook($id), "/fiscal/connecteurs/$id");
        flash('warning', 'Webhook créé. Adresse : ' . url('/fiscal/webhook/' . $w['id'], ['t' => $w['token']]) . ' — secret de signature HMAC-SHA256 (affiché une seule fois) : ' . $w['secret']);
        redirect("/fiscal/connecteurs/$id");
    }

    // ------------------------------------------------------------------ 6. certificats

    public function certificates(): void
    {
        $this->show('certificates', 'Certificats', 'certs', ['rows' => DB::all('SELECT f.*, c.name AS connector FROM fiscal_certificates f JOIN fiscal_connectors c ON c.id = f.connector_id ORDER BY c.name, f.kind'),
            'connectors' => DB::all("SELECT id, name, environment FROM fiscal_connectors WHERE kind <> 'simulation' ORDER BY name")]);
    }

    public function certificateUpload(): void
    {
        $f = $_FILES['file'] ?? null;
        $cid = Request::int('connector_id');
        $back = Request::str('back') === 'connector' && $cid ? "/fiscal/connecteurs/$cid" : '/fiscal/certificats';
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || (!is_uploaded_file($f['tmp_name']) && PHP_SAPI !== 'cli')) {
            $this->fail('Choisissez un fichier PEM.', $back);
        }
        $this->attempt(fn() => A::saveCertificate($cid, Request::str('kind'), $f['tmp_name'], (string) $f['name'], (string) ($_POST['password'] ?? '')), $back);
        flash('success', 'Certificat enregistré hors du dossier public.');
        redirect($back);
    }

    public function certificateDelete(int $id): void
    {
        $this->attempt(fn() => A::deleteCertificate($id), '/fiscal/certificats');
        flash('success', 'Certificat supprimé.');
        redirect('/fiscal/certificats');
    }

    // ------------------------------------------------------------------ 4. pays

    public function countries(): void
    {
        $this->show('countries', 'Pays et règles fiscales', 'countries', ['rows' => DB::all('SELECT c.*, (SELECT COUNT(*) FROM fiscal_tax_rates t WHERE t.country_id = c.id) AS rates FROM fiscal_countries c ORDER BY c.name')]);
    }

    public function countryForm(int $id): void
    {
        $c = $this->findOr404('fiscal_countries', $id);
        $this->show('country', 'Pays : ' . $c['name'], 'countries', ['c' => $c, 'rates' => DB::all('SELECT * FROM fiscal_tax_rates WHERE country_id = ? ORDER BY group_code', [$id])]);
    }

    public function countrySave(int $id): void
    {
        $this->attempt(fn() => A::saveCountry($id, $_POST), "/fiscal/pays/$id");
        flash('success', 'Règles du pays enregistrées.');
        redirect("/fiscal/pays/$id");
    }

    public function countryAdd(): void
    {
        $id = $this->attempt(fn() => A::addCountry(Request::str('code'), Request::str('name'), Request::str('currency')), '/fiscal/pays');
        redirect("/fiscal/pays/$id");
    }

    public function taxRateSave(int $id): void
    {
        $this->findOr404('fiscal_countries', $id);
        $this->attempt(fn() => A::saveTaxRate($id, $_POST), "/fiscal/pays/$id");
        flash('success', 'Taux enregistré.');
        redirect("/fiscal/pays/$id");
    }

    public function taxRateDelete(int $id): void
    {
        $cid = $this->attempt(fn() => A::deleteTaxRate($id), '/fiscal/pays');
        flash('success', 'Taux supprimé.');
        redirect("/fiscal/pays/$cid");
    }

    // ------------------------------------------------------------------ 5. paramètres (profils)

    public function profiles(): void
    {
        $edit = Request::int('modifier') ? DB::one('SELECT * FROM fiscal_profiles WHERE id = ?', [Request::int('modifier')]) : null;
        $this->show('profiles', 'Paramètres fiscaux', 'profiles', ['rows' => DB::all('SELECT p.*, c.name AS country, c.configured, s.name AS store, f.name AS connector
                FROM fiscal_profiles p JOIN fiscal_countries c ON c.id = p.country_id LEFT JOIN stores s ON s.id = p.store_id LEFT JOIN fiscal_connectors f ON f.id = p.connector_id ORDER BY p.active DESC, p.name'),
            'edit' => $edit, 'countries' => DB::all('SELECT id, name, configured FROM fiscal_countries ORDER BY name'), 'stores' => DB::all('SELECT id, name FROM stores WHERE active = 1 ORDER BY name'),
            'connectors' => DB::all('SELECT id, name, environment, kind FROM fiscal_connectors ORDER BY name')]);
    }

    public function profileSave(int $id = 0): void
    {
        $this->attempt(fn() => A::saveProfile($_POST, $id ?: null), $id ? "/fiscal/parametres?modifier=$id" : '/fiscal/parametres');
        flash('success', 'Profil fiscal enregistré.');
        redirect('/fiscal/parametres');
    }

    public function profileToggle(int $id): void
    {
        $on = $this->attempt(fn() => A::toggleProfile($id), '/fiscal/parametres');
        flash('success', $on ? 'Profil activé : les nouvelles ventes seront transmises.' : 'Profil désactivé.');
        redirect('/fiscal/parametres');
    }

    // ------------------------------------------------------------------ 7-8. historique et file d'attente

    private function certList(array $where, array $params, string $title, string $tab, string $view): never
    {
        [$from, $to] = date_range();
        $where[] = 'x.created_at BETWEEN ? AND ?';
        array_push($params, "$from 00:00:00", "$to 23:59:59");
        $q = Request::str('q');
        if ($q !== '') {
            $where[] = '(x.local_number LIKE ? OR x.fiscal_number LIKE ?)';
            array_push($params, "%$q%", "%$q%");
        }
        $st = Request::str('statut');
        if (isset(A::STATUSES[$st])) {
            $where[] = 'x.status = ?';
            $params[] = $st;
        }
        $sql = 'FROM fiscal_certifications x LEFT JOIN fiscal_connectors c ON c.id = x.connector_id LEFT JOIN sales s ON s.id = x.sale_id LEFT JOIN fiscal_pending_queue q ON q.certification_id = x.id WHERE ' . implode(' AND ', $where);
        $sel = 'x.*, c.name AS connector, s.total, q.next_attempt_at';
        if (Export::requested() && can('fiscal.reports')) {
            Export::download('certifications-fiscales', $title, ['Document', 'Type', 'Numéro local', 'Statut', 'Numéro fiscal', 'Dispositif', 'Simulation', 'Montant TTC', 'Date', 'Certifiée le', 'Erreur'],
                array_map(fn($r) => [$r['id'], $r['doc_type'] === 'invoice' ? 'Facture' : 'Avoir', $r['local_number'], A::STATUSES[$r['status']], $r['fiscal_number'], $r['device_id'], (int) $r['is_simulation'] ? 'OUI – NON CERTIFIÉ' : 'non',
                    money($r['total'] ?? 0, false), fdate($r['created_at']), fdate($r['certified_at']), $r['last_error']], DB::all("SELECT $sel $sql ORDER BY x.id DESC LIMIT 20000", $params)), ['period' => Export::period($from, $to)]);
        }
        $this->show($view, $title, $tab, ['p' => paginate($sql, $params, $sel, 'x.id DESC', 30), 'from' => $from, 'to' => $to, 'q' => $q, 'st' => $st]);
    }

    public function history(): void
    {
        $this->certList(['1 = 1'], [], 'Historique des certifications', 'history', 'history');
    }

    public function pending(): void
    {
        $_GET['from'] ??= '2000-01-01';
        $this->certList(["x.status IN ('pending','sending','failed')"], [], 'Factures en attente', 'pending', 'pending');
    }

    public function certification(int $id): void
    {
        $c = DB::one('SELECT x.*, f.name AS connector, f.driver, p.name AS profile FROM fiscal_certifications x LEFT JOIN fiscal_connectors f ON f.id = x.connector_id LEFT JOIN fiscal_profiles p ON p.id = x.profile_id WHERE x.id = ?', [$id]);
        if (!$c) {
            throw new HttpException(404);
        }
        $this->show('certification', 'Certification ' . $c['local_number'], 'history', ['c' => $c,
            'logs' => can('fiscal.logs') ? DB::all('SELECT * FROM fiscal_invoice_logs WHERE certification_id = ? ORDER BY id', [$id]) : []]);
    }

    public function retry(int $id): void
    {
        $r = $this->attempt(fn() => FiscalEngine::attempt($id, true), '/fiscal/attente');
        flash($r['status'] === 'certified' ? 'success' : 'warning', ($r['status'] === 'certified' ? 'Certifiée. ' : 'Non certifiée. ') . $r['message']);
        redirect(Request::str('back') === 'detail' ? "/fiscal/certifications/$id" : '/fiscal/attente');
    }

    public function processQueue(): void
    {
        $r = FiscalEngine::processQueue(50);
        flash('info', $r['processed'] . ' facture(s) traitée(s), ' . $r['certified'] . ' certifiée(s).');
        redirect('/fiscal/attente');
    }

    // ------------------------------------------------------------------ 9. rapports

    public function reports(): void
    {
        [$from, $to] = date_range();
        $days = DB::all("SELECT DATE(x.created_at) d, x.is_simulation sim,
                SUM(x.doc_type = 'invoice' AND x.status = 'certified') inv_ok, SUM(CASE WHEN x.doc_type = 'invoice' AND x.status = 'certified' THEN s.total ELSE 0 END) inv_total,
                SUM(CASE WHEN x.doc_type = 'invoice' AND x.status = 'certified' THEN s.tax_total ELSE 0 END) inv_tax,
                SUM(x.doc_type = 'credit_note' AND x.status = 'certified') cn_ok, SUM(x.status <> 'certified') not_ok
            FROM fiscal_certifications x JOIN sales s ON s.id = x.sale_id WHERE x.created_at BETWEEN ? AND ? GROUP BY DATE(x.created_at), x.is_simulation ORDER BY d DESC", ["$from 00:00:00", "$to 23:59:59"]);
        $uncovered = (int) DB::value("SELECT COUNT(*) FROM sales s WHERE s.created_at BETWEEN ? AND ? AND s.status <> 'cancelled' AND NOT EXISTS (SELECT 1 FROM fiscal_certifications x WHERE x.doc_type = 'invoice' AND x.doc_id = s.id)", ["$from 00:00:00", "$to 23:59:59"]);
        $conns = array_values(array_filter(DB::all('SELECT * FROM fiscal_connectors WHERE active = 1 ORDER BY name'), fn($c) => DriverRegistry::supports($c, 'getDailyReport') || DriverRegistry::supports($c, 'closeDay')));
        $this->show('reports', 'Rapports fiscaux', 'reports', ['days' => $days, 'from' => $from, 'to' => $to, 'uncovered' => $uncovered, 'conns' => $conns]);
    }

    public function deviceReport(int $id): void
    {
        $c = $this->attempt(fn() => A::connector($id), '/fiscal/rapports');
        $date = Request::str('date');
        if (!\App\Core\Validator::isDate($date)) {
            $this->fail('Date invalide.', '/fiscal/rapports');
        }
        $close = Request::str('action') === 'close';
        $fn = $close ? 'closeDay' : 'getDailyReport';
        if (!DriverRegistry::supports($c, $fn)) {
            $this->fail('Fonction non prise en charge par ce pilote.', '/fiscal/rapports');
        }
        $d = DriverRegistry::make($c);
        $r = FiscalEngine::call($d, $c, null, $fn, fn() => $close ? $d->closeDay($date) : $d->getDailyReport($date));
        if ($close && $r->isOk()) {
            FiscalAudit::log('device.close_day', 'connector', $id, null, ['date' => $date]);
        }
        $summary = $r->isOk() ? implode(' · ', array_map(fn($k, $v) => "$k : " . (is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)), array_keys($r->data), $r->data)) : '';
        flash($r->isOk() ? 'success' : 'danger', ($close ? 'Clôture ' : 'Rapport ') . $date . ' (' . $c['name'] . ') : ' . $r->message . ($summary ? ' — ' . mb_substr($summary, 0, 600) : ''));
        redirect('/fiscal/rapports');
    }

    // ------------------------------------------------------------------ 10. journaux

    public function logs(): void
    {
        $audit = Request::query('vue') === 'audit';
        if ($audit) {
            $p = paginate('FROM fiscal_audit_logs a LEFT JOIN users u ON u.id = a.user_id', [], 'a.*, u.full_name', 'a.id DESC', 40);
        } else {
            $w = ['1 = 1'];
            $pr = [];
            if (($code = Request::str('resultat')) !== '') {
                $w[] = 'l.result_code = ?';
                $pr[] = $code;
            }
            $p = paginate('FROM fiscal_invoice_logs l LEFT JOIN fiscal_connectors c ON c.id = l.connector_id LEFT JOIN fiscal_certifications x ON x.id = l.certification_id WHERE ' . implode(' AND ', $w), $pr,
                'l.id, l.certification_id, l.action, l.result_code, l.http_status, l.duration_ms, l.message, l.created_at, c.name AS connector, x.local_number', 'l.id DESC', 40);
        }
        $this->show('logs', 'Journaux techniques', 'logs', ['p' => $p, 'audit' => $audit]);
    }

    public function logDetail(int $id): void
    {
        $l = $this->findOr404('fiscal_invoice_logs', $id);
        $this->show('log', 'Échange n° ' . $id, 'logs', ['l' => $l]);
    }

    // ------------------------------------------------------------------ 11. documentation

    public function docs(): void
    {
        $files = ['INSTALLATION' => 'Installation', 'CONFIGURATION' => 'Guide de configuration', 'AJOUTER_UN_MCF' => 'Ajouter un MCF ou une API', 'API' => 'Pilotes et API', 'RAPPORT_DE_TESTS' => 'Rapport de tests'];
        $doc = Request::str('doc');
        $content = null;
        if (isset($files[$doc]) && is_file($f = dirname(__DIR__) . "/docs/$doc.md")) {
            $content = (string) file_get_contents($f);
        }
        $this->show('docs', 'Documentation des connecteurs', 'docs', ['drivers' => DriverRegistry::all(), 'files' => $files, 'doc' => $doc, 'content' => $content]);
    }
}
