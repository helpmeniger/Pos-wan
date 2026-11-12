<?php
declare(strict_types=1);

namespace Addons\MarwaIa;

/**
 * Outils de langue française pour MARWA IA (tout est local) : normalisation, mots vides,
 * racines simples, similarité entre un nom recherché et un nom enregistré, lecture des
 * périodes (« hier », « le mois dernier », « du 1er au 15 octobre », « en mars 2025 »…).
 */
final class Text
{
    public const STOP = ['le', 'la', 'les', 'l', 'un', 'une', 'des', 'de', 'du', 'd', 'au', 'aux', 'a', 'et', 'ou', 'en', 'pour', 'par', 'sur', 'avec',
        'moi', 'me', 'm', 'mon', 'ma', 'mes', 'je', 'j', 'tu', 'te', 't', 'ton', 'ta', 'tes', 'il', 'elle', 'on', 'nous', 'vous', 'ils', 'elles', 'se', 's',
        'ce', 'cet', 'cette', 'ces', 'ca', 'cela', 'qui', 'que', 'qu', 'quoi', 'quel', 'quelle', 'quels', 'quelles', 'est', 'sont', 'etre', 'ai', 'as', 'avoir',
        'svp', 'stp', 'merci', 'donne', 'donner', 'donnez', 'montre', 'montrer', 'affiche', 'afficher', 'veux', 'voudrais', 'peux', 'pouvez', 'besoin',
        'tout', 'tous', 'toute', 'toutes', 'y', 'ne', 'pas', 'plus', 'dans', 'chez', 'mais', 'donc', 'si', 'comme', 'aussi', 'bien', 'fait', 'faire',
        'stp', 'sil', 'plait', 'please', 'bonjour', 'salut', 'marwa', 'ia', 'pos', 'logiciel'];

    public const MONTHS = ['janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6, 'juillet' => 7, 'aout' => 8,
        'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12, 'janv' => 1, 'fev' => 2, 'avr' => 4, 'juil' => 7, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /** Minuscules sans accents ni ponctuation (les chiffres, / et - des dates sont gardés). */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['œ' => 'oe', 'æ' => 'ae', '’' => "'", '«' => ' ', '»' => ' ']);
        if (class_exists(\Normalizer::class)) {
            $s = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($s, \Normalizer::FORM_D));
        } else {
            $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c']);
        }
        $s = (string) preg_replace("/[^a-z0-9\\/\\-' ]+/", ' ', $s);
        $s = str_replace("'", ' ', $s);
        $s = (string) preg_replace('/(?<=[a-z])-(?=[a-z])/', ' ', $s); // « sais-tu », « avant-hier »
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    /** Mots significatifs (sans mots vides). */
    public static function words(string $s): array
    {
        return array_values(array_filter(explode(' ', self::norm($s)), fn($w) => $w !== '' && !in_array($w, self::STOP, true)));
    }

    /** Racine très simple (pluriels, quelques terminaisons courantes). */
    public static function stem(string $w): string
    {
        if (strlen($w) <= 3 || ctype_digit($w)) {
            return $w;
        }
        foreach (['ements', 'ement', 'ations', 'ation', 'euses', 'euse', 'eurs', 'eur', 'ees', 'ee', 'es', 's', 'x', 'e'] as $suf) {
            if (str_ends_with($w, $suf) && strlen($w) - strlen($suf) >= 3) {
                return substr($w, 0, -strlen($suf));
            }
        }
        return $w;
    }

    public static function stems(string $s): array
    {
        return array_map([self::class, 'stem'], self::words($s));
    }

    /** Distance d'édition bornée (fautes de frappe : « bouthique » ≈ « boutique »). */
    public static function close(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $la = strlen($a);
        $lb = strlen($b);
        if ($la < 4 || $lb < 4 || abs($la - $lb) > 2) {
            return false;
        }
        return levenshtein($a, $b) <= ($la >= 7 ? 2 : 1);
    }

    /**
     * Score de correspondance entre la question et un nom enregistré (0 à 1) : part des mots du
     * nom retrouvés dans la question (mot exact, racine, début de mot ou faute de frappe).
     */
    public static function nameScore(array $qWords, string $name): float
    {
        $nw = array_values(array_filter(self::words($name), fn($w) => strlen($w) >= 2));
        if (!$nw) {
            return 0.0;
        }
        $hit = 0.0;
        foreach ($nw as $w) {
            $best = 0.0;
            foreach ($qWords as $q) {
                if ($q === $w) {
                    $best = 1.0;
                    break;
                }
                if (strlen($q) >= 3 && (str_starts_with($w, $q) || str_starts_with($q, $w) || self::stem($q) === self::stem($w))) {
                    $best = max($best, 0.85);
                } elseif (self::close($q, $w)) {
                    $best = max($best, 0.75);
                }
            }
            $hit += $best;
        }
        // Les noms longs dont un seul mot courant correspond ne doivent pas l'emporter
        return $hit / count($nw) * min(1.0, 0.6 + 0.4 * $hit);
    }

    /**
     * Variante pour les articles : on compte aussi la part des mots de la question retrouvés
     * dans le nom (« stock du riz » → « Sac de riz 25 kg »).
     */
    public static function itemScore(array $qWords, string $name): float
    {
        $byName = self::nameScore($qWords, $name);
        $nw = self::words($name);
        if (!$qWords || !$nw) {
            return $byName;
        }
        $hit = 0.0;
        foreach ($qWords as $q) {
            $best = 0.0;
            foreach ($nw as $w) {
                if ($q === $w) {
                    $best = 1.0;
                    break;
                }
                if (strlen($q) >= 3 && (str_starts_with($w, $q) || str_starts_with($q, $w) || self::stem($q) === self::stem($w))) {
                    $best = max($best, 0.85);
                } elseif (self::close($q, $w)) {
                    $best = max($best, 0.75);
                }
            }
            $hit += $best;
        }
        return max($byName, 0.5 * $byName + 0.5 * $hit / count($qWords));
    }

    // ------------------------------------------------------------------ périodes
    /**
     * Période citée dans la question, ou null.
     * @return array{from:string,to:string,label:string}|null
     */
    public static function period(string $q, ?int $now = null): ?array
    {
        $now ??= time();
        $n = self::norm($q);
        $d = fn(int $t) => date('Y-m-d', $t);
        $today = strtotime(date('Y-m-d', $now));
        $y = (int) date('Y', $now);

        // Intervalle explicite : « du 01/10 au 15/10/2026 », « du 1er octobre au 15 octobre »
        if (preg_match('/\b(?:du|de|entre le|entre)\s+(.+?)\s+(?:au|a|et le|et|jusqu au)\s+(.+?)(?:\s+(?:en|au format|format|pdf|csv|excel)\b|$)/', $n, $m)) {
            $b0 = self::date($m[2], $now);
            $a = self::date($m[1], $now, $b0);
            $b = $b0 ?? self::date($m[2], $now, $a);
            if ($a && $b) {
                if ($b < $a) {
                    [$a, $b] = [$b, $a];
                }
                return ['from' => $a, 'to' => $b, 'label' => 'du ' . date('d/m/Y', strtotime($a)) . ' au ' . date('d/m/Y', strtotime($b))];
            }
        }
        if (preg_match('/\bdepuis (?:le )?(.+?)(?:\s+(?:en|au format|pdf|csv)\b|$)/', $n, $m) && ($a = self::date($m[1], $now))) {
            return ['from' => $a, 'to' => $d($today), 'label' => 'depuis le ' . date('d/m/Y', strtotime($a))];
        }
        if (preg_match('/\b(\d{1,3}) derniers? (jours?|semaines?|mois|ans?|annees?)\b/', $n, $m) || preg_match('/\b(?:les |ces )?(\d{1,3}) (jours?|semaines?|mois) (?:derniers?|passes?)\b/', $n, $m)) {
            $k = max(1, (int) $m[1]);
            $unit = $m[2][0];
            $from = match ($unit) { 's' => strtotime('-' . ($k * 7 - 1) . ' days', $today), 'm' => strtotime('-' . $k . ' months +1 day', $today), 'a' => strtotime('-' . $k . ' years +1 day', $today), default => strtotime('-' . ($k - 1) . ' days', $today) };
            return ['from' => $d($from), 'to' => $d($today), 'label' => 'les ' . $k . ' derniers ' . ($unit === 's' ? 'semaines' : ($unit === 'm' ? 'mois' : ($unit === 'a' ? 'ans' : 'jours')))];
        }
        // « en octobre », « octobre 2025 », « le mois d'octobre »
        foreach (self::MONTHS as $name => $mn) {
            if (preg_match('/\b' . $name . '\b(?:\s+(\d{4}))?/', $n, $m)) {
                $yy = isset($m[1]) ? (int) $m[1] : ($mn > (int) date('n', $now) ? $y - 1 : $y);
                $from = sprintf('%04d-%02d-01', $yy, $mn);
                return ['from' => $from, 'to' => date('Y-m-t', strtotime($from)), 'label' => self::monthLabel($mn) . ' ' . $yy];
            }
        }
        $map = [
            '/\bavant hier\b/' => fn() => [$d($today - 2 * 86400), $d($today - 2 * 86400), 'avant-hier'],
            '/\bhier\b/' => fn() => [$d($today - 86400), $d($today - 86400), 'hier'],
            '/\b(aujourd hui|aujourdhui|ce jour|today|du jour|de la journee|journee)\b/' => fn() => [$d($today), $d($today), 'aujourd\'hui'],
            '/\b(semaine derniere|semaine passee|derniere semaine)\b/' => fn() => [$d(strtotime('monday last week', $today)), $d(strtotime('sunday last week', $today)), 'la semaine dernière'],
            '/\b(cette semaine|semaine en cours|de la semaine)\b/' => fn() => [$d(strtotime('monday this week', $today)), $d($today), 'cette semaine'],
            '/\b(mois dernier|mois passe|dernier mois|mois precedent)\b/' => fn() => [date('Y-m-01', strtotime('first day of last month', $today)), date('Y-m-t', strtotime('first day of last month', $today)), 'le mois dernier'],
            '/\b(ce mois|mois en cours|ce mois ci|du mois|mensuel)\b/' => fn() => [date('Y-m-01', $today), $d($today), 'ce mois-ci'],
            '/\b(annee derniere|annee passee|an dernier|l an passe)\b/' => fn() => [($y - 1) . '-01-01', ($y - 1) . '-12-31', 'l\'année ' . ($y - 1)],
            '/\b(cette annee|annee en cours|de l annee|annuel)\b/' => fn() => [$y . '-01-01', $d($today), 'cette année'],
            '/\b(trimestre dernier|dernier trimestre)\b/' => function () use ($today) {
                $qn = intdiv((int) date('n', $today) - 1, 3);
                $s = strtotime(date('Y', $today) . '-' . sprintf('%02d', $qn * 3 + 1) . '-01 -3 months');
                return [date('Y-m-d', $s), date('Y-m-t', strtotime('+2 months', $s)), 'le trimestre dernier'];
            },
            '/\b(ce trimestre|trimestre en cours)\b/' => function () use ($today) {
                $qn = intdiv((int) date('n', $today) - 1, 3);
                return [date('Y', $today) . '-' . sprintf('%02d', $qn * 3 + 1) . '-01', date('Y-m-d', $today), 'ce trimestre'];
            },
            '/\b(depuis le debut|depuis toujours|tout l historique|toute la periode|de tout temps|historique complet|toutes les dates)\b/' => fn() => ['2000-01-01', $d($today), 'depuis le début'],
        ];
        foreach ($map as $re => $fn) {
            if (preg_match($re, $n)) {
                [$a, $b, $l] = $fn();
                return ['from' => $a, 'to' => $b, 'label' => $l];
            }
        }
        if (preg_match('/\b(?:le |du |au )?(\d{1,2})[\/.-](\d{1,2})(?:[\/.-](\d{2,4}))?\b/', $n) && ($a = self::date($n, $now))) {
            return ['from' => $a, 'to' => $a, 'label' => 'le ' . date('d/m/Y', strtotime($a))];
        }
        if (preg_match('/\b(?:en |annee |de l annee )?(20\d{2})\b/', $n, $m)) {
            $yy = (int) $m[1];
            return ['from' => $yy . '-01-01', 'to' => min($yy . '-12-31', $d($today)), 'label' => 'l\'année ' . $yy];
        }
        return null;
    }

    /** « 2026-09 » → « septembre 2026 ». */
    public static function monthLabelOf(string $ym): string
    {
        [$y, $m] = array_map('intval', explode('-', $ym) + [0, 1]);
        return self::monthLabel(max(1, min(12, $m))) . ' ' . $y;
    }

    public static function monthLabel(int $m): string
    {
        return ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][$m];
    }

    /** Une date isolée : « 15/10 », « 15/10/2026 », « 1er octobre », « 3 mars 2025 », « hier ». */
    public static function date(string $s, int $now, ?string $ref = null): ?string
    {
        $s = trim(self::norm($s));
        $y = $ref ? (int) substr($ref, 0, 4) : (int) date('Y', $now);
        if (preg_match('/^(?:le )?(\d{1,2})[\/.-](\d{1,2})(?:[\/.-](\d{2,4}))?/', $s, $m) || preg_match('/\b(\d{1,2})[\/.-](\d{1,2})(?:[\/.-](\d{2,4}))?\b/', $s, $m)) {
            $yy = isset($m[3]) ? ((int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3]) : $y;
            return checkdate((int) $m[2], (int) $m[1], $yy) ? sprintf('%04d-%02d-%02d', $yy, $m[2], $m[1]) : null;
        }
        if (preg_match('/^(?:le )?(\d{1,2})(?:er|e)?\s+([a-z]+)(?:\s+(\d{4}))?/', $s, $m) && isset(self::MONTHS[$m[2]])) {
            $yy = isset($m[3]) ? (int) $m[3] : $y;
            return checkdate(self::MONTHS[$m[2]], (int) $m[1], $yy) ? sprintf('%04d-%02d-%02d', $yy, self::MONTHS[$m[2]], $m[1]) : null;
        }
        if (preg_match('/^(?:le )?(\d{1,2})(?:er|e)?$/', $s, $m) && $ref) {
            $mm = (int) substr($ref, 5, 2);
            return checkdate($mm, (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $mm, $m[1]) : null;
        }
        if (preg_match('/^([a-z]+)(?:\s+(\d{4}))?$/', $s, $m) && isset(self::MONTHS[$m[1]])) {
            return sprintf('%04d-%02d-01', isset($m[2]) ? (int) $m[2] : $y, self::MONTHS[$m[1]]);
        }
        $today = strtotime(date('Y-m-d', $now));
        return match ($s) { 'aujourd hui', 'aujourdhui' => date('Y-m-d', $today), 'hier' => date('Y-m-d', $today - 86400), default => null };
    }

    /** Format demandé : pdf, csv (Excel) ou rien. */
    public static function format(string $q): ?string
    {
        $n = self::norm($q);
        if (preg_match('/\b(pdf|imprim\w*|telecharg\w*)\b/', $n)) {
            return 'pdf';
        }
        if (preg_match('/\b(csv|excel|tableur|xls\w*)\b/', $n)) {
            return 'csv';
        }
        return null;
    }
}
