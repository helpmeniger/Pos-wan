<?php
declare(strict_types=1);

namespace Addons\Ecole;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Évaluations, notes et bulletins.
 *   moyenne d'une matière = Σ (note ramenée sur 20 × coefficient de l'évaluation) / Σ coefficients
 *   moyenne générale      = Σ (moyenne matière × coefficient de la matière) / Σ coefficients
 *   rang                  = classement des moyennes générales de la classe (ex æquo gérés)
 */
final class Notes
{
    public const TYPES = ['devoir' => 'Devoir', 'interrogation' => 'Interrogation', 'composition' => 'Composition', 'examen' => 'Examen', 'tp' => 'Travaux pratiques'];

    public static function appreciation(?float $m): string
    {
        return match (true) {
            $m === null => '—',
            $m >= 18 => 'Excellent', $m >= 16 => 'Très bien', $m >= 14 => 'Bien', $m >= 12 => 'Assez bien',
            $m >= 10 => 'Passable', $m >= 8 => 'Insuffisant', default => 'Très insuffisant',
        };
    }

    public static function distinction(?float $m): ?string
    {
        return match (true) {
            $m === null => null, $m >= 16 => 'Félicitations', $m >= 14 => 'Encouragements', $m >= 12 => 'Tableau d\'honneur', default => null,
        };
    }

    public static function creerEvaluation(array $in): int
    {
        $classe = (int) ($in['classe_id'] ?? 0);
        $mat = (int) ($in['matiere_id'] ?? 0);
        $per = (int) ($in['periode_id'] ?? 0);
        DB::value('SELECT 1 FROM ecole_classe_matieres WHERE classe_id = ? AND matiere_id = ?', [$classe, $mat]) ?: throw new BusinessException('Cette matière n\'est pas au programme de la classe.');
        $p = DB::one('SELECT p.* FROM ecole_periodes p JOIN ecole_classes c ON c.annee_id = p.annee_id WHERE p.id = ? AND c.id = ?', [$per, $classe]) ?? throw new BusinessException('Période invalide pour cette classe.');
        if ($p['cloturee']) {
            throw new BusinessException('La période « ' . $p['nom'] . ' » est clôturée.');
        }
        $titre = trim(mb_substr((string) ($in['titre'] ?? ''), 0, 100));
        $type = isset(self::TYPES[$in['type'] ?? '']) ? $in['type'] : 'devoir';
        $bareme = round((float) str_replace(',', '.', (string) ($in['bareme'] ?? 20)), 2);
        $coef = round((float) str_replace(',', '.', (string) ($in['coefficient'] ?? 1)), 2);
        if ($bareme <= 0 || $bareme > 100 || $coef <= 0 || $coef > 10) {
            throw new BusinessException('Barème (1 à 100) ou coefficient (0,5 à 10) invalide.');
        }
        $id = DB::insert('ecole_evaluations', ['classe_id' => $classe, 'matiere_id' => $mat, 'periode_id' => $per, 'type' => $type, 'titre' => $titre ?: self::TYPES[$type],
            'date' => Ecole::date($in['date'] ?? null) ? $in['date'] : date('Y-m-d'), 'bareme' => $bareme, 'coefficient' => $coef, 'user_id' => Auth::id()]);
        Audit::log('ecole.evaluation', null, $id);
        return $id;
    }

    /** Saisie des notes : vide = non noté, « abs » = absent ; vérifie le barème. */
    public static function saisir(int $evaluationId, array $notes): int
    {
        $ev = DB::one('SELECT e.*, p.cloturee FROM ecole_evaluations e JOIN ecole_periodes p ON p.id = e.periode_id WHERE e.id = ?', [$evaluationId]) ?? throw new BusinessException('Évaluation introuvable.');
        if ($ev['cloturee']) {
            throw new BusinessException('Période clôturée : les notes ne sont plus modifiables.');
        }
        $inscrits = array_flip(array_map('intval', array_column(DB::all("SELECT eleve_id FROM ecole_inscriptions WHERE classe_id = ? AND statut = 'active'", [$ev['classe_id']]), 'eleve_id')));
        $n = 0;
        DB::transaction(function () use ($notes, $inscrits, $ev, $evaluationId, &$n) {
            foreach ($notes as $eleveId => $v) {
                $eleveId = (int) $eleveId;
                if (!isset($inscrits[$eleveId])) {
                    continue;
                }
                $v = trim(mb_strtolower((string) $v));
                if ($v === '') {
                    DB::run('DELETE FROM ecole_notes WHERE evaluation_id = ? AND eleve_id = ?', [$evaluationId, $eleveId]);
                    continue;
                }
                $abs = in_array($v, ['abs', 'a', 'absent'], true);
                $note = $abs ? null : round((float) str_replace(',', '.', $v), 2);
                if (!$abs && (!is_numeric(str_replace(',', '.', $v)) || $note < 0 || $note > (float) $ev['bareme'])) {
                    throw new BusinessException('Note invalide « ' . $v . ' » : elle doit être entre 0 et ' . rtrim(rtrim((string) $ev['bareme'], '0'), '.') . ' (ou « abs »).');
                }
                DB::run('INSERT INTO ecole_notes (evaluation_id, eleve_id, note, absent) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE note = VALUES(note), absent = VALUES(absent)', [$evaluationId, $eleveId, $note, $abs ? 1 : 0]);
                $n++;
            }
        });
        Audit::log('ecole.notes', null, $evaluationId, ['notes' => $n]);
        return $n;
    }

    /**
     * Résultats d'une classe pour une période (ou l'année si $periodeId = null) :
     * moyennes par matière et par élève, moyenne générale, rang, statistiques de classe.
     */
    public static function resultats(int $classeId, ?int $periodeId): array
    {
        $classe = DB::one('SELECT * FROM ecole_classes WHERE id = ?', [$classeId]) ?? throw new BusinessException('Classe introuvable.');
        $matieres = DB::all('SELECT cm.*, m.nom, m.code, CONCAT(p.prenom, \' \', p.nom) AS enseignant FROM ecole_classe_matieres cm JOIN ecole_matieres m ON m.id = cm.matiere_id
                             LEFT JOIN ecole_personnel p ON p.id = cm.enseignant_id WHERE cm.classe_id = ? ORDER BY cm.coefficient DESC, m.nom', [$classeId]);
        $eleves = DB::all("SELECT e.*, i.id AS inscription_id FROM ecole_inscriptions i JOIN ecole_eleves e ON e.id = i.eleve_id WHERE i.classe_id = ? AND i.statut = 'active' ORDER BY e.nom, e.prenom", [$classeId]);
        $absZero = setting('ecole_absence_zero', '0') === '1';
        $periodes = $periodeId ? [$periodeId] : array_map('intval', array_column(Ecole::periodes((int) $classe['annee_id']), 'id'));
        $res = [];
        foreach ($periodes as $pid) {
            $rows = DB::all('SELECT n.eleve_id, e.matiere_id, n.note, n.absent, e.bareme, e.coefficient FROM ecole_notes n JOIN ecole_evaluations e ON e.id = n.evaluation_id
                             WHERE e.classe_id = ? AND e.periode_id = ?', [$classeId, $pid]);
            $acc = [];
            foreach ($rows as $r) {
                if ($r['absent'] && !$absZero) {
                    continue;
                }
                $sur20 = $r['absent'] ? 0.0 : (float) $r['note'] * 20 / (float) $r['bareme'];
                $k = $r['eleve_id'] . ':' . $r['matiere_id'];
                $acc[$k][0] = ($acc[$k][0] ?? 0) + $sur20 * (float) $r['coefficient'];
                $acc[$k][1] = ($acc[$k][1] ?? 0) + (float) $r['coefficient'];
            }
            foreach ($acc as $k => [$s, $c]) {
                $res[$pid][$k] = $c > 0 ? $s / $c : null;
            }
        }
        // Moyenne de chaque matière : sur la période, ou moyenne des périodes pour l'année
        $out = [];
        foreach ($eleves as $el) {
            $lignes = []; $sm = 0; $sc = 0; $credits = 0;
            foreach ($matieres as $m) {
                $vals = [];
                foreach ($periodes as $pid) {
                    $v = $res[$pid][$el['id'] . ':' . $m['matiere_id']] ?? null;
                    if ($v !== null) {
                        $vals[] = $v;
                    }
                }
                $moy = $vals ? round(array_sum($vals) / count($vals), 2) : null;
                $lignes[(int) $m['matiere_id']] = $moy;
                if ($moy !== null) {
                    $sm += $moy * (float) $m['coefficient'];
                    $sc += (float) $m['coefficient'];
                    if ($m['credits'] !== null && $moy >= 10) {
                        $credits += (float) $m['credits'];
                    }
                }
            }
            $out[(int) $el['id']] = ['eleve' => $el, 'matieres' => $lignes, 'moyenne' => $sc > 0 ? round($sm / $sc, 2) : null, 'total_coef' => $sc, 'points' => round($sm, 2), 'credits' => $credits];
        }
        // Rangs (ex æquo : même rang, le suivant est décalé)
        $tri = array_filter($out, fn($r) => $r['moyenne'] !== null);
        uasort($tri, fn($a, $b) => $b['moyenne'] <=> $a['moyenne']);
        $rang = 0; $prec = null; $i = 0;
        foreach ($tri as $id => $r) {
            $i++;
            if ($r['moyenne'] !== $prec) {
                $rang = $i;
                $prec = $r['moyenne'];
            }
            $out[$id]['rang'] = $rang;
            $out[$id]['ex_aequo'] = false;
        }
        $compte = array_count_values(array_map(fn($r) => (string) ($r['rang'] ?? ''), $out));
        foreach ($out as $id => $r) {
            $out[$id]['rang'] ??= null;
            $out[$id]['ex_aequo'] = isset($r['rang']) && ($compte[(string) $r['rang']] ?? 0) > 1;
        }
        // Statistiques de la classe, par matière et générale
        $statM = [];
        foreach ($matieres as $m) {
            $v = array_values(array_filter(array_map(fn($r) => $r['matieres'][(int) $m['matiere_id']], $out), fn($x) => $x !== null));
            $statM[(int) $m['matiere_id']] = $v ? ['moy' => round(array_sum($v) / count($v), 2), 'min' => min($v), 'max' => max($v)] : null;
        }
        $g = array_values(array_filter(array_map(fn($r) => $r['moyenne'], $out), fn($x) => $x !== null));
        return ['classe' => $classe, 'matieres' => $matieres, 'eleves' => $out, 'stat_matieres' => $statM,
            'stat' => $g ? ['moy' => round(array_sum($g) / count($g), 2), 'min' => min($g), 'max' => max($g), 'effectif' => count($out), 'classes' => count($g), 'admis' => count(array_filter($g, fn($x) => $x >= (float) setting('ecole_seuil_passage', '10')))] : ['moy' => null, 'min' => null, 'max' => null, 'effectif' => count($out), 'classes' => 0, 'admis' => 0]];
    }

    public static function sauverBulletin(int $inscriptionId, int $periodeId, array $in): void
    {
        DB::value('SELECT 1 FROM ecole_inscriptions WHERE id = ?', [$inscriptionId]) ?: throw new BusinessException('Inscription introuvable.');
        DB::run('INSERT INTO ecole_bulletins (inscription_id, periode_id, appreciation, conduite, decision) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE appreciation = VALUES(appreciation), conduite = VALUES(conduite), decision = VALUES(decision)',
            [$inscriptionId, $periodeId, trim(mb_substr((string) ($in['appreciation'] ?? ''), 0, 500)) ?: null, trim(mb_substr((string) ($in['conduite'] ?? ''), 0, 100)) ?: null, trim(mb_substr((string) ($in['decision'] ?? ''), 0, 100)) ?: null]);
    }

    /** Absences (jours) d'un élève sur une période, d'après les présences enregistrées. */
    public static function absences(int $eleveId, ?string $du, ?string $au): array
    {
        if (!$du || !$au) {
            return ['absences' => 0, 'retards' => 0, 'excuses' => 0];
        }
        $r = DB::one("SELECT SUM(statut = 'absent') AS a, SUM(statut = 'retard') AS r, SUM(statut = 'excuse') AS x FROM ecole_presences WHERE personne_type = 'eleve' AND personne_id = ? AND date BETWEEN ? AND ?", [$eleveId, $du, $au]);
        return ['absences' => (int) $r['a'], 'retards' => (int) $r['r'], 'excuses' => (int) $r['x']];
    }
}
