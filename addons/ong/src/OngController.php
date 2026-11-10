<?php
declare(strict_types=1);

namespace Addons\Ong;

use App\Controllers\Controller;
use App\Core\DB;
use App\Core\Export;
use App\Core\Request;
use Addons\Ong\OngService as S;

final class OngController extends Controller
{
    /** Droit exigé pour enregistrer chaque fiche simple, et page de retour. */
    private const FICHES = [
        'bailleur' => ['ong.projets', '/ong/projets?onglet=bailleurs'], 'projet' => ['ong.projets', '/ong/projets'], 'compte' => ['ong.tresorerie', '/ong/tresorerie'],
        'personnel' => ['ong.personnel', '/ong/personnel'], 'vehicule' => ['ong.logistique', '/ong/logistique'], 'equipement' => ['ong.logistique', '/ong/logistique?onglet=equipements'],
        'article' => ['ong.logistique', '/ong/logistique?onglet=stocks'],
    ];

    private function lists(): array
    {
        return [
            'projets' => DB::all('SELECT id, code, name FROM ong_projets ORDER BY code'),
            'bailleurs' => DB::all('SELECT id, name FROM ong_bailleurs WHERE active = 1 ORDER BY name'),
            'convs' => DB::all("SELECT c.id, c.number, c.title, p.code AS projet_code FROM ong_conventions c JOIN ong_projets p ON p.id = c.projet_id WHERE c.status = 'active' ORDER BY c.number"),
            'lignes' => DB::all("SELECT l.id, l.code, l.label, l.convention_id, c.number FROM ong_lignes l JOIN ong_conventions c ON c.id = l.convention_id WHERE c.status = 'active' ORDER BY c.number, l.code"),
            'comptes' => DB::all('SELECT * FROM ong_comptes WHERE active = 1 ORDER BY kind, name'),
            'staff' => DB::all('SELECT id, matricule, full_name, position FROM ong_personnel WHERE active = 1 ORDER BY full_name'),
        ];
    }

    /** La convention est déduite de la ligne budgétaire choisie (une seule liste dans les formulaires). */
    private function withConvention(array $in): array
    {
        $in['convention_id'] = (int) DB::value('SELECT convention_id FROM ong_lignes WHERE id = ?', [(int) ($in['ligne_id'] ?? 0)]);
        return $in;
    }

    public function dashboard(): void
    {
        view('ong::dashboard', ['title' => 'Tableau de bord ONG', 'd' => S::dashboard()]);
    }

    public function saveSimple(): void
    {
        $entity = Request::str('entity');
        [$perm, $back] = self::FICHES[$entity] ?? ['', '/ong'];
        if ($perm === '' || !can($perm)) {
            $this->fail('Vous n\'avez pas le droit de modifier cette fiche.', '/ong');
        }
        $id = Request::int('id') ?: null;
        $in = $_POST;
        if (!array_key_exists('active', $in) && !$id) {
            $in['active'] = 1;
        }
        $this->attempt(fn () => S::saveSimple($entity, $in, $id), $back);
        flash('success', 'Fiche enregistrée.');
        redirect($back);
    }

    // ------------------------------------------------------------------ projets, bailleurs

    public function projets(): void
    {
        $projets = DB::all("SELECT p.*, (SELECT COUNT(*) FROM ong_conventions c WHERE c.projet_id = p.id) AS convs,
              (SELECT COALESCE(SUM(amount), 0) FROM ong_conventions c WHERE c.projet_id = p.id AND c.status <> 'negociation') AS budget,
              (SELECT COALESCE(SUM(amount), 0) FROM ong_depenses d WHERE d.projet_id = p.id AND d.status = 'payee') AS spent
            FROM ong_projets p ORDER BY FIELD(p.status, 'en_cours', 'preparation', 'suspendu', 'termine'), p.code");
        $bailleurs = DB::all("SELECT b.*, (SELECT COUNT(*) FROM ong_conventions c WHERE c.bailleur_id = b.id) AS convs,
              (SELECT COALESCE(SUM(amount), 0) FROM ong_conventions c WHERE c.bailleur_id = b.id AND c.status <> 'negociation') AS total FROM ong_bailleurs b ORDER BY b.active DESC, b.name");
        $edit = Request::int('edit');
        $onglet = Request::query('onglet') === 'bailleurs' ? 'bailleurs' : 'projets';
        view('ong::projets', ['title' => 'Projets et bailleurs', 'projets' => $projets, 'bailleurs' => $bailleurs, 'onglet' => $onglet,
            'edit' => $edit ? DB::one('SELECT * FROM ' . ($onglet === 'bailleurs' ? 'ong_bailleurs' : 'ong_projets') . ' WHERE id = ?', [$edit]) : null]);
    }

    public function projet(int $id): void
    {
        $p = $this->findOr404('ong_projets', $id);
        view('ong::projet', ['title' => $p['code'] . ' — ' . $p['name'], 'p' => $p,
            'convs' => DB::all("SELECT c.*, b.name AS bailleur, (SELECT COALESCE(SUM(amount), 0) FROM ong_depenses d WHERE d.convention_id = c.id AND d.status = 'payee') AS spent
                FROM ong_conventions c JOIN ong_bailleurs b ON b.id = c.bailleur_id WHERE c.projet_id = ? ORDER BY c.start_date", [$id]),
            'indicateurs' => DB::all('SELECT * FROM ong_indicateurs WHERE projet_id = ? ORDER BY id', [$id]),
            'missions' => DB::all('SELECT m.*, s.full_name AS chef FROM ong_missions m JOIN ong_personnel s ON s.id = m.chef_id WHERE m.projet_id = ? ORDER BY m.start_date DESC LIMIT 20', [$id]),
            'staff' => DB::all('SELECT * FROM ong_personnel WHERE projet_id = ? AND active = 1 ORDER BY full_name', [$id]),
            'distributed' => DB::all("SELECT a.name, a.unit, SUM(m.quantity) AS qty, SUM(COALESCE(m.beneficiaries, 0)) AS benef FROM ong_stock_mouvements m JOIN ong_articles a ON a.id = m.article_id
                WHERE m.projet_id = ? AND m.type = 'distribution' GROUP BY a.id, a.name, a.unit ORDER BY a.name", [$id])]);
    }

    public function indicateur(int $id): void
    {
        $this->attempt(fn () => S::saveIndicateur($id, $_POST, Request::int('indicateur_id') ?: null), '/ong/projets/' . $id);
        flash('success', 'Indicateur enregistré.');
        redirect('/ong/projets/' . $id);
    }

    // ------------------------------------------------------------------ conventions

    public function conventions(): void
    {
        $rows = DB::all("SELECT c.*, b.name AS bailleur, p.code AS projet_code,
              (SELECT COALESCE(SUM(budget), 0) FROM ong_lignes l WHERE l.convention_id = c.id) AS budgeted,
              (SELECT COALESCE(SUM(amount), 0) FROM ong_mouvements m WHERE m.convention_id = c.id AND m.type = 'fonds_bailleur' AND m.cancelled_at IS NULL) AS received,
              (SELECT COALESCE(SUM(amount), 0) FROM ong_depenses d WHERE d.convention_id = c.id AND d.status = 'payee') AS spent
            FROM ong_conventions c JOIN ong_bailleurs b ON b.id = c.bailleur_id JOIN ong_projets p ON p.id = c.projet_id ORDER BY FIELD(c.status, 'active', 'negociation', 'cloturee'), c.end_date");
        $edit = Request::int('edit');
        view('ong::conventions', ['title' => 'Conventions et budgets', 'rows' => $rows, 'edit' => $edit ? DB::one('SELECT * FROM ong_conventions WHERE id = ?', [$edit]) : null] + $this->lists());
    }

    public function conventionSave(): void
    {
        $id = Request::int('id') ?: null;
        $new = $this->attempt(fn () => S::saveConvention($_POST, $id), '/ong/conventions' . ($id ? '?edit=' . $id : ''));
        flash('success', 'Convention enregistrée.' . ($id ? '' : ' Ajoutez maintenant ses lignes budgétaires.'));
        redirect('/ong/conventions/' . $new);
    }

    public function convention(int $id): void
    {
        $c = $this->attempt(fn () => S::convention($id), '/ong/conventions');
        $b = S::budget($id);
        if (Export::requested()) {
            $rows = array_map(fn ($l) => [$l['code'], $l['label'], t(S::LINE_CATEGORIES[$l['category']]), (float) $l['budget'], (float) $l['paid'], (float) $l['engaged'], (float) $l['available'], $l['rate'] . ' %'], $b['lines']);
            Export::download('budget_' . $c['number'] . '.csv', 'Suivi budgétaire — convention ' . $c['number'], ['Code', 'Ligne budgétaire', 'Catégorie', 'Budget', 'Dépensé', 'Engagé', 'Disponible', 'Consommation'], $rows,
                ['subtitle' => $c['bailleur'] . ' — ' . $c['projet_code'] . ' ' . $c['projet'], 'landscape' => true,
                    'footer' => ['', 'TOTAL', '', $b['total']['budget'], $b['total']['paid'], $b['total']['engaged'], $b['total']['available'], $b['total']['rate'] . ' %', '_bold' => true]]);
        }
        $edit = Request::int('ligne');
        view('ong::convention', ['title' => 'Convention ' . $c['number'], 'c' => $c, 'b' => $b, 'editLigne' => $edit ? DB::one('SELECT * FROM ong_lignes WHERE id = ? AND convention_id = ?', [$edit, $id]) : null,
            'fonds' => DB::all("SELECT m.*, k.name AS compte FROM ong_mouvements m JOIN ong_comptes k ON k.id = m.compte_id WHERE m.convention_id = ? AND m.type = 'fonds_bailleur' ORDER BY m.op_date DESC, m.id DESC", [$id])]);
    }

    public function ligneSave(int $id): void
    {
        $this->attempt(fn () => S::saveLigne($id, $_POST, Request::int('ligne_id') ?: null), '/ong/conventions/' . $id);
        flash('success', 'Ligne budgétaire enregistrée.');
        redirect('/ong/conventions/' . $id);
    }

    /** Rapport financier pour le bailleur (A4 imprimable). */
    public function rapport(int $id): void
    {
        $c = $this->attempt(fn () => S::convention($id), '/ong/conventions');
        [$from, $to] = date_range('first day of january this year');
        $from = max($from, $c['start_date']);
        $lines = DB::all("SELECT l.*, (SELECT COALESCE(SUM(amount), 0) FROM ong_depenses d WHERE d.ligne_id = l.id AND d.status = 'payee' AND d.exp_date < ?) AS before_period,
              (SELECT COALESCE(SUM(amount), 0) FROM ong_depenses d WHERE d.ligne_id = l.id AND d.status = 'payee' AND d.exp_date BETWEEN ? AND ?) AS in_period
            FROM ong_lignes l WHERE l.convention_id = ? ORDER BY l.code", [$from, $from, $to, $id]);
        $recv = DB::all("SELECT * FROM ong_mouvements WHERE convention_id = ? AND type = 'fonds_bailleur' AND cancelled_at IS NULL AND op_date <= ? ORDER BY op_date", [$id, $to]);
        view('ong::rapport', ['title' => 'Rapport financier ' . $c['number'], 'c' => $c, 'lines' => $lines, 'recv' => $recv, 'from' => $from, 'to' => $to,
            'backUrl' => url('/ong/conventions/' . $id), 'bodyClass' => 'a4'], 'layouts/print');
    }

    // ------------------------------------------------------------------ dépenses

    public function depenses(): void
    {
        $status = Request::str('status');
        $conv = Request::int('convention');
        [$from, $to] = date_range('first day of january this year');
        $where = 'WHERE d.exp_date BETWEEN ? AND ?'; $params = [$from, $to];
        if (isset(S::DEP_STATUS[$status])) {
            $where .= ' AND d.status = ?'; $params[] = $status;
        }
        if ($conv) {
            $where .= ' AND d.convention_id = ?'; $params[] = $conv;
        }
        $fromSql = "FROM ong_depenses d JOIN ong_lignes l ON l.id = d.ligne_id JOIN ong_conventions c ON c.id = d.convention_id JOIN ong_projets p ON p.id = d.projet_id
            LEFT JOIN users u ON u.id = d.created_by LEFT JOIN users a ON a.id = d.approved_by $where";
        $select = 'd.*, l.code AS ligne_code, l.label AS ligne, c.number AS conv_number, p.code AS projet_code, u.full_name AS author, a.full_name AS approver';
        if (Export::requested()) {
            $rows = array_map(fn ($r) => [$r['number'], fdate($r['exp_date'], false), $r['projet_code'], $r['conv_number'], $r['ligne_code'] . ' ' . $r['ligne'], $r['payee'], $r['description'], (string) $r['piece'], (float) $r['amount'], t(S::DEP_STATUS[$r['status']])],
                DB::all("SELECT $select $fromSql ORDER BY d.exp_date, d.id", $params));
            Export::download("depenses_ong_{$from}_{$to}.csv", 'Journal des dépenses', ['N°', 'Date', 'Projet', 'Convention', 'Ligne', 'Bénéficiaire', 'Objet', 'Pièce', 'Montant', 'Statut'], $rows,
                ['subtitle' => Export::period($from, $to), 'landscape' => true]);
        }
        $p = paginate($fromSql, $params, $select, "FIELD(d.status, 'soumise', 'approuvee', 'payee', 'rejetee', 'annulee'), d.exp_date DESC, d.id DESC", 30);
        view('ong::depenses', ['title' => 'Dépenses', 'p' => $p, 'status' => $status, 'conv' => $conv, 'from' => $from, 'to' => $to,
            'totals' => DB::all("SELECT d.status, COUNT(*) AS n, SUM(d.amount) AS total $fromSql GROUP BY d.status", $params)] + $this->lists());
    }

    public function depenseSubmit(): void
    {
        $this->attempt(fn () => S::submitDepense($this->withConvention($_POST)), '/ong/depenses');
        flash('success', 'Dépense soumise à l\'approbation.');
        redirect('/ong/depenses');
    }

    public function depensePiece(int $id): void
    {
        $d = DB::one("SELECT d.*, l.code AS ligne_code, l.label AS ligne, c.number AS conv_number, c.title AS conv_title, b.name AS bailleur, p.code AS projet_code, p.name AS projet,
              u.full_name AS author, a.full_name AS approver, k.name AS compte
            FROM ong_depenses d JOIN ong_lignes l ON l.id = d.ligne_id JOIN ong_conventions c ON c.id = d.convention_id JOIN ong_bailleurs b ON b.id = c.bailleur_id JOIN ong_projets p ON p.id = d.projet_id
            LEFT JOIN users u ON u.id = d.created_by LEFT JOIN users a ON a.id = d.approved_by LEFT JOIN ong_comptes k ON k.id = d.compte_id WHERE d.id = ?", [$id]);
        if (!$d) {
            $this->fail('Dépense introuvable.', '/ong/depenses');
        }
        view('ong::piece', ['title' => 'Pièce de dépense ' . $d['number'], 'd' => $d, 'backUrl' => url('/ong/depenses'), 'bodyClass' => 'a4'], 'layouts/print');
    }

    public function depenseApprove(int $id): void
    {
        $this->attempt(fn () => S::approveDepense($id), '/ong/depenses');
        flash('success', 'Dépense approuvée : elle peut être payée.');
        redirect('/ong/depenses');
    }

    public function depenseReject(int $id): void
    {
        $this->attempt(fn () => S::rejectDepense($id, Request::str('reason')), '/ong/depenses');
        flash('success', 'Dépense rejetée.');
        redirect('/ong/depenses');
    }

    public function depensePay(int $id): void
    {
        $this->attempt(fn () => S::payDepense($id, Request::int('compte_id'), Request::str('op_date')), '/ong/depenses');
        flash('success', 'Dépense payée.');
        redirect('/ong/depenses');
    }

    public function depenseCancel(int $id): void
    {
        $this->attempt(fn () => S::cancelDepense($id, Request::str('reason')), '/ong/depenses');
        flash('success', 'Dépense annulée : le paiement a été contre-passé.');
        redirect('/ong/depenses');
    }

    // ------------------------------------------------------------------ trésorerie

    public function tresorerie(): void
    {
        $compte = Request::int('compte');
        [$from, $to] = date_range('first day of this month');
        $where = 'WHERE m.op_date BETWEEN ? AND ?'; $params = [$from, $to];
        if ($compte) {
            $where .= ' AND m.compte_id = ?'; $params[] = $compte;
        }
        $fromSql = "FROM ong_mouvements m JOIN ong_comptes k ON k.id = m.compte_id LEFT JOIN ong_conventions c ON c.id = m.convention_id LEFT JOIN users u ON u.id = m.user_id $where";
        $select = 'm.*, k.name AS compte, c.number AS conv_number, u.full_name AS author';
        if (Export::requested()) {
            $rows = array_map(fn ($r) => [$r['number'], fdate($r['op_date'], false), $r['compte'], t(S::MV_TYPES[$r['type']]), (string) $r['conv_number'], (string) $r['reference'], (string) $r['description'],
                (int) $r['direction'] > 0 ? (float) $r['amount'] : '', (int) $r['direction'] < 0 ? (float) $r['amount'] : '', $r['cancelled_at'] ? 'ANNULÉ' : ''], DB::all("SELECT $select $fromSql ORDER BY m.op_date, m.id", $params));
            Export::download("tresorerie_ong_{$from}_{$to}.csv", 'Journal de trésorerie', ['N°', 'Date', 'Compte', 'Opération', 'Convention', 'Référence', 'Libellé', 'Entrée', 'Sortie', 'État'], $rows,
                ['subtitle' => Export::period($from, $to), 'landscape' => true]);
        }
        $edit = Request::int('edit');
        view('ong::tresorerie', ['title' => 'Trésorerie et fonds', 'p' => paginate($fromSql, $params, $select, 'm.op_date DESC, m.id DESC', 30), 'compte' => $compte, 'from' => $from, 'to' => $to,
            'all' => DB::all('SELECT k.*, c.number AS conv_number FROM ong_comptes k LEFT JOIN ong_conventions c ON c.id = k.convention_id ORDER BY k.active DESC, k.kind, k.name'),
            'edit' => $edit ? DB::one('SELECT * FROM ong_comptes WHERE id = ?', [$edit]) : null] + $this->lists());
    }

    public function recette(): void
    {
        $this->attempt(fn () => S::receive($_POST), '/ong/tresorerie');
        flash('success', 'Fonds enregistrés sur le compte.');
        redirect('/ong/tresorerie');
    }

    public function virement(): void
    {
        $this->attempt(fn () => S::transfer(Request::int('from_id'), Request::int('to_id'), Request::num('amount'), Request::str('op_date', date('Y-m-d')), Request::str('description')), '/ong/tresorerie');
        flash('success', 'Virement enregistré.');
        redirect('/ong/tresorerie');
    }

    public function mouvementCancel(int $id): void
    {
        $this->attempt(fn () => S::cancelMovement($id, Request::str('reason')), '/ong/tresorerie');
        flash('success', 'Mouvement annulé : le solde est rétabli.');
        redirect('/ong/tresorerie');
    }

    // ------------------------------------------------------------------ missions

    public function missions(): void
    {
        $status = Request::str('status');
        $where = isset(S::MISSION_STATUS[$status]) ? 'WHERE m.status = ?' : '';
        $p = paginate("FROM ong_missions m JOIN ong_personnel s ON s.id = m.chef_id JOIN ong_projets p ON p.id = m.projet_id LEFT JOIN ong_vehicules v ON v.id = m.vehicule_id $where", $where ? [$status] : [],
            'm.*, s.full_name AS chef, p.code AS projet_code, v.plate', "FIELD(m.status, 'demandee', 'approuvee', 'en_cours', 'terminee', 'cloturee', 'annulee'), m.start_date DESC", 25);
        view('ong::missions', ['title' => 'Missions terrain', 'p' => $p, 'status' => $status,
            'vehicules' => DB::all("SELECT * FROM ong_vehicules WHERE status IN ('disponible','en_mission') ORDER BY plate")] + $this->lists());
    }

    public function missionRequest(): void
    {
        $membres = [];
        foreach ((array) Request::input('membres', []) as $m) {
            if (is_array($m) && (int) ($m['personnel_id'] ?? 0)) {
                $membres[] = $m;
            }
        }
        $id = $this->attempt(fn () => S::requestMission($this->withConvention($_POST), $membres), '/ong/missions');
        flash('success', 'Demande de mission enregistrée : elle attend l\'approbation.');
        redirect('/ong/missions/' . $id);
    }

    private function missionData(int $id): array
    {
        $m = DB::one("SELECT m.*, s.full_name AS chef, s.position AS chef_position, p.code AS projet_code, p.name AS projet, c.number AS conv_number, l.code AS ligne_code, l.label AS ligne,
              v.plate, v.label AS vehicule, u.full_name AS author, a.full_name AS approver
            FROM ong_missions m JOIN ong_personnel s ON s.id = m.chef_id JOIN ong_projets p ON p.id = m.projet_id JOIN ong_conventions c ON c.id = m.convention_id JOIN ong_lignes l ON l.id = m.ligne_id
            LEFT JOIN ong_vehicules v ON v.id = m.vehicule_id LEFT JOIN users u ON u.id = m.created_by LEFT JOIN users a ON a.id = m.approved_by WHERE m.id = ?", [$id]);
        if (!$m) {
            $this->fail('Mission introuvable.', '/ong/missions');
        }
        return ['m' => $m, 'membres' => DB::all('SELECT mm.*, s.full_name, s.position, s.matricule FROM ong_mission_membres mm JOIN ong_personnel s ON s.id = mm.personnel_id WHERE mm.mission_id = ? ORDER BY s.full_name', [$id])];
    }

    public function mission(int $id): void
    {
        $d = $this->missionData($id);
        view('ong::mission', ['title' => 'Mission ' . $d['m']['number'], 'comptes' => DB::all('SELECT * FROM ong_comptes WHERE active = 1 ORDER BY kind, name'),
            'mouvements' => DB::all('SELECT mv.*, k.name AS compte FROM ong_mouvements mv JOIN ong_comptes k ON k.id = mv.compte_id WHERE mv.mission_id = ? ORDER BY mv.id', [$id]),
            'trajets' => DB::all('SELECT * FROM ong_carnet WHERE mission_id = ? ORDER BY log_date, id', [$id])] + $d);
    }

    public function missionOrdre(int $id): void
    {
        $d = $this->missionData($id);
        view('ong::ordre', ['title' => 'Ordre de mission ' . $d['m']['number'], 'backUrl' => url('/ong/missions/' . $id), 'bodyClass' => 'a4'] + $d, 'layouts/print');
    }

    private function missionAction(int $id, callable $fn, string $ok): void
    {
        $this->attempt($fn, '/ong/missions/' . $id);
        flash('success', $ok);
        redirect('/ong/missions/' . $id);
    }

    public function missionApprove(int $id): void
    {
        $this->missionAction($id, fn () => S::approveMission($id), 'Mission approuvée.');
    }

    public function missionAdvance(int $id): void
    {
        $this->missionAction($id, fn () => S::advanceMission($id, Request::int('compte_id'), Request::num('amount'), Request::str('op_date')), 'Avance versée : elle reste à justifier au retour.');
    }

    public function missionStart(int $id): void
    {
        $this->missionAction($id, fn () => S::startMission($id), 'Départ en mission enregistré.');
    }

    public function missionFinish(int $id): void
    {
        $this->missionAction($id, fn () => S::finishMission($id, Request::str('report')), 'Retour enregistré : il reste à justifier l\'avance.');
    }

    public function missionClose(int $id): void
    {
        $this->missionAction($id, fn () => S::closeMission($id, Request::num('justified'), Request::int('compte_id'), Request::str('piece')), 'Mission clôturée : frais imputés au budget, avance soldée.');
    }

    public function missionCancel(int $id): void
    {
        $this->missionAction($id, fn () => S::cancelMission($id, Request::str('reason')), 'Mission annulée.');
    }

    // ------------------------------------------------------------------ logistique, personnel

    public function logistique(): void
    {
        $onglet = in_array(Request::query('onglet'), ['equipements', 'stocks'], true) ? (string) Request::query('onglet') : 'vehicules';
        $edit = Request::int('edit');
        $table = ['vehicules' => 'ong_vehicules', 'equipements' => 'ong_equipements', 'stocks' => 'ong_articles'][$onglet];
        if ($onglet === 'equipements' && Export::requested()) {
            $rows = array_map(fn ($r) => [$r['code'], $r['label'], (string) $r['category'], $r['acquired_on'] ? fdate($r['acquired_on'], false) : '', (float) $r['value'], (string) $r['conv_number'], (string) $r['location'], (string) $r['holder'], t(S::EQUIP_STATES[$r['state']])],
                DB::all('SELECT e.*, c.number AS conv_number FROM ong_equipements e LEFT JOIN ong_conventions c ON c.id = e.convention_id ORDER BY e.code'));
            Export::download('inventaire_equipements.csv', 'Inventaire des équipements', ['Code', 'Désignation', 'Catégorie', 'Acquis le', 'Valeur', 'Financement', 'Lieu', 'Détenteur', 'État'], $rows, ['landscape' => true]);
        }
        view('ong::logistique', ['title' => 'Logistique', 'onglet' => $onglet, 'edit' => $edit ? DB::one("SELECT * FROM `$table` WHERE id = ?", [$edit]) : null,
            'vehicules' => DB::all('SELECT v.*, p.code AS projet_code FROM ong_vehicules v LEFT JOIN ong_projets p ON p.id = v.projet_id ORDER BY v.plate'),
            'carnet' => DB::all('SELECT c.*, v.plate, m.number AS mission FROM ong_carnet c JOIN ong_vehicules v ON v.id = c.vehicule_id LEFT JOIN ong_missions m ON m.id = c.mission_id ORDER BY c.log_date DESC, c.id DESC LIMIT 40'),
            'missionsEnCours' => DB::all("SELECT id, number, title, vehicule_id FROM ong_missions WHERE status = 'en_cours' ORDER BY number"),
            'equipements' => DB::all('SELECT e.*, c.number AS conv_number FROM ong_equipements e LEFT JOIN ong_conventions c ON c.id = e.convention_id ORDER BY e.code'),
            'articles' => DB::all('SELECT * FROM ong_articles ORDER BY name'),
            'stockMv' => DB::all('SELECT m.*, a.name, a.unit, p.code AS projet_code FROM ong_stock_mouvements m JOIN ong_articles a ON a.id = m.article_id LEFT JOIN ong_projets p ON p.id = m.projet_id ORDER BY m.id DESC LIMIT 40'),
            'allConvs' => DB::all('SELECT id, number FROM ong_conventions ORDER BY number')] + $this->lists());
    }

    public function carnet(): void
    {
        $this->attempt(fn () => S::logTrip($_POST), '/ong/logistique');
        flash('success', 'Trajet inscrit au carnet de bord.');
        redirect('/ong/logistique');
    }

    public function stock(): void
    {
        $this->attempt(fn () => S::stockMove($_POST), '/ong/logistique?onglet=stocks');
        flash('success', 'Mouvement de stock enregistré.');
        redirect('/ong/logistique?onglet=stocks');
    }

    public function personnel(): void
    {
        $rows = DB::all('SELECT s.*, p.code AS projet_code FROM ong_personnel s LEFT JOIN ong_projets p ON p.id = s.projet_id ORDER BY s.active DESC, s.full_name');
        if (Export::requested()) {
            Export::download('personnel_ong.csv', 'Personnel', ['Matricule', 'Nom', 'Poste', 'Statut', 'Projet', 'Téléphone', 'Début', 'Fin', 'Coût mensuel'],
                array_map(fn ($r) => [$r['matricule'], $r['full_name'], (string) $r['position'], t(S::PERSONNEL_KINDS[$r['kind']]), (string) $r['projet_code'], (string) $r['phone'],
                    $r['start_date'] ? fdate($r['start_date'], false) : '', $r['end_date'] ? fdate($r['end_date'], false) : '', (float) $r['monthly_cost']], $rows), ['landscape' => true]);
        }
        $edit = Request::int('edit');
        view('ong::personnel', ['title' => 'Personnel de l\'organisation', 'rows' => $rows, 'edit' => $edit ? DB::one('SELECT * FROM ong_personnel WHERE id = ?', [$edit]) : null] + $this->lists());
    }
}
