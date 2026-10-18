<?php
declare(strict_types=1);

namespace Addons\Ecole;

use App\Controllers\Controller;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Export;
use App\Core\HttpException;
use App\Core\Request;

/** Élèves / étudiants, personnel, cartes, données faciales. */
final class PersonnesController extends Controller
{
    // ------------------------------------------------------------------ élèves
    public function eleves(): void
    {
        $an = Ecole::anneeCourante();
        $q = trim((string) Request::query('q', ''));
        $classe = (int) Request::query('classe', 0);
        $statut = (string) Request::query('statut', 'inscrits');
        $where = ['1 = 1']; $params = [(int) ($an['id'] ?? 0)];
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $where[] = '(e.matricule = ? OR CONCAT(e.nom, \' \', e.prenom) LIKE ? OR CONCAT(e.prenom, \' \', e.nom) LIKE ? OR e.tuteur_tel LIKE ?)';
            array_push($params, $q, $like, $like, $like);
        }
        if ($classe) {
            $where[] = 'i.classe_id = ?';
            $params[] = $classe;
        }
        if ($statut === 'inscrits') {
            $where[] = "i.id IS NOT NULL AND i.statut = 'active'";
        } elseif ($statut === 'non_inscrits') {
            $where[] = "i.id IS NULL AND e.statut = 'actif'";
        } elseif (in_array($statut, ['suspendu', 'sorti', 'diplome'], true)) {
            $where[] = 'e.statut = ?';
            $params[] = $statut;
        }
        $from = 'FROM ecole_eleves e LEFT JOIN ecole_inscriptions i ON i.eleve_id = e.id AND i.annee_id = ? LEFT JOIN ecole_classes c ON c.id = i.classe_id WHERE ' . implode(' AND ', $where);
        if (Export::requested()) {
            $rows = DB::all('SELECT e.*, c.nom AS classe ' . $from . ' ORDER BY c.nom, e.nom, e.prenom', $params);
            Export::download('eleves_' . date('Ymd') . '.csv', 'Liste des ' . mb_strtolower(Ecole::apprenant(true)), ['Matricule', 'Nom', 'Prénom', 'Sexe', 'Né(e) le', 'Classe', 'Tuteur', 'Téléphone tuteur', 'Statut'],
                array_map(fn($r) => [$r['matricule'], $r['nom'], $r['prenom'], $r['sexe'], $r['date_naissance'] ? fdate($r['date_naissance']) : '', $r['classe'] ?? '', $r['tuteur_nom'], $r['tuteur_tel'], $r['statut']], $rows));
        }
        $p = paginate($from, $params, 'e.id, e.matricule, e.nom, e.prenom, e.sexe, e.date_naissance, e.tuteur_nom, e.tuteur_tel, e.photo, e.statut, e.consentement_visage, c.nom AS classe, i.id AS inscription_id,
            (SELECT COUNT(*) FROM ecole_visages v WHERE v.personne_type = \'eleve\' AND v.personne_id = e.id) AS visages', 'e.nom, e.prenom', 40);
        view('ecole::eleves', ['title' => Ecole::apprenant(true), 'p' => $p, 'q' => $q, 'classe' => $classe, 'statut' => $statut, 'classes' => Ecole::classes()]);
    }

    public function nouvelEleve(): void
    {
        view('ecole::eleve_form', ['title' => 'Nouvel(le) ' . mb_strtolower(Ecole::apprenant()), 'e' => null, 'classes' => Ecole::classes(), 'scripts' => $this->scriptsVisage()]);
    }

    public function modifierEleve(int $id): void
    {
        view('ecole::eleve_form', ['title' => 'Modifier la fiche', 'e' => Personnes::eleve($id), 'classes' => [], 'scripts' => $this->scriptsVisage()]);
    }

    private function scriptsVisage(): array
    {
        return ['addons/ecole/ecole-visage.js', 'addons/ecole/ecole-form.js'];
    }

    public function creerEleve(): void
    {
        $id = $this->attempt(fn() => DB::transaction(function () {
            // Fiche + inscription + visage : tout ou rien
            $id = Personnes::sauverEleve($_POST, null, Request::input('photo_data') ?: null, $_FILES['photo'] ?? null);
            if ((int) Request::input('classe_id', 0)) {
                Finance::inscrire($id, (int) Request::input('classe_id'), $_POST);
            }
            $this->visagesDuFormulaire('eleve', $id);
            return $id;
        }), '/ecole/eleves/nouveau');
        flash('success', t('Fiche enregistrée.'));
        redirect(Request::input('aller_caisse') ? '/ecole/caisse?eleve=' . $id : '/ecole/eleves/' . $id);
    }

    public function sauverEleve(int $id): void
    {
        $this->attempt(function () use ($id) {
            Personnes::sauverEleve($_POST, $id, Request::input('photo_data') ?: null, $_FILES['photo'] ?? null);
            $this->visagesDuFormulaire('eleve', $id);
        }, '/ecole/eleves/' . $id . '/modifier');
        flash('success', t('Fiche enregistrée.'));
        redirect('/ecole/eleves/' . $id);
    }

    /** Empreintes faciales calculées dans le formulaire (si autorisé et consenti). */
    private function visagesDuFormulaire(string $type, int $id): void
    {
        $v = json_decode((string) Request::input('visages', ''), true);
        if (is_array($v) && $v && can('ecole.biometrie') && Biometrie::active()) {
            Biometrie::enregistrer($type, $id, $v, 'inscription');
        }
    }

    public function eleve(int $id): void
    {
        $e = Personnes::eleve($id);
        $an = Ecole::anneeCourante();
        $ins = DB::all('SELECT i.*, c.nom AS classe, a.libelle AS annee FROM ecole_inscriptions i JOIN ecole_classes c ON c.id = i.classe_id JOIN ecole_annees a ON a.id = i.annee_id WHERE i.eleve_id = ? ORDER BY a.debut DESC', [$id]);
        $cur = $an ? (array_values(array_filter($ins, fn($i) => (int) $i['annee_id'] === (int) $an['id']))[0] ?? null) : null;
        $notes = [];
        if ($cur && can('ecole.bulletins')) {
            foreach (Ecole::periodes((int) $an['id']) as $per) {
                $r = Notes::resultats((int) $cur['classe_id'], (int) $per['id']);
                $notes[] = ['periode' => $per, 'moyenne' => $r['eleves'][$id]['moyenne'] ?? null, 'rang' => $r['eleves'][$id]['rang'] ?? null, 'effectif' => $r['stat']['effectif']];
            }
        }
        view('ecole::eleve', ['title' => $e['prenom'] . ' ' . $e['nom'], 'e' => $e, 'inscriptions' => $ins, 'cur' => $cur,
            'echeances' => $cur ? Finance::echeances((int) $cur['id']) : [], 'solde' => $cur ? Finance::solde((int) $cur['id']) : null,
            'paiements' => DB::all('SELECT * FROM ecole_paiements WHERE eleve_id = ? ORDER BY id DESC LIMIT 30', [$id]),
            'presences' => DB::all("SELECT * FROM ecole_presences WHERE personne_type = 'eleve' AND personne_id = ? ORDER BY date DESC LIMIT 20", [$id]),
            'notes' => $notes, 'visages' => Biometrie::nombre('eleve', $id), 'classes' => Ecole::classes(), 'scripts' => $this->scriptsVisage()]);
    }

    public function inscrire(int $id): void
    {
        $this->attempt(fn() => Finance::inscrire($id, (int) Request::input('classe_id', 0), $_POST), '/ecole/eleves/' . $id);
        flash('success', t('Inscription enregistrée : les frais sont à encaisser dans la caisse.'));
        redirect('/ecole/eleves/' . $id);
    }

    public function frais(int $id): void
    {
        $inscriptionId = $id; // le routeur transmet le paramètre {id}
        $eid = (int) DB::value('SELECT eleve_id FROM ecole_inscriptions WHERE id = ?', [$inscriptionId]);
        $this->attempt(fn() => Finance::ajouterFrais($inscriptionId, Request::str('libelle'), Request::input('montant'), Request::str('date') ?: null), '/ecole/eleves/' . $eid);
        flash('success', t('Frais ajoutés.'));
        redirect('/ecole/eleves/' . $eid);
    }

    public function changerClasse(int $id): void
    {
        $inscriptionId = $id; // le routeur transmet le paramètre {id}
        $eid = (int) DB::value('SELECT eleve_id FROM ecole_inscriptions WHERE id = ?', [$inscriptionId]);
        $this->attempt(fn() => Finance::changerClasse($inscriptionId, (int) Request::input('classe_id', 0)), '/ecole/eleves/' . $eid);
        flash('success', t('Classe modifiée.'));
        redirect('/ecole/eleves/' . $eid);
    }

    public function abandon(int $id): void
    {
        $inscriptionId = $id; // le routeur transmet le paramètre {id}
        $eid = (int) DB::value('SELECT eleve_id FROM ecole_inscriptions WHERE id = ?', [$inscriptionId]);
        $this->attempt(fn() => Finance::abandon($inscriptionId, Request::str('statut')), '/ecole/eleves/' . $eid);
        flash('success', t('Inscription mise à jour.'));
        redirect('/ecole/eleves/' . $eid);
    }

    /** Recherche (caisse, pointage, revue) : JSON. */
    public function recherche(): void
    {
        if (!can('ecole.view') && !can('ecole.caisse') && !can('ecole.presence') && !can('ecole.biometrie')) {
            throw new HttpException(403);
        }
        $items = array_map(fn($r) => ['id' => (int) $r['id'], 'matricule' => $r['matricule'], 'nom' => $r['prenom'] . ' ' . $r['nom'], 'classe' => $r['classe'], 'statut' => $r['statut'],
            'inscription_id' => $r['inscription_id'] ? (int) $r['inscription_id'] : null, 'photo' => $r['photo'] ? url('/ecole/photo/eleve/' . $r['id']) : null],
            Personnes::chercherEleves((string) Request::query('q', '')));
        if (Request::query('personnel') === '1' && mb_strlen(trim((string) Request::query('q', ''))) >= 2) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim((string) Request::query('q'))) . '%';
            foreach (DB::all("SELECT id, matricule, nom, prenom, poste, fonction, photo FROM ecole_personnel WHERE statut = 'actif' AND (matricule = ? OR CONCAT(nom, ' ', prenom) LIKE ? OR CONCAT(prenom, ' ', nom) LIKE ?) LIMIT 10", [trim((string) Request::query('q')), $like, $like]) as $p) {
                $items[] = ['id' => (int) $p['id'], 'type' => 'personnel', 'matricule' => $p['matricule'], 'nom' => $p['prenom'] . ' ' . $p['nom'], 'classe' => $p['poste'] ?: Ecole::FONCTIONS[$p['fonction']], 'photo' => $p['photo'] ? url('/ecole/photo/personnel/' . $p['id']) : null];
            }
        }
        json_response(['ok' => true, 'items' => $items]);
    }

    /** Carte scolaire / carte professionnelle (format carte bancaire), avec code-barres du matricule. */
    public function carteEleve(int $id): void
    {
        $e = Personnes::eleve($id);
        $c = Personnes::carte('eleve', $id);
        view('ecole::carte', ['title' => 'Carte ' . $e['matricule'], 'cartes' => [['p' => $e, 'detail' => $c['detail'], 'photo' => $e['photo'] ? url('/ecole/photo/eleve/' . $id) : null, 'titre' => 'CARTE ' . mb_strtoupper(Ecole::apprenant() === 'Étudiant' ? "d'étudiant" : 'scolaire')]],
            'annee' => Ecole::anneeCourante(), 'backUrl' => url('/ecole/eleves/' . $id)], 'layouts/print');
    }

    public function cartesClasse(int $id): void
    {
        $classeId = $id; // le routeur transmet le paramètre {id}
        $rows = DB::all("SELECT e.* FROM ecole_inscriptions i JOIN ecole_eleves e ON e.id = i.eleve_id WHERE i.classe_id = ? AND i.statut = 'active' ORDER BY e.nom, e.prenom", [$classeId]);
        $cl = (string) DB::value('SELECT nom FROM ecole_classes WHERE id = ?', [$classeId]);
        view('ecole::carte', ['title' => 'Cartes ' . $cl, 'cartes' => array_map(fn($e) => ['p' => $e, 'detail' => Ecole::apprenant() . ' · ' . $cl, 'photo' => $e['photo'] ? url('/ecole/photo/eleve/' . $e['id']) : null, 'titre' => 'CARTE ' . (Ecole::apprenant() === 'Étudiant' ? "D'ÉTUDIANT" : 'SCOLAIRE')], $rows),
            'annee' => Ecole::anneeCourante(), 'backUrl' => url('/ecole/classes/' . $classeId)], 'layouts/print');
    }

    // ------------------------------------------------------------------ personnel
    public function personnelListe(): void
    {
        $q = trim((string) Request::query('q', ''));
        $f = (string) Request::query('fonction', '');
        $where = ['1 = 1']; $params = [];
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $where[] = '(matricule = ? OR CONCAT(nom, \' \', prenom) LIKE ? OR telephone LIKE ?)';
            array_push($params, $q, $like, $like);
        }
        if (isset(Ecole::FONCTIONS[$f])) {
            $where[] = 'fonction = ?';
            $params[] = $f;
        }
        $p = paginate('FROM ecole_personnel WHERE ' . implode(' AND ', $where), $params, '*, (SELECT COUNT(*) FROM ecole_visages v WHERE v.personne_type = \'personnel\' AND v.personne_id = ecole_personnel.id) AS visages', 'statut, nom, prenom', 40);
        view('ecole::personnel_liste', ['title' => 'Personnel et enseignants', 'p' => $p, 'q' => $q, 'fonction' => $f]);
    }

    public function nouveauPersonnel(): void
    {
        view('ecole::personnel_form', ['title' => 'Nouveau membre du personnel', 'e' => null, 'scripts' => $this->scriptsVisage()]);
    }

    public function modifierPersonnel(int $id): void
    {
        view('ecole::personnel_form', ['title' => 'Modifier la fiche', 'e' => Personnes::personnel($id), 'scripts' => $this->scriptsVisage()]);
    }

    public function creerPersonnel(): void
    {
        $id = $this->attempt(function () {
            $id = Personnes::sauverPersonnel($_POST, null, Request::input('photo_data') ?: null, $_FILES['photo'] ?? null);
            $this->visagesDuFormulaire('personnel', $id);
            return $id;
        }, '/ecole/personnel/nouveau');
        flash('success', t('Fiche enregistrée.'));
        redirect('/ecole/personnel/' . $id);
    }

    public function sauverPersonnel(int $id): void
    {
        $this->attempt(function () use ($id) {
            Personnes::sauverPersonnel($_POST, $id, Request::input('photo_data') ?: null, $_FILES['photo'] ?? null);
            $this->visagesDuFormulaire('personnel', $id);
        }, '/ecole/personnel/' . $id . '/modifier');
        flash('success', t('Fiche enregistrée.'));
        redirect('/ecole/personnel/' . $id);
    }

    public function personnelFiche(int $id): void
    {
        $p = Personnes::personnel($id);
        view('ecole::personnel', ['title' => $p['prenom'] . ' ' . $p['nom'], 'e' => $p,
            'cours' => DB::all('SELECT c.nom AS classe, m.nom AS matiere, cm.coefficient FROM ecole_classe_matieres cm JOIN ecole_classes c ON c.id = cm.classe_id JOIN ecole_matieres m ON m.id = cm.matiere_id
                                JOIN ecole_annees a ON a.id = c.annee_id AND a.courante = 1 WHERE cm.enseignant_id = ? ORDER BY c.nom', [$id]),
            'salaires' => can('ecole.finance') ? DB::all('SELECT * FROM ecole_salaires WHERE personnel_id = ? ORDER BY periode DESC, id DESC LIMIT 24', [$id]) : [],
            'presences' => DB::all("SELECT * FROM ecole_presences WHERE personne_type = 'personnel' AND personne_id = ? ORDER BY date DESC LIMIT 20", [$id]),
            'visages' => Biometrie::nombre('personnel', $id), 'scripts' => $this->scriptsVisage()]);
    }

    public function cartePersonnel(int $id): void
    {
        $p = Personnes::personnel($id);
        view('ecole::carte', ['title' => 'Carte ' . $p['matricule'], 'cartes' => [['p' => $p, 'detail' => $p['poste'] ?: Ecole::FONCTIONS[$p['fonction']], 'photo' => $p['photo'] ? url('/ecole/photo/personnel/' . $id) : null, 'titre' => 'CARTE PROFESSIONNELLE']],
            'annee' => Ecole::anneeCourante(), 'backUrl' => url('/ecole/personnel/' . $id)], 'layouts/print');
    }

    // ------------------------------------------------------------------ données faciales
    public function enregistrerVisages(): void
    {
        $this->jsonAttempt(fn() => ['total' => Biometrie::enregistrer((string) Request::input('type'), (int) Request::input('id'), (array) Request::input('empreintes', []), (string) Request::input('source', 'inscription'))]);
    }

    public function supprimerVisages(): void
    {
        $type = (string) Request::input('type');
        $id = (int) Request::input('id');
        $this->attempt(fn() => Biometrie::supprimer($type, $id), '/ecole');
        flash('success', t('Données faciales supprimées.'));
        redirect($type === 'personnel' ? '/ecole/personnel/' . $id : '/ecole/eleves/' . $id);
    }

    public function galerie(): void
    {
        if (!Biometrie::active()) {
            json_response(['ok' => false, 'error' => 'La reconnaissance faciale est désactivée (Paramètres de l\'établissement).'], 422);
        }
        $v = (int) Request::query('v', 0);
        header($v && $v === Biometrie::version() ? 'Cache-Control: private, max-age=3600' : 'Cache-Control: no-store');
        json_response(['ok' => true] + Biometrie::galerie());
    }

    public function personne(string $type, int $id): void
    {
        $c = Personnes::carte($type, $id) ?? throw new HttpException(404);
        json_response(['ok' => true, 'personne' => $c]);
    }

    public function personneEleve(int $id): void
    {
        $this->personne('eleve', $id);
    }

    public function personnePersonnel(int $id): void
    {
        $this->personne('personnel', $id);
    }
}
