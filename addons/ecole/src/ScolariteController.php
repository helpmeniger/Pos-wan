<?php
declare(strict_types=1);

namespace Addons\Ecole;

use App\Controllers\Controller;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Export;
use App\Core\HttpException;
use App\Core\Request;

/** Notes, bulletins, appel, présences, pointage par caméra. */
final class ScolariteController extends Controller
{
    private function choix(): array
    {
        $an = Ecole::anneeCourante();
        $classes = Ecole::classes();
        $periodes = $an ? Ecole::periodes((int) $an['id']) : [];
        $classe = (int) Request::query('classe', 0) ?: (int) ($classes[0]['id'] ?? 0);
        $periode = (int) Request::query('periode', 0);
        if (!$periode && $periodes) {
            $periode = (int) $periodes[0]['id'];
            foreach ($periodes as $p) {
                if ($p['debut'] && $p['debut'] <= date('Y-m-d')) {
                    $periode = (int) $p['id'];
                }
            }
        }
        return [$an, $classes, $periodes, $classe, $periode];
    }

    // ------------------------------------------------------------------ notes
    public function notes(): void
    {
        [$an, $classes, $periodes, $classe, $periode] = $this->choix();
        view('ecole::notes', ['title' => 'Notes et évaluations', 'annee' => $an, 'classes' => $classes, 'periodes' => $periodes, 'classe' => $classe, 'periode' => $periode,
            'matieres' => $classe ? DB::all('SELECT cm.*, m.nom FROM ecole_classe_matieres cm JOIN ecole_matieres m ON m.id = cm.matiere_id WHERE cm.classe_id = ? ORDER BY m.nom', [$classe]) : [],
            'evaluations' => $classe && $periode ? DB::all('SELECT e.*, m.nom AS matiere, (SELECT COUNT(*) FROM ecole_notes n WHERE n.evaluation_id = e.id) AS saisies FROM ecole_evaluations e
                JOIN ecole_matieres m ON m.id = e.matiere_id WHERE e.classe_id = ? AND e.periode_id = ? ORDER BY e.date DESC, e.id DESC', [$classe, $periode]) : []]);
    }

    public function creerEvaluation(): void
    {
        $id = $this->attempt(fn() => Notes::creerEvaluation($_POST), '/ecole/notes?classe=' . (int) Request::input('classe_id') . '&periode=' . (int) Request::input('periode_id'));
        redirect('/ecole/evaluations/' . $id);
    }

    public function evaluation(int $id): void
    {
        $ev = DB::one('SELECT e.*, m.nom AS matiere, c.nom AS classe, p.nom AS periode, p.cloturee FROM ecole_evaluations e JOIN ecole_matieres m ON m.id = e.matiere_id
                       JOIN ecole_classes c ON c.id = e.classe_id JOIN ecole_periodes p ON p.id = e.periode_id WHERE e.id = ?', [$id]) ?? throw new HttpException(404);
        $eleves = DB::all("SELECT el.id, el.matricule, el.nom, el.prenom, n.note, n.absent FROM ecole_inscriptions i JOIN ecole_eleves el ON el.id = i.eleve_id
                           LEFT JOIN ecole_notes n ON n.eleve_id = el.id AND n.evaluation_id = ? WHERE i.classe_id = ? AND i.statut = 'active' ORDER BY el.nom, el.prenom", [$id, $ev['classe_id']]);
        view('ecole::evaluation', ['title' => $ev['titre'] . ' · ' . $ev['matiere'], 'ev' => $ev, 'eleves' => $eleves, 'scripts' => ['addons/ecole/ecole-notes.js']]);
    }

    public function saisirNotes(int $id): void
    {
        $n = $this->attempt(fn() => Notes::saisir($id, (array) Request::input('notes', [])), '/ecole/evaluations/' . $id);
        flash('success', $n . ' ' . t('note(s) enregistrée(s).'));
        redirect('/ecole/evaluations/' . $id);
    }

    public function supprimerEvaluation(int $id): void
    {
        $ev = DB::one('SELECT * FROM ecole_evaluations WHERE id = ?', [$id]) ?? throw new HttpException(404);
        $this->attempt(function () use ($id) {
            if (DB::value('SELECT 1 FROM ecole_notes WHERE evaluation_id = ? LIMIT 1', [$id])) {
                throw new BusinessException('Des notes sont saisies : videz-les d\'abord pour supprimer l\'évaluation.');
            }
            DB::run('DELETE FROM ecole_evaluations WHERE id = ?', [$id]);
        }, '/ecole/evaluations/' . $id);
        flash('success', t('Évaluation supprimée.'));
        redirect('/ecole/notes', ['classe' => $ev['classe_id'], 'periode' => $ev['periode_id']]);
    }

    // ------------------------------------------------------------------ bulletins
    public function bulletins(): void
    {
        [$an, $classes, $periodes, $classe, $periode] = $this->choix();
        $annuel = Request::query('periode') === 'annee';
        $res = $classe ? Notes::resultats($classe, $annuel ? null : $periode) : null;
        $app = $classe && !$annuel ? array_column(DB::all('SELECT b.* FROM ecole_bulletins b JOIN ecole_inscriptions i ON i.id = b.inscription_id WHERE i.classe_id = ? AND b.periode_id = ?', [$classe, $periode]), null, 'inscription_id') : [];
        if ($res && Export::requested()) {
            $rows = $res['eleves'];
            uasort($rows, fn($a, $b) => [$a['rang'] ?? 9999, $a['eleve']['nom']] <=> [$b['rang'] ?? 9999, $b['eleve']['nom']]);
            Export::download('resultats_' . preg_replace('/\W+/', '_', $res['classe']['nom']) . '.csv', 'Résultats ' . $res['classe']['nom'],
                array_merge(['Rang', 'Matricule', 'Nom'], array_map(fn($m) => $m['nom'], $res['matieres']), ['Moyenne', 'Appréciation']),
                array_map(fn($r) => array_merge([$r['rang'] ? $r['rang'] . ($r['ex_aequo'] ? ' ex' : '') : '', $r['eleve']['matricule'], $r['eleve']['prenom'] . ' ' . $r['eleve']['nom']],
                    array_map(fn($m) => $r['matieres'][(int) $m['matiere_id']] ?? '', $res['matieres']), [$r['moyenne'] ?? '', Notes::appreciation($r['moyenne'])]), array_values($rows)), ['landscape' => true]);
        }
        view('ecole::bulletins', ['title' => 'Bulletins', 'annee' => $an, 'classes' => $classes, 'periodes' => $periodes, 'classe' => $classe, 'periode' => $periode, 'annuel' => $annuel, 'res' => $res, 'app' => $app]);
    }

    public function appreciations(): void
    {
        $periode = (int) Request::input('periode_id', 0);
        $classe = (int) Request::input('classe_id', 0);
        $this->attempt(function () use ($periode) {
            DB::value('SELECT 1 FROM ecole_periodes WHERE id = ?', [$periode]) ?: throw new BusinessException('Période introuvable.');
            foreach ((array) Request::input('b', []) as $insId => $d) {
                Notes::sauverBulletin((int) $insId, $periode, (array) $d);
            }
        }, '/ecole/bulletins?classe=' . $classe . '&periode=' . $periode);
        flash('success', t('Appréciations enregistrées.'));
        redirect('/ecole/bulletins', ['classe' => $classe, 'periode' => $periode]);
    }

    /** Impression des bulletins (un élève ou toute la classe) : une page A4 par élève. */
    public function imprimer(): void
    {
        $classe = (int) Request::query('classe', 0);
        $annuel = Request::query('periode') === 'annee';
        $periode = $annuel ? null : (int) Request::query('periode', 0);
        $res = Notes::resultats($classe, $periode);
        $per = $periode ? DB::one('SELECT * FROM ecole_periodes WHERE id = ?', [$periode]) : null;
        $an = DB::one('SELECT * FROM ecole_annees WHERE id = ?', [$res['classe']['annee_id']]);
        $seul = (int) Request::query('eleve', 0);
        $eleves = array_filter($res['eleves'], fn($r) => !$seul || (int) $r['eleve']['id'] === $seul);
        if (!$eleves) {
            throw new HttpException(404);
        }
        $app = $periode ? array_column(DB::all('SELECT b.* FROM ecole_bulletins b JOIN ecole_inscriptions i ON i.id = b.inscription_id WHERE i.classe_id = ? AND b.periode_id = ?', [$classe, $periode]), null, 'inscription_id') : [];
        $abs = [];
        foreach ($eleves as $id => $r) {
            $abs[$id] = Notes::absences($id, $per['debut'] ?? $an['debut'], $per['fin'] ?? $an['fin']);
        }
        $titulaire = $res['classe']['titulaire_id'] ? DB::one('SELECT nom, prenom FROM ecole_personnel WHERE id = ?', [$res['classe']['titulaire_id']]) : null;
        view('ecole::bulletin_print', ['title' => 'Bulletins ' . $res['classe']['nom'], 'res' => $res, 'eleves' => $eleves, 'periode' => $per, 'annee' => $an, 'app' => $app, 'abs' => $abs,
            'titulaire' => $titulaire, 'bodyClass' => 'a4', 'backUrl' => url('/ecole/bulletins', ['classe' => $classe, 'periode' => $annuel ? 'annee' : $periode])], 'layouts/print');
    }

    // ------------------------------------------------------------------ présences
    public function presences(): void
    {
        $date = Ecole::date((string) Request::query('date')) && (string) Request::query('date') <= date('Y-m-d') ? (string) Request::query('date') : date('Y-m-d');
        view('ecole::presences', ['title' => 'Appel et présences', 'date' => $date, 'jour' => Presences::jour($date), 'classes' => Ecole::classes()]);
    }

    public function appelClasse(int $id): void
    {
        $date = Ecole::date((string) Request::query('date')) && (string) Request::query('date') <= date('Y-m-d') ? (string) Request::query('date') : date('Y-m-d');
        $c = DB::one('SELECT * FROM ecole_classes WHERE id = ?', [$id]) ?? throw new HttpException(404);
        $eleves = DB::all("SELECT e.id, e.matricule, e.nom, e.prenom, e.photo, p.statut, p.arrivee, p.depart, p.source, p.note FROM ecole_inscriptions i JOIN ecole_eleves e ON e.id = i.eleve_id
                           LEFT JOIN ecole_presences p ON p.personne_type = 'eleve' AND p.personne_id = e.id AND p.date = ? WHERE i.classe_id = ? AND i.statut = 'active' ORDER BY e.nom, e.prenom", [$date, $id]);
        view('ecole::appel', ['title' => 'Appel · ' . $c['nom'], 'c' => $c, 'date' => $date, 'liste' => $eleves, 'type' => 'eleve', 'action' => url('/ecole/presences/classe/' . $id), 'scripts' => ['addons/ecole/ecole-notes.js']]);
    }

    public function enregistrerAppelClasse(int $id): void
    {
        $date = Request::str('date');
        $this->attempt(fn() => Presences::appel('eleve', (array) Request::input('statut', []), $date, (array) Request::input('note', [])), '/ecole/presences/classe/' . $id . '?date=' . $date);
        flash('success', t('Appel enregistré.'));
        redirect('/ecole/presences', ['date' => $date]);
    }

    public function appelPersonnel(): void
    {
        $date = Ecole::date((string) Request::query('date')) && (string) Request::query('date') <= date('Y-m-d') ? (string) Request::query('date') : date('Y-m-d');
        $liste = DB::all("SELECT s.id, s.matricule, s.nom, s.prenom, s.photo, s.poste, p.statut, p.arrivee, p.depart, p.source, p.note FROM ecole_personnel s
                          LEFT JOIN ecole_presences p ON p.personne_type = 'personnel' AND p.personne_id = s.id AND p.date = ? WHERE s.statut = 'actif' ORDER BY s.nom, s.prenom", [$date]);
        view('ecole::appel', ['title' => 'Présences du personnel', 'c' => null, 'date' => $date, 'liste' => $liste, 'type' => 'personnel', 'action' => url('/ecole/presences/personnel'), 'scripts' => ['addons/ecole/ecole-notes.js']]);
    }

    public function enregistrerAppelPersonnel(): void
    {
        $date = Request::str('date');
        $this->attempt(fn() => Presences::appel('personnel', (array) Request::input('statut', []), $date, (array) Request::input('note', [])), '/ecole/presences/personnel?date=' . $date);
        flash('success', t('Présences du personnel enregistrées.'));
        redirect('/ecole/presences', ['date' => $date]);
    }

    public function cloturer(): void
    {
        $date = Request::str('date');
        $n = $this->attempt(fn() => Presences::cloturerJour($date), '/ecole/presences?date=' . $date);
        flash('success', $n . ' ' . t('élève(s) non pointé(s) noté(s) absent(s).'));
        redirect('/ecole/presences', ['date' => $date]);
    }

    /** Relevé des absences et retards sur une période (par élève). */
    public function rapportPresences(): void
    {
        $du = Ecole::date((string) Request::query('du')) ? (string) Request::query('du') : date('Y-m-01');
        $au = Ecole::date((string) Request::query('au')) ? (string) Request::query('au') : date('Y-m-d');
        $classe = (int) Request::query('classe', 0);
        $an = Ecole::anneeCourante();
        $rows = $an ? DB::all("SELECT e.id, e.matricule, e.nom, e.prenom, e.tuteur_tel, c.nom AS classe,
                SUM(p.statut IN ('present','retard')) AS presents, SUM(p.statut = 'retard') AS retards, SUM(p.statut = 'absent') AS absents, SUM(p.statut = 'excuse') AS excuses
                FROM ecole_inscriptions i JOIN ecole_eleves e ON e.id = i.eleve_id JOIN ecole_classes c ON c.id = i.classe_id
                LEFT JOIN ecole_presences p ON p.personne_type = 'eleve' AND p.personne_id = e.id AND p.date BETWEEN ? AND ?
                WHERE i.annee_id = ? AND i.statut = 'active'" . ($classe ? ' AND i.classe_id = ' . $classe : '') . ' GROUP BY e.id, e.matricule, e.nom, e.prenom, e.tuteur_tel, c.nom ORDER BY absents DESC, retards DESC, e.nom', [$du, $au, $an['id']]) : [];
        if (Export::requested()) {
            Export::download("presences_{$du}_{$au}.csv", 'Absences et retards', ['Matricule', 'Nom', 'Classe', 'Présences', 'Retards', 'Absences', 'Excusées', 'Tél. tuteur'],
                array_map(fn($r) => [$r['matricule'], $r['prenom'] . ' ' . $r['nom'], $r['classe'], (int) $r['presents'], (int) $r['retards'], (int) $r['absents'], (int) $r['excuses'], $r['tuteur_tel']], $rows), ['subtitle' => "Du $du au $au"]);
        }
        view('ecole::presences_rapport', ['title' => 'Absences et retards', 'rows' => $rows, 'du' => $du, 'au' => $au, 'classe' => $classe, 'classes' => Ecole::classes()]);
    }

    // ------------------------------------------------------------------ pointage par caméra
    public function pointage(): void
    {
        Presences::purgerVignettes();
        $points = DB::all('SELECT pt.*' . (EcoleController::camerasVision() ? ', vc.name AS camera, vc.kind AS camera_kind, vc.fps, vc.device_label AS camera_device' : ', NULL AS camera, NULL AS camera_kind, NULL AS fps, NULL AS camera_device') . ' FROM ecole_points pt'
            . (EcoleController::camerasVision() ? ' LEFT JOIN vision_cameras vc ON vc.id = pt.vision_camera_id' : '') . ' WHERE pt.actif = 1 ORDER BY pt.nom');
        view('ecole::pointage', ['title' => 'Pointage par caméra', 'points' => $points, 'actif' => Biometrie::active(),
            'reglages' => ['seuil' => (float) setting('ecole_visage_seuil', '0.50'), 'marge' => (float) setting('ecole_visage_marge', '0.05')],
            'personnes' => (int) DB::value('SELECT COUNT(DISTINCT personne_type, personne_id) FROM ecole_visages'), 'apprendre' => can('ecole.biometrie'),
            'scripts' => ['addons/ecole/ecole-visage.js', 'addons/ecole/ecole-pointage.js']]);
    }

    public function evenement(): void
    {
        $this->jsonAttempt(function () {
            if (!Biometrie::active()) {
                throw new BusinessException('La reconnaissance faciale est désactivée.');
            }
            $thumb = (string) Request::input('thumb', '');
            if ($thumb !== '' && (strlen($thumb) > 60000 || !preg_match('#^data:image/jpeg;base64,[A-Za-z0-9+/=]+$#', $thumb))) {
                throw new BusinessException('Vignette invalide.');
            }
            $point = (int) Request::input('point_id', 0) ?: null;
            $role = $point ? (string) DB::value('SELECT role FROM ecole_points WHERE id = ?', [$point]) : (string) Request::input('role', 'passage');
            if (Request::input('inconnu')) {
                return ['passage' => Presences::inconnu($role, $point, $thumb ?: null)];
            }
            $type = (string) Request::input('type');
            if (!in_array($type, ['eleve', 'personnel'], true)) {
                throw new BusinessException('Type inconnu.');
            }
            $num = fn($k) => is_numeric(Request::input($k)) ? round((float) Request::input($k), 3) : null;
            return Presences::pointer($type, (int) Request::input('id'), $role ?: 'passage', $point, $num('distance'), $num('marge'), $thumb ?: null);
        });
    }

    /** Image actuelle de la caméra IP d'un point de pointage (relais du module Reconnaissance). */
    public function imagePoint(int $id): void
    {
        $vc = (int) DB::value('SELECT vision_camera_id FROM ecole_points WHERE id = ? AND actif = 1', [$id]);
        $cam = $vc && EcoleController::camerasVision() ? DB::one("SELECT * FROM vision_cameras WHERE id = ? AND active = 1 AND kind = 'ip'", [$vc]) : null;
        if (!$cam) {
            throw new HttpException(404);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        try {
            [$img, $mime] = \Addons\Vision\CameraService::fetchFrame($cam);
        } catch (BusinessException $e) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
            exit;
        }
        header('Content-Type: ' . $mime);
        header('Cache-Control: no-store');
        echo $img;
        exit;
    }

    public function passages(): void
    {
        Presences::purgerVignettes();
        $f = (string) Request::query('f', 'inconnu');
        $where = match ($f) { 'tous' => '1 = 1', 'reconnu' => "pa.statut IN ('reconnu','confirme','corrige')", default => "pa.statut = 'inconnu'" };
        $rows = DB::all("SELECT pa.*, pt.nom AS point,
                         CASE WHEN pa.personne_type = 'eleve' THEN (SELECT CONCAT(prenom, ' ', nom) FROM ecole_eleves WHERE id = pa.personne_id)
                              WHEN pa.personne_type = 'personnel' THEN (SELECT CONCAT(prenom, ' ', nom) FROM ecole_personnel WHERE id = pa.personne_id) END AS personne
                         FROM ecole_passages pa LEFT JOIN ecole_points pt ON pt.id = pa.point_id WHERE $where ORDER BY pa.id DESC LIMIT 150");
        view('ecole::passages', ['title' => 'Passages devant les caméras', 'rows' => $rows, 'f' => $f, 'scripts' => ['addons/ecole/ecole-passages.js']]);
    }

    public function revuePassage(int $id): void
    {
        $this->jsonAttempt(fn() => Presences::revue($id, (string) Request::input('action'), Request::input('type') ? (string) Request::input('type') : null, (int) Request::input('id', 0) ?: null));
    }
}
