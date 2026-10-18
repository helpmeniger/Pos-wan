<?php
declare(strict_types=1);

namespace Addons\Ecole;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Présences : appel manuel (par classe, personnel) et pointage automatique par caméra.
 *  - Point d'« entrée » : la première reconnaissance du jour fixe l'heure d'arrivée.
 *  - Point de « sortie » : chaque reconnaissance met à jour l'heure de départ.
 *  - Point de « passage » : arrivée à la première reconnaissance, départ aux suivantes
 *    (au moins 30 minutes après l'arrivée).
 *  - Retard : arrivée après l'heure de début + tolérance (Paramètres de l'établissement).
 * Une saisie manuelle n'est jamais écrasée par la caméra (sauf heure d'arrivée / départ vide).
 */
final class Presences
{
    public const STATUTS = ['present' => 'Présent', 'retard' => 'En retard', 'absent' => 'Absent', 'excuse' => 'Absent excusé'];
    public const ROLES = ['entree' => 'Entrée (arrivées)', 'sortie' => 'Sortie (départs)', 'passage' => 'Passage (arrivées et départs)'];

    private static function retard(string $heure): bool
    {
        $debut = (string) setting('ecole_heure_debut', '07:30');
        $tol = max(0, (int) setting('ecole_tolerance', '10'));
        return strtotime('1970-01-01 ' . $heure) > strtotime('1970-01-01 ' . $debut) + $tol * 60;
    }

    /** Appel d'une classe (ou du personnel) : statut de chacun pour une date. */
    public static function appel(string $type, array $statuts, string $date, array $notes = []): int
    {
        if (!in_array($type, ['eleve', 'personnel'], true) || !Ecole::date($date) || $date > date('Y-m-d')) {
            throw new BusinessException('Date d\'appel invalide (pas de date future).');
        }
        $n = 0;
        DB::transaction(function () use ($type, $statuts, $date, $notes, &$n) {
            foreach ($statuts as $pid => $st) {
                if (!isset(self::STATUTS[$st])) {
                    continue;
                }
                $pid = (int) $pid;
                $note = isset($notes[$pid]) ? (trim(mb_substr((string) $notes[$pid], 0, 255)) ?: null) : null;
                DB::run('INSERT INTO ecole_presences (personne_type, personne_id, date, statut, source, note, user_id) VALUES (?, ?, ?, ?, \'manuel\', ?, ?)
                         ON DUPLICATE KEY UPDATE statut = VALUES(statut), source = \'manuel\', note = COALESCE(VALUES(note), note), user_id = VALUES(user_id)', [$type, $pid, $date, $st, $note, Auth::id()]);
                $n++;
            }
        });
        Audit::log('ecole.appel', null, null, ['type' => $type, 'date' => $date, 'nombre' => $n]);
        return $n;
    }

    /** Reconnaissance d'une personne par une caméra : arrivée / départ / retard. */
    public static function pointer(string $type, int $id, string $role, ?int $pointId = null, ?float $distance = null, ?float $marge = null, ?string $thumb = null, string $statutPassage = 'reconnu'): array
    {
        if (!isset(self::ROLES[$role])) {
            throw new BusinessException('Rôle du point de pointage inconnu.');
        }
        $carte = Personnes::carte($type, $id) ?? throw new BusinessException('Personne introuvable.');
        $now = date('H:i:s');
        $today = date('Y-m-d');
        $res = DB::transaction(function () use ($type, $id, $role, $now, $today) {
            $p = DB::one('SELECT * FROM ecole_presences WHERE personne_type = ? AND personne_id = ? AND date = ? FOR UPDATE', [$type, $id, $today]);
            $evt = 'deja';
            if (!$p) {
                if ($role === 'sortie') {
                    DB::insert('ecole_presences', ['personne_type' => $type, 'personne_id' => $id, 'date' => $today, 'depart' => $now, 'statut' => 'present', 'source' => 'camera']);
                    return ['evenement' => 'depart', 'arrivee' => null, 'depart' => $now, 'statut' => 'present'];
                }
                $st = self::retard($now) ? 'retard' : 'present';
                DB::insert('ecole_presences', ['personne_type' => $type, 'personne_id' => $id, 'date' => $today, 'arrivee' => $now, 'statut' => $st, 'source' => 'camera']);
                return ['evenement' => 'arrivee', 'arrivee' => $now, 'depart' => null, 'statut' => $st];
            }
            $maj = [];
            if (!$p['arrivee'] && $role !== 'sortie') {
                $maj['arrivee'] = $now;
                $evt = 'arrivee';
                if ($p['source'] === 'camera' || $p['statut'] === 'absent') {
                    $maj['statut'] = self::retard($now) ? 'retard' : 'present'; // présent finalement (appel corrigé par la caméra)
                }
            } elseif ($role === 'sortie' || ($role === 'passage' && $p['arrivee'] && strtotime($today . ' ' . $now) - strtotime($today . ' ' . $p['arrivee']) >= 1800)) {
                $maj['depart'] = $now;
                $evt = 'depart';
            }
            if ($maj) {
                DB::update('ecole_presences', $maj, 'id = ?', [$p['id']]);
            }
            $q = DB::one('SELECT arrivee, depart, statut FROM ecole_presences WHERE id = ?', [$p['id']]);
            return ['evenement' => $evt, 'arrivee' => $q['arrivee'], 'depart' => $q['depart'], 'statut' => $q['statut']];
        });
        DB::insert('ecole_passages', ['point_id' => $pointId, 'role' => $role, 'personne_type' => $type, 'personne_id' => $id, 'distance' => $distance, 'marge' => $marge,
            'statut' => $statutPassage, 'thumb' => $thumb, 'vu_a' => date('Y-m-d H:i:s'), 'user_id' => Auth::id()]);
        return $res + ['personne' => $carte];
    }

    /** Visage non reconnu : gardé pour la revue (attribuer à une personne ou ignorer). */
    public static function inconnu(string $role, ?int $pointId, ?string $thumb): int
    {
        return DB::insert('ecole_passages', ['point_id' => $pointId, 'role' => isset(self::ROLES[$role]) ? $role : 'passage', 'statut' => 'inconnu', 'thumb' => $thumb, 'vu_a' => date('Y-m-d H:i:s'), 'user_id' => Auth::id()]);
    }

    /** Revue d'un passage : attribuer (et pointer, à l'heure du passage) ou ignorer. */
    public static function revue(int $passageId, string $action, ?string $type, ?int $id): array
    {
        $p = DB::one('SELECT * FROM ecole_passages WHERE id = ?', [$passageId]) ?? throw new BusinessException('Passage introuvable.');
        if ($action === 'ignorer') {
            DB::update('ecole_passages', ['statut' => 'ignore'], 'id = ?', [$passageId]);
            return ['statut' => 'ignore'];
        }
        if ($action !== 'attribuer' || !$type || !$id || !Personnes::carte($type, $id)) {
            throw new BusinessException('Choisissez la personne.');
        }
        $date = substr($p['vu_a'], 0, 10); $heure = substr($p['vu_a'], 11, 8);
        DB::transaction(function () use ($p, $passageId, $type, $id, $date, $heure) {
            DB::update('ecole_passages', ['statut' => $p['personne_id'] ? 'corrige' : 'confirme', 'personne_type' => $type, 'personne_id' => $id], 'id = ?', [$passageId]);
            $pr = DB::one('SELECT * FROM ecole_presences WHERE personne_type = ? AND personne_id = ? AND date = ? FOR UPDATE', [$type, $id, $date]);
            if (!$pr) {
                DB::insert('ecole_presences', ['personne_type' => $type, 'personne_id' => $id, 'date' => $date, $p['role'] === 'sortie' ? 'depart' : 'arrivee' => $heure, 'statut' => $p['role'] !== 'sortie' && self::retard($heure) ? 'retard' : 'present', 'source' => 'camera', 'user_id' => Auth::id()]);
            } elseif ($p['role'] !== 'sortie' && (!$pr['arrivee'] || $heure < $pr['arrivee'])) {
                DB::update('ecole_presences', ['arrivee' => $heure], 'id = ?', [$pr['id']]);
            } elseif ($p['role'] === 'sortie' && (!$pr['depart'] || $heure > $pr['depart'])) {
                DB::update('ecole_presences', ['depart' => $heure], 'id = ?', [$pr['id']]);
            }
        });
        Audit::log('ecole.passage_revue', null, $passageId, ['type' => $type, 'id' => $id]);
        return ['statut' => $p['personne_id'] ? 'corrige' : 'confirme'];
    }

    /** Situation d'une journée : par classe et pour le personnel. */
    public static function jour(string $date): array
    {
        $an = Ecole::anneeCourante();
        $classes = $an ? DB::all("SELECT c.id, c.nom, COUNT(i.id) AS inscrits,
                    SUM(p.statut = 'present') AS presents, SUM(p.statut = 'retard') AS retards, SUM(p.statut = 'absent') AS absents, SUM(p.statut = 'excuse') AS excuses,
                    SUM(p.id IS NULL) AS non_pointes
                    FROM ecole_classes c JOIN ecole_inscriptions i ON i.classe_id = c.id AND i.statut = 'active'
                    LEFT JOIN ecole_presences p ON p.personne_type = 'eleve' AND p.personne_id = i.eleve_id AND p.date = ?
                    WHERE c.annee_id = ? GROUP BY c.id, c.nom ORDER BY c.cycle, c.niveau, c.nom", [$date, $an['id']]) : [];
        $pers = DB::all("SELECT s.id, s.matricule, s.nom, s.prenom, s.fonction, s.poste, p.arrivee, p.depart, p.statut, p.source FROM ecole_personnel s
                         LEFT JOIN ecole_presences p ON p.personne_type = 'personnel' AND p.personne_id = s.id AND p.date = ? WHERE s.statut = 'actif' ORDER BY s.nom, s.prenom", [$date]);
        return ['classes' => $classes, 'personnel' => $pers];
    }

    /** Fin de journée : les élèves inscrits sans pointage ni appel sont notés absents. */
    public static function cloturerJour(string $date): int
    {
        $an = Ecole::anneeCouranteOuErreur();
        if (!Ecole::date($date) || $date > date('Y-m-d')) {
            throw new BusinessException('Date invalide.');
        }
        $n = DB::run("INSERT IGNORE INTO ecole_presences (personne_type, personne_id, date, statut, source, note, user_id)
                      SELECT 'eleve', i.eleve_id, ?, 'absent', 'manuel', 'Non pointé (clôture de la journée)', ? FROM ecole_inscriptions i
                      WHERE i.annee_id = ? AND i.statut = 'active'", [$date, Auth::id(), $an['id']])->rowCount();
        Audit::log('ecole.cloture_jour', null, null, ['date' => $date, 'absents' => $n]);
        return $n;
    }

    public static function purgerVignettes(): int
    {
        $j = max(1, min(365, (int) setting('ecole_conservation', '30')));
        return DB::run('UPDATE ecole_passages SET thumb = NULL WHERE thumb IS NOT NULL AND vu_a < NOW() - INTERVAL ' . $j . ' DAY')->rowCount()
            + DB::run("DELETE FROM ecole_passages WHERE statut IN ('inconnu','ignore') AND vu_a < NOW() - INTERVAL " . $j . ' DAY')->rowCount();
    }
}
