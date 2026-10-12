<?php
declare(strict_types=1);

namespace Verifia\Analysis;

final class TextNormalizer
{
    /** Nettoyage de base : espaces, caractères de contrôle, mentions d'interface (« Voir plus »). */
    public static function clean(string $t): string
    {
        $t = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{200B}-\x{200D}\x{FEFF}]/u', '', $t) ?? '';
        $t = preg_replace('/(…\s*)?(Voir plus|See more|Afficher la suite|Show more)\s*$/u', '', $t) ?? $t;
        $t = preg_replace('/[ \t]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/\n{3,}/u', "\n\n", $t) ?? $t;
        return trim($t);
    }

    /** Forme canonique pour la comparaison : minuscules, sans accents, sans ponctuation. */
    public static function forComparison(string $t): string
    {
        $t = mb_strtolower($t, 'UTF-8');
        $t = preg_replace('#https?://\S+#u', ' ', $t) ?? $t;
        if (class_exists(\Normalizer::class)) {
            $t = \Normalizer::normalize($t, \Normalizer::FORM_D) ?: $t;
            $t = preg_replace('/\p{Mn}+/u', '', $t) ?? $t;
        }
        $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t) ?? $t;
        return trim($t);
    }

    /** @return string[] */
    public static function words(string $t): array
    {
        $n = self::forComparison($t);
        return $n === '' ? [] : explode(' ', $n);
    }

    /** Ensemble de n-grammes de mots (shingles). */
    public static function shingles(string $t, int $n = 3): array
    {
        $w = self::words($t);
        if (count($w) < $n) {
            return $w ? [implode(' ', $w) => true] : [];
        }
        $out = [];
        for ($i = 0, $m = count($w) - $n; $i <= $m; $i++) {
            $out[implode(' ', array_slice($w, $i, $n))] = true;
        }
        return $out;
    }

    public static function jaccard(string $a, string $b, int $n = 3): float
    {
        $sa = self::shingles($a, $n);
        $sb = self::shingles($b, $n);
        if (!$sa || !$sb) {
            return 0.0;
        }
        $inter = count(array_intersect_key($sa, $sb));
        $union = count($sa + $sb);
        return $union ? $inter / $union : 0.0;
    }
}
