<?php
declare(strict_types=1);

namespace Addons\Grh;

use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\DB;
use App\Core\Export;
use App\Core\Request;
use Addons\Grh\GrhService as S;

final class GrhController extends Controller
{
    private function lists(): array
    {
        return [
            'deps' => DB::all('SELECT id, name FROM grh_departements ORDER BY name'),
            'postes' => DB::all('SELECT id, title, grade, base_salary FROM grh_postes ORDER BY title'),
            'actifs' => DB::all("SELECT id, matricule, first_name, last_name FROM grh_employes WHERE status <> 'sorti' ORDER BY last_name, first_name"),
        ];
    }

    public function dashboard(): void
    {
        view('grh::dashboard', ['title' => 'Tableau de bord RH', 'd' => S::dashboard()]);
    }

    // ------------------------------------------------------------------ employés

    public function employes(): void
    {
        $q = Request::str('q');
        $dep = Request::int('departement');
        $status = Request::str('status', 'actif');
        $where = 'WHERE 1'; $params = [];
        if (isset(S::EMP_STATUS[$status])) {
            $where .= ' AND e.status = ?'; $params[] = $status;
        }
        if ($dep) {
            $where .= ' AND e.departement_id = ?'; $params[] = $dep;
        }
        if ($q !== '') {
            $where .= ' AND (e.last_name LIKE ? OR e.first_name LIKE ? OR e.matricule LIKE ? OR e.phone LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        $from = "FROM grh_employes e LEFT JOIN grh_departements d ON d.id = e.departement_id LEFT JOIN grh_postes p ON p.id = e.poste_id
            LEFT JOIN grh_contrats c ON c.employe_id = e.id AND c.status = 'en_cours' $where";
        $select = 'e.*, d.name AS departement, p.title AS poste, c.kind AS contrat, c.end_date AS contrat_fin, c.base_salary, c.allowances';
        if (Export::requested()) {
            $paie = can('grh.paie');
            $rows = array_map(fn ($r) => array_merge([$r['matricule'], $r['last_name'], $r['first_name'], $r['sex'], (string) $r['departement'], (string) $r['poste'], (string) $r['phone'], fdate($r['hire_date'], false),
                $r['contrat'] ? S::CONTRACT_KINDS[$r['contrat']] : '', $r['contrat_fin'] ? fdate($r['contrat_fin'], false) : '', t(S::EMP_STATUS[$r['status']])], $paie ? [(float) $r['base_salary'] + (float) $r['allowances']] : []),
                DB::all("SELECT $select $from ORDER BY e.last_name, e.first_name", $params));
            Audit::log('grh.export.employes', 'grh_employes', null, ['rows' => count($rows)]);
            Export::download('employes.csv', 'Liste du personnel', array_merge(['Matricule', 'Nom', 'Prénom', 'Sexe', 'Département', 'Poste', 'Téléphone', 'Embauche', 'Contrat', 'Fin de contrat', 'État'], $paie ? ['Salaire mensuel'] : []), $rows, ['landscape' => true]);
        }
        view('grh::employes', ['title' => 'Employés', 'p' => paginate($from, $params, $select, 'e.last_name, e.first_name', 30), 'q' => $q, 'dep' => $dep, 'status' => $status] + $this->lists());
    }

    public function form(): void
    {
        $id = Request::int('id');
        $e = $id ? $this->findOr404('grh_employes', $id) : [];
        view('grh::form', ['title' => $id ? 'Modifier la fiche' : 'Nouvel employé', 'e' => $e,
            'nextMat' => $id ? '' : 'EMP-' . str_pad((string) ((int) DB::value('SELECT COALESCE(MAX(id), 0) FROM grh_employes') + 1), 4, '0', STR_PAD_LEFT)] + $this->lists());
    }

    public function save(): void
    {
        $id = Request::int('id') ?: null;
        $new = $this->attempt(fn () => S::saveEmploye($_POST, $id), '/grh/employes/fiche' . ($id ? '?id=' . $id : ''));
        flash('success', $id ? 'Fiche enregistrée.' : 'Employé créé. Ajoutez maintenant son contrat.');
        redirect('/grh/employes/' . $new);
    }

    public function show(int $id): void
    {
        $e = $this->attempt(fn () => S::employe($id), '/grh/employes');
        Audit::log('grh.employe.consulte', 'grh_employes', $id);
        view('grh::employe', ['title' => $e['first_name'] . ' ' . $e['last_name'], 'e' => $e, 'solde' => S::leaveBalance($id),
            'contrats' => DB::all('SELECT * FROM grh_contrats WHERE employe_id = ? ORDER BY start_date DESC, id DESC', [$id]),
            'conges' => DB::all('SELECT * FROM grh_conges WHERE employe_id = ? ORDER BY start_date DESC LIMIT 30', [$id]),
            'dossier' => DB::all('SELECT g.*, u.full_name AS author FROM grh_dossier g LEFT JOIN users u ON u.id = g.created_by WHERE g.employe_id = ? ORDER BY g.event_date DESC, g.id DESC', [$id]),
            'avances' => can('grh.paie') ? DB::all('SELECT * FROM grh_avances WHERE employe_id = ? ORDER BY id DESC', [$id]) : [],
            'bulletins' => can('grh.paie') ? DB::all('SELECT b.id, b.net, b.gross, p.period, p.status FROM grh_bulletins b JOIN grh_paies p ON p.id = b.paie_id WHERE b.employe_id = ? ORDER BY p.period DESC LIMIT 12', [$id]) : [],
            'presences' => DB::all('SELECT status, COUNT(*) AS n FROM grh_presences WHERE employe_id = ? AND day >= ? GROUP BY status', [$id, date('Y-m-01')]),
            'equipe' => DB::all("SELECT id, first_name, last_name FROM grh_employes WHERE manager_id = ? AND status <> 'sorti' ORDER BY last_name", [$id])]);
    }

    public function attestation(int $id): void
    {
        $e = $this->attempt(fn () => S::employe($id), '/grh/employes');
        view('grh::attestation', ['title' => 'Attestation de travail', 'e' => $e, 'contrat' => S::currentContract($id), 'backUrl' => url('/grh/employes/' . $id), 'bodyClass' => 'a4'], 'layouts/print');
    }

    private function back(int $id, callable $fn, string $ok): void
    {
        $this->attempt($fn, '/grh/employes/' . $id);
        flash('success', $ok);
        redirect('/grh/employes/' . $id);
    }

    public function contrat(int $id): void
    {
        $this->back($id, fn () => S::saveContrat($id, $_POST), 'Contrat enregistré. Le contrat précédent, s\'il existait, est terminé la veille.');
    }

    public function dossier(int $id): void
    {
        $this->back($id, fn () => S::addDossier($id, $_POST), 'Élément ajouté au dossier.');
    }

    public function etat(int $id): void
    {
        $this->back($id, fn () => S::setStatus($id, Request::str('status')), 'État de l\'employé mis à jour.');
    }

    public function sortie(int $id): void
    {
        $this->attempt(fn () => S::exitEmploye($id, Request::str('exit_date'), Request::str('exit_reason')), '/grh/employes/' . $id);
        $du = (float) DB::value("SELECT COALESCE(SUM(remaining), 0) FROM grh_avances WHERE employe_id = ? AND status = 'en_cours'", [$id]);
        flash($du > 0 ? 'warning' : 'success', 'Sortie enregistrée : la fiche et l\'historique sont conservés.' . ($du > 0 ? ' Attention : il reste ' . money($du) . ' d\'avances sur salaire à récupérer sur le solde de tout compte.' : ''));
        redirect('/grh/employes/' . $id);
    }

    // ------------------------------------------------------------------ structure

    public function structure(): void
    {
        $ed = Request::int('departement'); $ep = Request::int('poste');
        view('grh::structure', ['title' => 'Structure et organigramme', 'tree' => S::tree(), 'editDep' => $ed ? DB::one('SELECT * FROM grh_departements WHERE id = ?', [$ed]) : null,
            'editPoste' => $ep ? DB::one('SELECT * FROM grh_postes WHERE id = ?', [$ep]) : null,
            'allPostes' => DB::all("SELECT p.*, d.name AS departement, (SELECT COUNT(*) FROM grh_employes e WHERE e.poste_id = p.id AND e.status = 'actif') AS n FROM grh_postes p LEFT JOIN grh_departements d ON d.id = p.departement_id ORDER BY p.title")] + $this->lists());
    }

    public function departement(): void
    {
        $this->attempt(fn () => S::saveDepartement($_POST, Request::int('id') ?: null), '/grh/structure');
        flash('success', 'Département enregistré.');
        redirect('/grh/structure');
    }

    public function poste(): void
    {
        $this->attempt(fn () => S::savePoste($_POST, Request::int('id') ?: null), '/grh/structure');
        flash('success', 'Poste enregistré.');
        redirect('/grh/structure');
    }

    // ------------------------------------------------------------------ congés

    public function conges(): void
    {
        $status = Request::str('status');
        $where = isset(S::LEAVE_STATUS[$status]) ? 'WHERE g.status = ?' : '';
        $p = paginate("FROM grh_conges g JOIN grh_employes e ON e.id = g.employe_id LEFT JOIN users u ON u.id = g.decided_by $where", $where ? [$status] : [],
            'g.*, e.matricule, e.first_name, e.last_name, u.full_name AS decider', "FIELD(g.status, 'demande', 'approuve', 'refuse', 'annule'), g.start_date DESC", 30);
        foreach ($p['rows'] as &$r) {
            $r['solde'] = $r['status'] === 'demande' && $r['kind'] === 'annuel' ? S::leaveBalance((int) $r['employe_id'], (string) $r['start_date'])['balance'] : null;
        }
        unset($r);
        view('grh::conges', ['title' => 'Congés', 'p' => $p, 'status' => $status] + $this->lists());
    }

    public function congeRequest(): void
    {
        $this->attempt(fn () => S::requestLeave($_POST), '/grh/conges');
        flash('success', 'Demande de congé enregistrée.');
        redirect('/grh/conges');
    }

    public function congeDecide(int $id): void
    {
        $ok = Request::str('decision') === 'approuver';
        $this->attempt(fn () => S::decideLeave($id, $ok, Request::str('note')), '/grh/conges');
        flash('success', $ok ? 'Congé approuvé.' : 'Congé refusé.');
        redirect('/grh/conges');
    }

    public function congeCancel(int $id): void
    {
        $this->attempt(fn () => S::cancelLeave($id, Request::str('note')), '/grh/conges');
        flash('success', 'Congé annulé : les jours reviennent au solde.');
        redirect('/grh/conges');
    }

    // ------------------------------------------------------------------ présences

    public function presences(): void
    {
        $day = Request::str('jour', date('Y-m-d'));
        if (!\App\Core\Validator::isDate($day) || $day > date('Y-m-d')) {
            $day = date('Y-m-d');
        }
        $month = substr($day, 0, 7);
        $emps = DB::all("SELECT e.id, e.matricule, e.first_name, e.last_name, d.name AS departement, pr.status AS pointage,
              (SELECT g.kind FROM grh_conges g WHERE g.employe_id = e.id AND g.status = 'approuve' AND g.start_date <= ? AND g.end_date >= ? LIMIT 1) AS conge
            FROM grh_employes e LEFT JOIN grh_departements d ON d.id = e.departement_id LEFT JOIN grh_presences pr ON pr.employe_id = e.id AND pr.day = ?
            WHERE e.hire_date <= ? AND (e.exit_date IS NULL OR e.exit_date >= ?) AND e.status <> 'sorti' ORDER BY d.name, e.last_name, e.first_name", [$day, $day, $day, $day, $day]);
        $sum = [];
        foreach (DB::all('SELECT employe_id, status, COUNT(*) AS n FROM grh_presences WHERE day BETWEEN ? AND ? GROUP BY employe_id, status', [$month . '-01', date('Y-m-t', strtotime($month . '-01'))]) as $r) {
            $sum[(int) $r['employe_id']][$r['status']] = (int) $r['n'];
        }
        if (Export::requested()) {
            Export::download("presences_$month.csv", 'Présences du mois ' . $month, ['Matricule', 'Nom', 'Département', 'Présent', 'Retard', 'Absent', 'Mission', 'Repos'],
                array_map(fn ($e) => [$e['matricule'], $e['last_name'] . ' ' . $e['first_name'], (string) $e['departement'], $sum[$e['id']]['present'] ?? 0, $sum[$e['id']]['retard'] ?? 0, $sum[$e['id']]['absent'] ?? 0, $sum[$e['id']]['mission'] ?? 0, $sum[$e['id']]['repos'] ?? 0], $emps));
        }
        view('grh::presences', ['title' => 'Présences', 'day' => $day, 'month' => $month, 'emps' => $emps, 'sum' => $sum,
            'locked' => (bool) DB::value("SELECT id FROM grh_paies WHERE period = ? AND status <> 'brouillon'", [$month])]);
    }

    public function presencesSave(): void
    {
        $day = Request::str('day');
        $n = $this->attempt(fn () => S::markDay($day, (array) Request::input('p', [])), '/grh/presences?jour=' . urlencode($day));
        flash('success', "Pointage enregistré pour $n employé(s).");
        redirect('/grh/presences', ['jour' => $day]);
    }

    // ------------------------------------------------------------------ paie

    public function paies(): void
    {
        view('grh::paies', ['title' => 'Paie et bulletins', 'rows' => DB::all('SELECT p.*, u.full_name AS validator, (SELECT COUNT(*) FROM grh_bulletins b WHERE b.paie_id = p.id) AS n,
            (SELECT COALESCE(SUM(gross), 0) FROM grh_bulletins b WHERE b.paie_id = p.id) AS gross, (SELECT COALESCE(SUM(net), 0) FROM grh_bulletins b WHERE b.paie_id = p.id) AS net,
            (SELECT COALESCE(SUM(employer_charges), 0) FROM grh_bulletins b WHERE b.paie_id = p.id) AS charges FROM grh_paies p LEFT JOIN users u ON u.id = p.validated_by ORDER BY p.period DESC')]);
    }

    public function paieGenerate(): void
    {
        $id = $this->attempt(fn () => S::generatePaie(Request::str('period')), '/grh/paie');
        flash('success', 'Paie préparée : vérifiez les bulletins, ajoutez primes et retenues, puis validez.');
        redirect('/grh/paie/' . $id);
    }

    public function paie(int $id): void
    {
        $p = $this->findOr404('grh_paies', $id);
        $rows = DB::all('SELECT b.*, e.matricule, e.first_name, e.last_name, e.bank_account, d.name AS departement FROM grh_bulletins b JOIN grh_employes e ON e.id = b.employe_id LEFT JOIN grh_departements d ON d.id = e.departement_id
            WHERE b.paie_id = ? ORDER BY e.last_name, e.first_name', [$id]);
        $tot = S::paieTotals($id);
        if (Export::requested()) {
            Audit::log('grh.export.paie', 'grh_paies', $id, ['period' => $p['period']]);
            Export::download('paie_' . $p['period'] . '.csv', 'État de paie ' . $p['period'], ['Matricule', 'Nom', 'Base', 'Primes', 'Prime except.', 'Heures sup.', 'Absences', 'Brut', 'CNSS', 'Impôt', 'Avance', 'Autres ret.', 'Net à payer', 'Compte'],
                array_map(fn ($b) => [$b['matricule'], $b['last_name'] . ' ' . $b['first_name'], (float) $b['base'], (float) $b['allowances'], (float) $b['bonus'], (float) $b['overtime'], -(float) $b['absence_deduction'], (float) $b['gross'],
                    (float) $b['cnss'], (float) $b['tax'], (float) $b['advance'], (float) $b['other_deduction'], (float) $b['net'], (string) $b['bank_account']], $rows),
                ['landscape' => true, 'footer' => ['', 'TOTAL', '', '', '', '', '', (float) $tot['gross'], (float) $tot['cnss'], (float) $tot['tax'], (float) $tot['advance'], (float) $tot['other_deduction'], (float) $tot['net'], '', '_bold' => true]]);
        }
        view('grh::paie', ['title' => 'Paie ' . $p['period'], 'p' => $p, 'rows' => $rows, 'tot' => $tot, 'edit' => Request::int('bulletin')]);
    }

    public function paieValidate(int $id): void
    {
        $this->attempt(fn () => S::validatePaie($id), '/grh/paie/' . $id);
        flash('success', 'Paie validée : bulletins verrouillés, avances retenues.');
        redirect('/grh/paie/' . $id);
    }

    public function paiePay(int $id): void
    {
        $this->attempt(fn () => S::payPaie($id), '/grh/paie/' . $id);
        flash('success', 'Paie marquée comme payée.');
        redirect('/grh/paie/' . $id);
    }

    public function bulletinUpdate(int $id): void
    {
        $pid = (int) DB::value('SELECT paie_id FROM grh_bulletins WHERE id = ?', [$id]);
        $this->attempt(fn () => S::updateBulletin($id, $_POST), '/grh/paie/' . $pid);
        flash('success', 'Bulletin recalculé.');
        redirect('/grh/paie/' . $pid);
    }

    public function bulletin(int $id): void
    {
        $b = DB::one('SELECT b.*, p.period, p.status, e.matricule, e.civility, e.first_name, e.last_name, e.cnss_number, e.hire_date, e.bank_account, d.name AS departement, po.title AS poste, po.grade
            FROM grh_bulletins b JOIN grh_paies p ON p.id = b.paie_id JOIN grh_employes e ON e.id = b.employe_id LEFT JOIN grh_departements d ON d.id = e.departement_id LEFT JOIN grh_postes po ON po.id = e.poste_id WHERE b.id = ?', [$id]);
        if (!$b) {
            $this->fail('Bulletin introuvable.', '/grh/paie');
        }
        view('grh::bulletin', ['title' => 'Bulletin de paie ' . $b['period'], 'b' => $b, 'backUrl' => url('/grh/paie/' . $b['paie_id']), 'bodyClass' => 'a4'], 'layouts/print');
    }

    // ------------------------------------------------------------------ avances

    public function avances(): void
    {
        view('grh::avances', ['title' => 'Avances sur salaire', 'rows' => DB::all("SELECT a.*, e.matricule, e.first_name, e.last_name FROM grh_avances a JOIN grh_employes e ON e.id = a.employe_id ORDER BY FIELD(a.status, 'en_cours', 'soldee', 'annulee'), a.id DESC LIMIT 200")] + $this->lists());
    }

    public function avanceGrant(): void
    {
        $this->attempt(fn () => S::grantAvance($_POST), '/grh/avances');
        flash('success', 'Avance accordée : elle sera retenue sur les prochaines paies.');
        redirect('/grh/avances');
    }

    public function avanceCancel(int $id): void
    {
        $this->attempt(fn () => S::cancelAvance($id), '/grh/avances');
        flash('success', 'Avance annulée.');
        redirect('/grh/avances');
    }
}
