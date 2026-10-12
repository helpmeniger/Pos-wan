<?php
declare(strict_types=1);

namespace Verifia\Analysis;

/**
 * Analyse textuelle heuristique et explicable (sans modèle externe).
 * Elle ne décide jamais qu'un contenu est faux : elle relève des indices
 * qui justifient (ou non) une vérification humaine.
 */
final class TextAnalyzer
{
    public const CATEGORY_LABELS = [
        'actualite' => 'Actualité', 'politique' => 'Politique', 'economie' => 'Économie', 'sante' => 'Santé',
        'education' => 'Éducation', 'securite' => 'Sécurité', 'science' => 'Science', 'societe' => 'Société',
        'opinion' => 'Opinion', 'satire' => 'Satire', 'publicite' => 'Publicité', 'rumeur' => 'Rumeur',
        'trompeur_potentiel' => 'Contenu trompeur potentiel', 'non_classe' => 'Information non classée',
    ];

    public function __construct(private ?\DateTimeImmutable $now = null)
    {
        $this->now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function analyze(string $text, array $links = [], bool $knownSatireSite = false): array
    {
        $norm = ' ' . TextNormalizer::forComparison($text) . ' ';
        $indicators = [];

        $flags = [
            'opinion'     => $this->matches($norm, Lexicon::OPINION),
            'satire'      => $knownSatireSite ? ['site satirique connu'] : $this->matches($norm, Lexicon::SATIRE),
            'advertising' => $this->matches($norm, Lexicon::ADVERTISING),
            'rumor'       => $this->matches($norm, Lexicon::RUMOR),
        ];

        // --- Indices de manipulation / sensationnalisme ---
        if ($m = $this->matches($norm, Lexicon::SENSATIONAL)) {
            $indicators[] = $this->ind('sensational', 'Formulations sensationnalistes', min(3, count($m)), implode(', ', $m));
        }
        if ($m = $this->matches($norm, Lexicon::SHARE_CALL)) {
            $indicators[] = $this->ind('share_call', 'Incitation pressante au partage', 2, implode(', ', $m));
        }
        if ($m = $this->matches($norm, Lexicon::CONSPIRACY)) {
            $indicators[] = $this->ind('conspiracy', 'Rhétorique de dissimulation / complot', 2, implode(', ', $m));
        }
        if ($m = $this->matches($norm, Lexicon::MIRACLE)) {
            $indicators[] = $this->ind('miracle', 'Promesse thérapeutique ou miracle', 3, implode(', ', $m));
        }
        $caps = $this->capsRatio($text);
        if ($caps > 0.35 && mb_strlen($text) > 40) {
            $indicators[] = $this->ind('all_caps', 'Usage excessif des majuscules', 1, round($caps * 100) . ' % de lettres majuscules');
        }
        if (preg_match_all('/[!?]{3,}/u', $text, $mm) && count($mm[0]) > 0) {
            $indicators[] = $this->ind('punctuation', 'Ponctuation excessive', 1, implode(' ', array_slice($mm[0], 0, 3)));
        }
        if ($flags['rumor']) {
            $indicators[] = $this->ind('rumor_marker', 'Formulation de rumeur ou information non confirmée', 2, implode(', ', $flags['rumor']));
        }

        // --- Affirmations, dates, lieux, sources ---
        $claims = $this->extractClaims($text);
        $dates = $this->extractDates($text);
        $places = $this->extractPlaces($text);
        $sources = $this->extractSources($text, $links);

        if ($claims && !$sources) {
            $indicators[] = $this->ind('no_source', 'Affirmations factuelles sans source citée', 1, count($claims) . ' affirmation(s)');
        }
        $recency = $this->matches($norm, Lexicon::RECENCY);
        $currentYear = (int) $this->now->format('Y');
        $old = array_filter($dates, fn($d) => $d['year'] !== null && $d['year'] < $currentYear - 1);
        if ($recency && $old) {
            $indicators[] = $this->ind(
                'temporal_inconsistency',
                'Incohérence temporelle : présenté comme récent mais mentionne une date ancienne',
                2,
                implode(', ', $recency) . ' / ' . implode(', ', array_column($old, 'text'))
            );
        }
        $future = array_filter($dates, fn($d) => $d['iso'] !== null && $d['iso'] > $this->now->modify('+2 years')->format('Y-m-d'));
        if ($future) {
            $indicators[] = $this->ind('implausible_date', 'Date improbable (très lointaine dans le futur)', 1, implode(', ', array_column($future, 'text')));
        }

        // --- Classification ---
        [$category, $topic, $scores] = $this->classify($norm);
        if ($flags['satire']) {
            $category = 'satire';
        } elseif ($flags['advertising'] && count($flags['advertising']) >= 2) {
            $category = 'publicite';
        } elseif ($flags['opinion'] && !$claims) {
            $category = 'opinion';
        } elseif ($flags['rumor']) {
            $category = 'rumeur';
        }

        return [
            'category'    => $category,
            'topic'       => $topic,
            'category_scores' => $scores,
            'flags'       => array_map(fn($v) => (bool) $v, $flags),
            'flag_terms'  => $flags,
            'indicators'  => $indicators,
            'claims'      => $claims,
            'dates'       => $dates,
            'places'      => $places,
            'sources'     => $sources,
            'recency_terms' => $recency,
            'language'    => $this->language($norm),
            'word_count'  => count(TextNormalizer::words($text)),
        ];
    }

    private function ind(string $code, string $label, int $weight, string $excerpt = ''): array
    {
        return ['code' => $code, 'label' => $label, 'weight' => $weight, 'excerpt' => mb_substr($excerpt, 0, 300)];
    }

    /** Termes du lexique présents (comparaison sur mots entiers). */
    private function matches(string $norm, array $terms): array
    {
        $found = [];
        foreach ($terms as $t) {
            $tn = TextNormalizer::forComparison($t);
            if ($tn !== '' && str_contains($norm, ' ' . $tn . ' ')) {
                $found[$tn] = $tn;
            }
        }
        return array_values($found);
    }

    private function capsRatio(string $t): float
    {
        $letters = preg_match_all('/\p{L}/u', $t);
        $upper = preg_match_all('/\p{Lu}/u', $t);
        return $letters ? $upper / $letters : 0.0;
    }

    private function classify(string $norm): array
    {
        $scores = [];
        foreach (Lexicon::CATEGORIES as $cat => $terms) {
            $scores[$cat] = count($this->matches($norm, $terms));
        }
        arsort($scores);
        $best = array_key_first($scores);
        if ($scores[$best] === 0) {
            return ['non_classe', null, $scores];
        }
        // L'« actualité » n'est retenue que si aucun thème plus précis n'est détecté.
        if ($best === 'actualite') {
            foreach ($scores as $c => $s) {
                if ($c !== 'actualite' && $s > 0) {
                    $best = $c;
                    break;
                }
            }
        }
        $topic = $this->matches($norm, Lexicon::CATEGORIES[$best])[0] ?? null;
        return [$best, $topic, $scores];
    }

    /** Phrases contenant une affirmation vérifiable : chiffre, date, verbe factuel ou attribution. */
    public function extractClaims(string $text): array
    {
        $sentences = preg_split('/(?<=[.!?…])\s+|\n+/u', $text) ?: [];
        $claims = [];
        foreach ($sentences as $s) {
            $s = trim($s);
            if (mb_strlen($s) < 25 || mb_strlen($s) > 600 || str_ends_with($s, '?')) {
                continue;
            }
            $n = ' ' . TextNormalizer::forComparison($s) . ' ';
            if ($this->matches($n, Lexicon::OPINION)) {
                continue;
            }
            $hasNumber = (bool) preg_match('/\d/u', $s);
            $hasVerb = (bool) $this->matches($n, Lexicon::FACT_VERBS);
            $hasEntity = (bool) preg_match('/(?<!^)(?<![.!?]\s)\b\p{Lu}\p{Ll}{2,}/u', $s);
            $score = ($hasNumber ? 1 : 0) + ($hasVerb ? 1 : 0) + ($hasEntity ? 1 : 0);
            if ($score >= 2 || ($hasVerb && mb_strlen($s) > 40)) {
                $type = $hasNumber && preg_match('/\d+\s*(%|pour cent|morts|tues|blesses|millions?|milliards?|fcfa|francs|dollars|euros)/iu', $s) ? 'statistic' : 'factual';
                $claims[] = ['text' => $s, 'type' => $type];
            }
            if (count($claims) >= 10) {
                break;
            }
        }
        return $claims;
    }

    public function extractDates(string $text): array
    {
        $out = [];
        $norm = TextNormalizer::forComparison($text);
        // 12/03/2021, 12-03-21
        if (preg_match_all('/\b(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2,4})\b/u', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $y = (int) $x[3];
                $y = $y < 100 ? 2000 + $y : $y;
                $out[] = ['text' => $x[0], 'year' => $y, 'iso' => checkdate((int) $x[2], (int) $x[1], $y) ? sprintf('%04d-%02d-%02d', $y, $x[2], $x[1]) : null];
            }
        }
        // 12 mars 2021 / mars 2021
        $months = implode('|', array_keys(Lexicon::MONTHS));
        if (preg_match_all('/\b(?:(\d{1,2})(?:er)?\s+)?(' . $months . ')\s+(\d{4})\b/u', $norm, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $y = (int) $x[3];
                $mo = Lexicon::MONTHS[$x[2]];
                $d = $x[1] !== '' ? (int) $x[1] : 1;
                $out[] = ['text' => $x[0], 'year' => $y, 'iso' => checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null];
            }
        }
        // Année seule « en 2019 »
        if (preg_match_all('/\b(?:en|depuis|in|since|annee)\s+((?:19|20)\d{2})\b/u', $norm, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $out[] = ['text' => $x[0], 'year' => (int) $x[1], 'iso' => null];
            }
        }
        $uniq = [];
        foreach ($out as $d) {
            $uniq[$d['text']] = $d;
        }
        return array_values($uniq);
    }

    /** Lieux probables : nom propre après une préposition de lieu. */
    public function extractPlaces(string $text): array
    {
        $places = [];
        if (preg_match_all('/\b(?:à|au|aux|en|dans|de|vers|près de|in|at)\s+((?:\p{Lu}[\p{L}\'-]+)(?:\s+\p{Lu}[\p{L}\'-]+){0,2})/u', $text, $m)) {
            foreach ($m[1] as $p) {
                if (mb_strlen($p) >= 3 && !in_array(mb_strtolower($p), ['selon', 'le', 'la', 'les'], true)) {
                    $places[$p] = $p;
                }
            }
        }
        return array_values(array_slice($places, 0, 10));
    }

    public function extractSources(string $text, array $links): array
    {
        $sources = [];
        if (preg_match_all('/\b(?:selon|d\'après|d’après|according to|source\s*:)\s+((?:l[ae]s?\s+|l[\'’])?[\p{L}\p{N}\'’ -]{2,60}?)(?=[,.;:\n]|$)/iu', $text, $m)) {
            foreach ($m[1] as $s) {
                $sources[] = ['type' => 'attribution', 'value' => trim($s)];
            }
        }
        if (preg_match_all('#https?://[^\s<>"\']+#u', $text, $m)) {
            foreach ($m[0] as $u) {
                $sources[] = ['type' => 'link', 'value' => rtrim($u, '.,;)')];
            }
        }
        foreach ($links as $l) {
            if (is_string($l) && !in_array($l, array_column($sources, 'value'), true)) {
                $sources[] = ['type' => 'link', 'value' => $l];
            }
        }
        return array_slice($sources, 0, 20);
    }

    private function language(string $norm): string
    {
        $fr = preg_match_all('/ (le|la|les|des|est|une|et|pour|dans|qui|que) /u', $norm);
        $en = preg_match_all('/ (the|and|is|are|for|with|that|this|of) /u', $norm);
        return $fr >= $en ? 'fr' : 'en';
    }
}
