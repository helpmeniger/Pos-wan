<?php
declare(strict_types=1);

namespace Addons\Ecole;

use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Request;

/** Tableau de bord, paramètres (années, périodes, points de pointage), classes et matières, photos. */
final class EcoleController extends Controller
{
    public function dashboard(): void
    {
        $an = Ecole::anneeCourante();
        $today = date('Y-m-d');
        $stat = ['eleves' => 0, 'filles' => 0, 'classes' => 0, 'personnel' => 0, 'jour' => 0, 'mois' => 0, 'echu' => 0, 'presents' => 0, 'absents' => 0, 'retards' => 0, 'personnel_present' => 0];
        if ($an) {
            $r = DB::one("SELECT COUNT(*) AS n, SUM(e.sexe = 'F') AS f FROM ecole_inscriptions i JOIN ecole_eleves e ON e.id = i.eleve_id WHERE i.annee_id = ? AND i.statut = 'active'", [$an['id']]);
            $stat['eleves'] = (int) $r['n']; $stat['filles'] = (int) $r['f'];
            $stat['classes'] = (int) DB::value('SELECT COUNT(*) FROM ecole_classes WHERE annee_id = ? AND active = 1', [$an['id']]);
            $p = DB::one("SELECT SUM(p.statut = 'present') AS pr, SUM(p.statut = 'absent') AS ab, SUM(p.statut = 'retard') AS re FROM ecole_presences p
                          JOIN ecole_inscriptions i ON i.eleve_id = p.personne_id AND i.annee_id = ? WHERE p.personne_type = 'eleve' AND p.date = ?", [$an['id'], $today]);
            $stat['presents'] = (int) $p['pr'] + (int) $p['re']; $stat['absents'] = (int) $p['ab']; $stat['retards'] = (int) $p['re'];
            $stat['echu'] = (float) DB::value("SELECT COALESCE(SUM(e.montant - e.paye),0) FROM ecole_echeances e JOIN ecole_inscriptions i ON i.id = e.inscription_id WHERE i.annee_id = ? AND i.statut = 'active' AND e.annule = 0 AND e.date_limite <= CURDATE()", [$an['id']]);
        }
        $stat['personnel'] = (int) DB::value("SELECT COUNT(*) FROM ecole_personnel WHERE statut = 'actif'");
        $stat['personnel_present'] = (int) DB::value("SELECT COUNT(*) FROM ecole_presences WHERE personne_type = 'personnel' AND date = ? AND statut IN ('present','retard')", [$today]);
        if (can('ecole.finance') || can('ecole.caisse')) {
            $stat['jour'] = (float) DB::value('SELECT COALESCE(SUM(montant),0) FROM ecole_paiements WHERE annule = 0 AND DATE(created_at) = ?', [$today]);
            $stat['mois'] = (float) DB::value("SELECT COALESCE(SUM(montant),0) FROM ecole_paiements WHERE annule = 0 AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
        }
        $derniers = can('ecole.caisse') ? DB::all('SELECT p.id, p.numero, p.montant, p.created_at, p.annule, e.nom, e.prenom FROM ecole_paiements p JOIN ecole_eleves e ON e.id = p.eleve_id ORDER BY p.id DESC LIMIT 8') : [];
        view('ecole::dashboard', ['title' => Ecole::nom(), 'annee' => $an, 'stat' => $stat, 'derniers' => $derniers, 'classes' => $an ? Ecole::classes() : []]);
    }

    // ------------------------------------------------------------------ paramètres
    public function parametres(): void
    {
        view('ecole::parametres', ['title' => 'Paramètres de l\'établissement', 'annees' => DB::all('SELECT * FROM ecole_annees ORDER BY debut DESC'),
            'annee' => Ecole::anneeCourante(), 'periodes' => ($a = Ecole::anneeCourante()) ? Ecole::periodes((int) $a['id']) : [],
            'points' => DB::all('SELECT * FROM ecole_points ORDER BY nom'), 'cameras' => self::camerasVision()]);
    }

    /** Caméras du module Reconnaissance (IP, USB…), si ce module est installé. */
    public static function camerasVision(): array
    {
        try {
            return \App\Core\Addons::isActive('vision') ? DB::all('SELECT id, name, kind FROM vision_cameras WHERE active = 1 ORDER BY name') : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function sauverParametres(): void
    {
        $this->attempt(function () {
            // Tout est vérifié avant d'enregistrer quoi que ce soit
            foreach (['ecole_heure_debut', 'ecole_heure_fin'] as $k) {
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) Request::input($k, ''))) {
                    throw new BusinessException('Heure invalide (format HH:MM).');
                }
            }
            $type = (string) Request::input('ecole_type', 'mixte');
            setting_set('ecole_type', isset(Ecole::TYPES[$type]) ? $type : 'mixte');
            foreach (['ecole_heure_debut', 'ecole_heure_fin'] as $k) {
                setting_set($k, (string) Request::input($k));
            }
            setting_set('ecole_tolerance', (string) max(0, min(120, (int) Request::input('ecole_tolerance', 10))));
            setting_set('ecole_seuil_passage', (string) max(0, min(20, round((float) str_replace(',', '.', (string) Request::input('ecole_seuil_passage', 10)), 2))));
            setting_set('ecole_signature', mb_substr(trim((string) Request::input('ecole_signature', '')), 0, 100));
            setting_set('ecole_devise', mb_substr(trim((string) Request::input('ecole_devise', '')), 0, 150));
            setting_set('ecole_absence_zero', Request::input('ecole_absence_zero') ? '1' : '0');
            setting_set('ecole_conservation', (string) max(1, min(365, (int) Request::input('ecole_conservation', 30))));
            setting_set('ecole_visage_actif', Request::input('ecole_visage_actif') ? '1' : '0');
            setting_set('ecole_visage_seuil', (string) max(0.3, min(0.7, round((float) str_replace(',', '.', (string) Request::input('ecole_visage_seuil', '0.50')), 2))));
            setting_set('ecole_visage_marge', (string) max(0, min(0.3, round((float) str_replace(',', '.', (string) Request::input('ecole_visage_marge', '0.05')), 2))));
            Audit::log('settings.ecole');
        }, '/ecole/parametres');
        flash('success', t('Paramètres enregistrés.'));
        redirect('/ecole/parametres');
    }

    public function creerAnnee(): void
    {
        $this->attempt(fn() => Ecole::creerAnnee(Request::str('libelle'), Request::str('debut'), Request::str('fin'), Request::str('decoupage'), (bool) Request::input('courante')), '/ecole/parametres');
        flash('success', t('Année scolaire créée.'));
        redirect('/ecole/parametres');
    }

    public function anneeCourante(int $id): void
    {
        $this->attempt(fn() => Ecole::rendreCourante($id), '/ecole/parametres');
        redirect('/ecole/parametres');
    }

    public function sauverPeriode(int $id): void
    {
        $this->attempt(function () use ($id) {
            DB::value('SELECT 1 FROM ecole_periodes WHERE id = ?', [$id]) ?: throw new BusinessException('Période introuvable.');
            $d = ['nom' => trim(mb_substr(Request::str('nom'), 0, 50)) ?: 'Période', 'cloturee' => Request::input('cloturee') ? 1 : 0];
            foreach (['debut', 'fin'] as $k) {
                $d[$k] = Ecole::date(Request::str($k)) ? Request::str($k) : null;
            }
            DB::update('ecole_periodes', $d, 'id = ?', [$id]);
            Audit::log('ecole.periode', null, $id, ['cloturee' => $d['cloturee']]);
        }, '/ecole/parametres');
        flash('success', t('Période enregistrée.'));
        redirect('/ecole/parametres');
    }

    public function sauverPoint(int $id = 0): void
    {
        $this->attempt(function () use ($id) {
            $nom = trim(mb_substr(Request::str('nom'), 0, 100));
            if ($nom === '') {
                throw new BusinessException('Nom du point de pointage obligatoire (ex. : Portail principal).');
            }
            $role = Request::str('role');
            $d = ['nom' => $nom, 'role' => isset(Presences::ROLES[$role]) ? $role : 'passage', 'vision_camera_id' => (int) Request::input('vision_camera_id', 0) ?: null,
                'device_label' => trim(mb_substr(Request::str('device_label'), 0, 200)) ?: null, 'actif' => Request::input('actif', '1') ? 1 : 0];
            if ($id) {
                DB::update('ecole_points', $d, 'id = ?', [$id]);
            } else {
                DB::insert('ecole_points', $d);
            }
        }, '/ecole/parametres');
        flash('success', t('Point de pointage enregistré.'));
        redirect('/ecole/parametres');
    }

    public function creerPoint(): void
    {
        $this->sauverPoint(0);
    }

    public function supprimerPoint(int $id): void
    {
        // Un point qui a déjà vu des passages est seulement désactivé (l'historique garde son nom)
        if (DB::value('SELECT 1 FROM ecole_passages WHERE point_id = ? LIMIT 1', [$id])) {
            DB::update('ecole_points', ['actif' => 0], 'id = ?', [$id]);
        } else {
            DB::run('DELETE FROM ecole_points WHERE id = ?', [$id]);
        }
        flash('success', t('Point de pointage supprimé.'));
        redirect('/ecole/parametres');
    }

    // ------------------------------------------------------------------ classes et matières
    public function classes(): void
    {
        view('ecole::classes', ['title' => 'Classes et matières', 'annee' => Ecole::anneeCourante(), 'classes' => Ecole::classes(null, false),
            'matieres' => DB::all('SELECT m.*, (SELECT COUNT(*) FROM ecole_classe_matieres cm WHERE cm.matiere_id = m.id) AS classes FROM ecole_matieres m ORDER BY m.nom'),
            'personnel' => DB::all("SELECT id, nom, prenom FROM ecole_personnel WHERE statut = 'actif' ORDER BY nom")]);
    }

    public function creerClasse(): void
    {
        $id = $this->attempt(fn() => Ecole::sauverClasse($_POST), '/ecole/classes');
        flash('success', t('Classe enregistrée.'));
        redirect('/ecole/classes/' . $id);
    }

    public function classe(int $id): void
    {
        $c = DB::one('SELECT c.*, a.libelle AS annee FROM ecole_classes c JOIN ecole_annees a ON a.id = c.annee_id WHERE c.id = ?', [$id]) ?? throw new \App\Core\HttpException(404);
        view('ecole::classe', ['title' => 'Classe ' . $c['nom'], 'c' => $c,
            'programme' => DB::all('SELECT cm.*, m.nom FROM ecole_classe_matieres cm JOIN ecole_matieres m ON m.id = cm.matiere_id WHERE cm.classe_id = ? ORDER BY m.nom', [$id]),
            'matieres' => DB::all('SELECT * FROM ecole_matieres WHERE active = 1 ORDER BY nom'),
            'personnel' => DB::all("SELECT id, nom, prenom, fonction FROM ecole_personnel WHERE statut = 'actif' ORDER BY nom"),
            'eleves' => DB::all("SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe, i.id AS inscription_id, i.type FROM ecole_inscriptions i JOIN ecole_eleves e ON e.id = i.eleve_id WHERE i.classe_id = ? AND i.statut = 'active' ORDER BY e.nom, e.prenom", [$id])]);
    }

    public function sauverClasse(int $id): void
    {
        $this->attempt(fn() => Ecole::sauverClasse($_POST, $id), '/ecole/classes/' . $id);
        flash('success', t('Classe enregistrée.'));
        redirect('/ecole/classes/' . $id);
    }

    public function programme(int $id): void
    {
        $this->attempt(fn() => Ecole::programme($id, (array) Request::input('lignes', [])), '/ecole/classes/' . $id);
        flash('success', t('Programme de la classe enregistré.'));
        redirect('/ecole/classes/' . $id);
    }

    public function creerMatiere(): void
    {
        $this->attempt(fn() => Ecole::sauverMatiere(Request::str('nom'), Request::str('code') ?: null), '/ecole/classes');
        flash('success', t('Matière enregistrée.'));
        redirect('/ecole/classes');
    }

    // ------------------------------------------------------------------ photos (accès contrôlé)
    public function photoEleve(int $id): void
    {
        $this->photo((string) DB::value('SELECT photo FROM ecole_eleves WHERE id = ?', [$id]));
    }

    public function photoPersonnel(int $id): void
    {
        $this->photo((string) DB::value('SELECT photo FROM ecole_personnel WHERE id = ?', [$id]));
    }

    private function photo(string $name): void
    {
        if (!can('ecole.view') && !can('ecole.presence') && !can('ecole.caisse')) {
            throw new \App\Core\HttpException(403);
        }
        $f = Ecole::cheminPhoto($name);
        if (!$f) {
            throw new \App\Core\HttpException(404);
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=3600');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }
}
