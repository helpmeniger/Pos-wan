<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\Normalizer;

/**
 * Détection des fiches susceptibles d'être des doublons, à partir des
 * seules informations administratives (identifiant, nom, prénom, date
 * et lieu de naissance). Aucune comparaison biométrique.
 *
 * Le résultat est une SUGGESTION : la décision appartient à l'agent,
 * après comparaison visuelle et vérification des pièces.
 */
final class DuplicateService
{
    public const THRESHOLD = 50;

    /**
     * Candidats pour une fiche (en création ou existante).
     * @return array<int, array{person: array, score: int, level: string, reasons: string[]}>
     */
    public static function candidatesFor(array $data, ?int $excludeId = null, string $accessFilter = '1=1'): array
    {
        $ln = Normalizer::name($data['last_name'] ?? '');
        $fn = Normalizer::name($data['first_name'] ?? '');
        $phon = Normalizer::phonetic($data['last_name'] ?? '');
        $aid = Normalizer::adminId($data['admin_id_number'] ?? null);
        if ($ln === '' && $aid === null) {
            return [];
        }
        $rows = Database::all(
            "SELECT p.* FROM persons p
             WHERE p.id <> :ex AND ($accessFilter) AND (
                (p.admin_id_norm IS NOT NULL AND p.admin_id_norm = :aid)
                OR p.last_name_phon = :phon
                OR p.last_name_norm = :fn1
                OR p.first_name_norm = :ln1
             ) LIMIT 300",
            ['ex' => $excludeId ?? 0, 'aid' => $aid ?? '', 'phon' => $phon, 'fn1' => $fn, 'ln1' => $ln]
        );
        $out = [];
        foreach ($rows as $row) {
            $m = self::score($data, $row);
            if ($m['score'] >= self::THRESHOLD) {
                $out[] = ['person' => $row] + $m;
            }
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }

    /** @return array{score: int, level: string, reasons: string[]} */
    public static function score(array $a, array $b): array
    {
        $score = 0;
        $reasons = [];
        $aln = Normalizer::name($a['last_name'] ?? '');
        $afn = Normalizer::name($a['first_name'] ?? '');
        $bln = Normalizer::name($b['last_name'] ?? '');
        $bfn = Normalizer::name($b['first_name'] ?? '');

        $aAid = Normalizer::adminId($a['admin_id_number'] ?? null);
        $bAid = Normalizer::adminId($b['admin_id_number'] ?? null);
        if ($aAid !== null && $aAid === $bAid) {
            $score += 100;
            $reasons[] = 'Même identifiant administratif';
        }

        if ($aln !== '' && $aln === $bln && $afn === $bfn) {
            $score += 50;
            $reasons[] = 'Nom et prénom identiques';
        } elseif ($aln !== '' && $aln === $bfn && $afn === $bln) {
            $score += 40;
            $reasons[] = 'Nom et prénom inversés';
        } elseif (Normalizer::phonetic($aln) === Normalizer::phonetic($bln)
            && (Normalizer::phonetic($afn) === Normalizer::phonetic($bfn) || levenshtein($afn, $bfn) <= 2)) {
            $score += 35;
            $reasons[] = 'Nom et prénom de prononciation proche';
        }

        $abd = $a['birth_date'] ?? null;
        $bbd = $b['birth_date'] ?? null;
        if ($abd && $bbd) {
            if ($abd === $bbd) {
                $score += 30;
                $reasons[] = 'Même date de naissance';
            } elseif (substr((string) $abd, 0, 4) === substr((string) $bbd, 0, 4)) {
                $score += 10;
                $reasons[] = 'Même année de naissance';
            } else {
                $score -= 20;
            }
        }
        $abp = Normalizer::name($a['birth_place'] ?? '');
        if ($abp !== '' && $abp === Normalizer::name($b['birth_place'] ?? '')) {
            $score += 5;
            $reasons[] = 'Même lieu de naissance';
        }
        $level = $score >= 100 ? 'élevé' : ($score >= 70 ? 'moyen' : 'faible');
        return ['score' => max(0, $score), 'level' => $level, 'reasons' => $reasons];
    }

    /**
     * Analyse globale : paires de fiches susceptibles d'être des doublons,
     * en excluant les paires déjà examinées.
     */
    public static function scan(string $accessFilterA, string $accessFilterB, int $limit = 200): array
    {
        $pairs = Database::all(
            "SELECT a.id AS a_id, b.id AS b_id FROM persons a
             JOIN persons b ON b.id > a.id AND (
                 b.last_name_phon = a.last_name_phon
                 OR (a.admin_id_norm IS NOT NULL AND b.admin_id_norm = a.admin_id_norm)
                 OR (b.last_name_norm = a.first_name_norm AND b.first_name_norm = a.last_name_norm)
             )
             LEFT JOIN duplicate_reviews r ON r.person_a_id = a.id AND r.person_b_id = b.id
             WHERE r.id IS NULL AND a.status = 'actif' AND b.status = 'actif'
               AND ($accessFilterA) AND ($accessFilterB)
             LIMIT 5000"
        );
        if (!$pairs) {
            return [];
        }
        $ids = array_unique(array_merge(array_column($pairs, 'a_id'), array_column($pairs, 'b_id')));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $persons = [];
        foreach (Database::all("SELECT * FROM persons WHERE id IN ($placeholders)", array_values($ids)) as $p) {
            $persons[(int) $p['id']] = $p;
        }
        $out = [];
        foreach ($pairs as $pair) {
            $a = $persons[(int) $pair['a_id']];
            $b = $persons[(int) $pair['b_id']];
            $m = self::score($a, $b);
            if ($m['score'] >= self::THRESHOLD) {
                $out[] = ['a' => $a, 'b' => $b] + $m;
            }
        }
        usort($out, fn ($x, $y) => $y['score'] <=> $x['score']);
        return array_slice($out, 0, $limit);
    }
}
