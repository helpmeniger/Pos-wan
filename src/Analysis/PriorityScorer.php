<?php
declare(strict_types=1);

namespace Verifia\Analysis;

/**
 * Combine les indices en un niveau de priorité de VÉRIFICATION.
 * Une priorité élevée ou critique ne signifie pas que la publication est fausse.
 *
 *  low      : contenu informatif ordinaire, aucune affirmation vérifiable notable
 *  normal   : affirmation(s) vérifiable(s) ou indices faibles
 *  high     : score >= 4, ou indice fort (image déjà vue, contradiction potentielle)
 *  critical : au moins 3 indices importants (poids >= 2) et score >= 8
 */
final class PriorityScorer
{
    public const ORDER = ['low' => 0, 'normal' => 1, 'high' => 2, 'critical' => 3];
    public const STRONG = ['image_reuse', 'contradicted_claim', 'similar_contradicted'];

    public static function score(array $indicators, int $claimCount, array $flags): array
    {
        $soft = ['sensational', 'all_caps', 'punctuation', 'share_call'];
        $total = 0;
        $important = 0;
        $strong = false;
        foreach ($indicators as $i) {
            $w = (int) $i['weight'];
            // Opinion et satire : le style n'est pas un indice de tromperie.
            if (($flags['satire'] ?? false) || ($flags['opinion'] ?? false)) {
                if (in_array($i['code'], $soft, true)) {
                    $w = intdiv($w, 2);
                }
            }
            $total += $w;
            if ($w >= 2) {
                $important++;
            }
            if (in_array($i['code'], self::STRONG, true)) {
                $strong = true;
            }
        }

        if ($important >= 3 && $total >= 8) {
            $p = 'critical';
        } elseif ($total >= 4 || $strong) {
            $p = 'high';
        } elseif ($claimCount > 0 || $total >= 2) {
            $p = 'normal';
        } else {
            $p = 'low';
        }
        // Satire déclarée : jamais au-delà de « normale » sauf réutilisation d'image.
        if (($flags['satire'] ?? false) && !$strong && self::ORDER[$p] > self::ORDER['normal']) {
            $p = 'normal';
        }
        return ['priority' => $p, 'score' => $total, 'important' => $important];
    }

    /**
     * Confiance dans l'ANALYSE (et non dans la fausseté du contenu) :
     * augmente avec la quantité de texte et le nombre d'indices concordants.
     */
    public static function confidence(int $wordCount, int $indicatorCount, bool $hasImageEvidence): float
    {
        $c = 0.30;
        $c += min(0.25, $wordCount / 400);
        $c += min(0.30, 0.08 * $indicatorCount);
        $c += $hasImageEvidence ? 0.10 : 0.0;
        return round(min(0.95, $c), 3);
    }

    public static function atLeast(string $p, string $min): bool
    {
        return (self::ORDER[$p] ?? 0) >= (self::ORDER[$min] ?? 2);
    }
}
