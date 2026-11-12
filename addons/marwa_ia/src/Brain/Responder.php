<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

/**
 * MARWA AI NATURAL RESPONSE ENGINE.
 * - Variété : chaque outil fournit plusieurs formulations des MÊMES chiffres ; le choix varie d'un message à l'autre.
 * - Reformulation facultative par le modèle IA (réglage « ai_llm_phrasing ») : le modèle ne reçoit que la phrase déjà
 *   calculée ; sa réponse est rejetée si elle contient un nombre absent des faits (contrôle numberGuard).
 */
final class Responder
{
    public static function pick(array $variants, int $seed): string
    {
        $variants = array_values(array_filter($variants, 'strlen'));
        return $variants ? $variants[$seed % count($variants)] : '';
    }

    /** Nombres d'un texte, normalisés (« 15 450 000 » → 15450000 ; « 18,1 » → 18.1). */
    public static function numbers(string $text): array
    {
        preg_match_all('/\d[\d\x{00A0}\x{202F} ]*(?:[.,]\d+)?/u', $text, $m);
        $out = [];
        foreach ($m[0] as $raw) {
            $v = preg_replace('/[\x{00A0}\x{202F} ]/u', '', $raw);
            $v = str_replace(',', '.', $v);
            if ($v !== '' && is_numeric($v)) {
                $out[] = round((float) $v, 2);
            }
        }
        return $out;
    }

    /** Vrai si chaque nombre du texte proposé figure dans les faits (ou dans la phrase de référence / la question). */
    public static function numberGuard(string $candidate, string $reference, array $facts, string $question = ''): bool
    {
        $allowed = array_merge(self::numbers($reference), self::numbers($question));
        foreach ($facts as $f) {
            if (is_numeric($f)) {
                $allowed[] = round((float) $f, 2);
                $allowed[] = round((float) $f / 1_000_000, 2); // « 15,45 millions »
                $allowed[] = round((float) $f / 1_000, 2);
                $allowed[] = round((float) $f);
            }
        }
        $allowed = array_unique(array_map(fn($x) => (string) $x, $allowed));
        foreach (self::numbers($candidate) as $n) {
            if (!in_array((string) $n, $allowed, true) && !in_array((string) round($n), $allowed, true)) {
                return false;
            }
        }
        return true;
    }

    /** Consigne donnée au modèle pour reformuler une réponse chiffrée. */
    public static function phrasingPrompt(): string
    {
        return 'Tu es MARWA AI, l\'administrateur intelligent du logiciel de gestion POS Marwa (Niger, FCFA). On te donne une réponse déjà calculée à partir de la base de données. '
            . 'Reformule-la en français naturel, professionnel et bref (2 phrases au plus). Garde EXACTEMENT les mêmes nombres, montants, dates et noms ; n\'ajoute aucun chiffre, aucune hypothèse, aucune information absente. '
            . 'Ne commence pas par « Bien sûr » ni par une formule de politesse.';
    }

    /** Consigne pour une réponse d'aide à partir d'extraits de la connaissance de l'application. */
    public static function helpPrompt(): string
    {
        return 'Tu es MARWA AI, l\'administrateur et formateur du logiciel POS Marwa (Niger, FCFA). Réponds en français, clairement et brièvement (au plus 8 phrases ou une liste d\'étapes numérotées). '
            . 'Appuie-toi UNIQUEMENT sur les extraits fournis (menus, procédures, documentation de la version installée). Si la réponse n\'y figure pas, dis-le simplement. N\'invente aucun menu, bouton ni chiffre.';
    }
}
