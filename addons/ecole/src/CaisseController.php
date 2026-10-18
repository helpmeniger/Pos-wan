<?php
declare(strict_types=1);

namespace Addons\Ecole;

use App\Controllers\Controller;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Export;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\CashService;

/** Caisse de l'établissement (inscriptions, frais, reçus), comptabilité, impayés, salaires. */
final class CaisseController extends Controller
{
    public function caisse(): void
    {
        $u = $this->user();
        view('ecole::caisse', ['title' => 'Caisse de l\'établissement', 'sessionOuverte' => (bool) CashService::currentSession($u),
            'sessionRequise' => setting('require_cash_session', '1') === '1', 'annee' => Ecole::anneeCourante(), 'classes' => Ecole::classes(),
            'modes' => payment_methods(), 'operateurs' => mobile_money_providers(), 'eleveId' => (int) Request::query('eleve', 0),
            'visage' => Biometrie::active(), 'scripts' => ['addons/ecole/ecole-visage.js', 'addons/ecole/ecole-form.js', 'addons/ecole/ecole-caisse.js']]);
    }

    /** Dossier d'un élève pour la caisse : identité, inscription de l'année, échéances, reçus. */
    public function dossier(int $id): void
    {
        $e = Personnes::eleve($id);
        $an = Ecole::anneeCourante();
        $ins = $an ? DB::one('SELECT i.*, c.nom AS classe FROM ecole_inscriptions i JOIN ecole_classes c ON c.id = i.classe_id WHERE i.eleve_id = ? AND i.annee_id = ?', [$id, $an['id']]) : null;
        json_response(['ok' => true, 'eleve' => ['id' => (int) $e['id'], 'matricule' => $e['matricule'], 'nom' => $e['nom'], 'prenom' => $e['prenom'], 'sexe' => $e['sexe'],
            'naissance' => $e['date_naissance'] ? fdate($e['date_naissance']) : null, 'tuteur' => trim(($e['tuteur_nom'] ?? '') . ' ' . ($e['tuteur_tel'] ?? '')), 'statut' => $e['statut'],
            'photo' => $e['photo'] ? url('/ecole/photo/eleve/' . $id) : null, 'infos_medicales' => $e['infos_medicales']],
            'inscription' => $ins ? ['id' => (int) $ins['id'], 'classe' => $ins['classe'], 'statut' => $ins['statut'], 'remise' => (float) $ins['remise']] : null,
            'echeances' => $ins ? array_map(fn($x) => ['id' => (int) $x['id'], 'libelle' => $x['libelle'], 'montant' => (float) $x['montant'], 'paye' => (float) $x['paye'], 'reste' => (float) $x['reste'],
                'date' => $x['date_limite'] ? fdate($x['date_limite']) : '', 'echue' => $x['date_limite'] && $x['date_limite'] <= date('Y-m-d') && (float) $x['reste'] > 0], Finance::echeances((int) $ins['id'])) : [],
            'solde' => $ins ? Finance::solde((int) $ins['id']) : null,
            'recus' => array_map(fn($p) => ['id' => (int) $p['id'], 'numero' => $p['numero'], 'montant' => (float) $p['montant'], 'date' => fdate($p['created_at']), 'annule' => (bool) $p['annule']],
                DB::all('SELECT * FROM ecole_paiements WHERE eleve_id = ? ORDER BY id DESC LIMIT 10', [$id]))]);
    }

    public function payer(): void
    {
        $this->jsonAttempt(fn() => Finance::encaisser((int) Request::input('inscription_id', 0), [
            'uuid' => Request::input('uuid'), 'montant' => Request::input('montant'), 'mode' => Request::input('mode'), 'reference' => Request::input('reference'),
            'echeances' => (array) Request::input('echeances', [])]));
    }

    /** Inscription depuis la caisse : élève existant ou nouveau, classe, frais, paiement immédiat facultatif. */
    public function inscription(): void
    {
        $r = $this->attempt(fn() => DB::transaction(function () {
            $eid = (int) Request::input('eleve_id', 0);
            if (!$eid) {
                $eid = Personnes::sauverEleve($_POST, null, Request::input('photo_data') ?: null, $_FILES['photo'] ?? null);
                $v = json_decode((string) Request::input('visages', ''), true);
                if (is_array($v) && $v && can('ecole.biometrie') && Biometrie::active()) {
                    Biometrie::enregistrer('eleve', $eid, $v, 'inscription');
                }
            }
            $ins = Finance::inscrire($eid, (int) Request::input('classe_id', 0), $_POST);
            $recu = null;
            if ((float) str_replace([' ', ','], ['', '.'], (string) Request::input('premier_paiement', '0')) > 0) {
                $recu = Finance::encaisser($ins, ['uuid' => Request::input('uuid'), 'montant' => Request::input('premier_paiement'), 'mode' => Request::input('mode', 'cash'), 'reference' => Request::input('reference')]);
            }
            return ['eleve' => $eid, 'recu' => $recu['id'] ?? null];
        }), '/ecole/caisse');
        flash('success', t('Inscription enregistrée.'));
        redirect('/ecole/caisse', array_filter(['eleve' => $r['eleve'], 'recu' => $r['recu']]));
    }

    public function recu(int $id): void
    {
        $p = DB::one('SELECT p.*, e.matricule, e.nom, e.prenom, e.tuteur_nom, c.nom AS classe, a.libelle AS annee, u.full_name AS caissier FROM ecole_paiements p
                      JOIN ecole_eleves e ON e.id = p.eleve_id JOIN ecole_inscriptions i ON i.id = p.inscription_id JOIN ecole_classes c ON c.id = i.classe_id
                      JOIN ecole_annees a ON a.id = i.annee_id LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ?', [$id]) ?? throw new HttpException(404);
        $lignes = DB::all('SELECT l.montant, e.libelle FROM ecole_paiement_lignes l JOIN ecole_echeances e ON e.id = l.echeance_id WHERE l.paiement_id = ?', [$id]);
        $format = Request::query('format') === 'a4' ? 'a4' : 't80';
        view('ecole::recu', ['title' => 'Reçu ' . $p['numero'], 'p' => $p, 'lignes' => $lignes, 'solde' => Finance::solde((int) $p['inscription_id']), 'format' => $format,
            'bodyClass' => $format, 'backUrl' => url('/ecole/caisse', ['eleve' => $p['eleve_id']]), 'autoPrint' => Request::query('imprimer') === '1',
            'toolbarLinks' => [[url('/ecole/recus/' . $id, ['format' => $format === 'a4' ? 't80' : 'a4']), $format === 'a4' ? 'Ticket 80 mm' : 'Format A4', 'file-earmark']]], 'layouts/print');
    }

    public function annulerRecu(int $id): void
    {
        $eid = (int) DB::value('SELECT eleve_id FROM ecole_paiements WHERE id = ?', [$id]);
        $this->attempt(fn() => Finance::annuler($id, (Request::str('reason') ?: Request::str('motif'))), '/ecole/eleves/' . $eid);
        flash('success', t('Reçu annulé.'));
        redirect('/ecole/eleves/' . $eid);
    }

    // ------------------------------------------------------------------ comptabilité
    public function comptabilite(): void
    {
        $du = Ecole::date((string) Request::query('du')) ? (string) Request::query('du') : date('Y-m-01');
        $au = Ecole::date((string) Request::query('au')) ? (string) Request::query('au') : date('Y-m-d');
        $r = Finance::rapport($du, $au);
        if (Export::requested()) {
            $rows = DB::all('SELECT p.numero, p.created_at, e.matricule, e.nom, e.prenom, c.nom AS classe, p.mode, p.montant FROM ecole_paiements p JOIN ecole_eleves e ON e.id = p.eleve_id
                             JOIN ecole_inscriptions i ON i.id = p.inscription_id JOIN ecole_classes c ON c.id = i.classe_id WHERE p.annule = 0 AND p.created_at BETWEEN ? AND ? ORDER BY p.created_at', [$du . ' 00:00:00', $au . ' 23:59:59']);
            $modes = payment_methods();
            Export::download("encaissements_{$du}_{$au}.csv", 'Encaissements de l\'établissement', ['Reçu', 'Date', 'Matricule', 'Nom', 'Classe', 'Mode', 'Montant'],
                array_map(fn($x) => [$x['numero'], fdate($x['created_at']), $x['matricule'], $x['prenom'] . ' ' . $x['nom'], $x['classe'], $modes[$x['mode']] ?? $x['mode'], $x['montant']], $rows),
                ['subtitle' => "Du $du au $au", 'footer' => ['', '', '', '', '', 'Total', $r['recettes'], '_bold' => 1]]);
        }
        view('ecole::comptabilite', ['title' => 'Comptabilité de l\'établissement', 'r' => $r, 'du' => $du, 'au' => $au,
            'recus' => DB::all('SELECT p.*, e.nom, e.prenom FROM ecole_paiements p JOIN ecole_eleves e ON e.id = p.eleve_id WHERE p.created_at BETWEEN ? AND ? ORDER BY p.id DESC LIMIT 100', [$du . ' 00:00:00', $au . ' 23:59:59'])]);
    }

    public function impayes(): void
    {
        $classe = (int) Request::query('classe', 0);
        $rows = Finance::impayes($classe ?: null);
        if (Export::requested()) {
            Export::download('impayes_' . date('Ymd') . '.csv', 'Impayés échus', ['Matricule', 'Nom', 'Classe', 'Tuteur', 'Téléphone', 'Échéance la plus ancienne', 'Montant échu'],
                array_map(fn($r) => [$r['matricule'], $r['prenom'] . ' ' . $r['nom'], $r['classe'], $r['tuteur_nom'], $r['tuteur_tel'], fdate($r['plus_ancienne']), $r['echu']], $rows),
                ['footer' => ['', '', '', '', '', 'Total', array_sum(array_column($rows, 'echu')), '_bold' => 1]]);
        }
        view('ecole::impayes', ['title' => 'Impayés', 'rows' => $rows, 'classe' => $classe, 'classes' => Ecole::classes()]);
    }

    // ------------------------------------------------------------------ salaires
    public function salaires(): void
    {
        $mois = preg_match('/^\d{4}-\d{2}$/', (string) Request::query('mois')) ? (string) Request::query('mois') : date('Y-m');
        $pers = DB::all("SELECT p.*, (SELECT COALESCE(SUM(s.montant),0) FROM ecole_salaires s WHERE s.personnel_id = p.id AND s.periode = ? AND s.annule = 0) AS verse
                         FROM ecole_personnel p WHERE p.statut = 'actif' ORDER BY p.nom, p.prenom", [$mois]);
        view('ecole::salaires', ['title' => 'Salaires du personnel', 'mois' => $mois, 'personnel' => $pers, 'modes' => payment_methods(),
            'versements' => DB::all('SELECT s.*, p.nom, p.prenom, p.matricule FROM ecole_salaires s JOIN ecole_personnel p ON p.id = s.personnel_id WHERE s.periode = ? ORDER BY s.id DESC', [$mois])]);
    }

    public function payerSalaire(): void
    {
        $mois = (string) Request::input('periode', date('Y-m'));
        $r = $this->attempt(fn() => Finance::payerSalaire((int) Request::input('personnel_id', 0), $mois, $_POST), '/ecole/salaires?mois=' . $mois);
        flash('success', t('Salaire enregistré') . ' : ' . $r['numero']);
        redirect('/ecole/salaires', ['mois' => $mois]);
    }

    public function annulerSalaire(int $id): void
    {
        $mois = (string) DB::value('SELECT periode FROM ecole_salaires WHERE id = ?', [$id]);
        $this->attempt(fn() => Finance::annulerSalaire($id, (Request::str('reason') ?: Request::str('motif'))), '/ecole/salaires?mois=' . $mois);
        flash('success', t('Versement annulé.'));
        redirect('/ecole/salaires', ['mois' => $mois]);
    }

    public function bulletinPaie(int $id): void
    {
        $s = DB::one('SELECT s.*, p.matricule, p.nom, p.prenom, p.poste, p.fonction FROM ecole_salaires s JOIN ecole_personnel p ON p.id = s.personnel_id WHERE s.id = ?', [$id]) ?? throw new HttpException(404);
        view('ecole::salaire_recu', ['title' => 'Paiement ' . $s['numero'], 's' => $s, 'bodyClass' => 'a4', 'backUrl' => url('/ecole/salaires', ['mois' => $s['periode']])], 'layouts/print');
    }
}
