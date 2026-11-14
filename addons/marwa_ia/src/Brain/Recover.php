<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

use Addons\MarwaIa\Text;
use App\Core\DB;

/**
 * MARWA TYPO & LANGUAGE RECOVERY ENGINE.
 *
 * Remet une question mal écrite dans une forme que le moteur de compréhension sait lire, sans jamais juger l'utilisateur :
 *  1. abréviations et écriture SMS (« cb », « stp », « pk », « auj », « qté »…) ;
 *  2. élisions collées (« largent » → « l argent », « jai » → « j ai », « daffaire » → « d affaire ») ;
 *  3. mots collés (« valeurdustock » → « valeur du stock ») ;
 *  4. fautes de frappe : lettre oubliée, en trop, remplacée ou inversée (distance de Damerau-Levenshtein) ;
 *  5. écriture phonétique (« ojourdui » → « aujourd hui », « produi » → « produit », « stoke » → « stock »).
 * Les mots connus — vocabulaire du métier, mots de la langue, noms d'articles, de clients, d'utilisateurs, de boutiques —
 * ne sont jamais modifiés. Seul le texte donné au moteur change : la question d'origine reste affichée et enregistrée.
 */
final class Recover
{
    /** Abréviations courantes (forme normalisée → remplacement). Chaîne vide : mot de politesse retiré. */
    public const ABBR = [
        'stp' => '', 'svp' => '', 'sil' => '', 'slt' => 'salut', 'bjr' => 'bonjour', 'bsr' => 'bonsoir', 'cc' => 'salut', 'mrc' => 'merci', 'mci' => 'merci',
        'cb' => 'combien', 'cmb' => 'combien', 'cbn' => 'combien', 'combiens' => 'combien', 'bcp' => 'beaucoup', 'pr' => 'pour', 'ds' => 'dans', 'dc' => 'donc', 'tt' => 'tout', 'tte' => 'toute', 'tts' => 'tous',
        'auj' => 'aujourd hui', 'ajd' => 'aujourd hui', 'aujourdhui' => 'aujourd hui', 'ojourdui' => 'aujourd hui', 'aujourdui' => 'aujourd hui', 'ojourdhui' => 'aujourd hui', 'ojd' => 'aujourd hui',
        'o' => 'au', 'fon' => 'fond', 'kun' => 'qu un', 'kune' => 'qu une', 'kil' => 'qu il', 'kon' => 'qu on', 'kel' => 'quel', 'san' => 'sans', 'ide' => 'idee', 'ides' => 'idees', 'ier' => 'hier', 'yer' => 'hier', 'dem' => 'demain', 'qd' => 'quand', 'kan' => 'quand', 'pk' => 'pourquoi', 'pkoi' => 'pourquoi', 'pq' => 'pourquoi', 'pourkoi' => 'pourquoi', 'koi' => 'quoi', 'kwa' => 'quoi',
        'kel' => 'quel', 'kelle' => 'quelle', 'kels' => 'quels', 'ke' => 'que', 'ki' => 'qui', 'jai' => 'j ai', 'jé' => 'j ai', 'ya' => 'il y a',
        'nb' => 'nombre', 'nbr' => 'nombre', 'nbre' => 'nombre', 'qte' => 'quantite', 'qtt' => 'quantite', 'qtte' => 'quantite', 'clt' => 'client', 'clts' => 'clients', 'frs' => 'fournisseur', 'fourn' => 'fournisseur',
        'prod' => 'produit', 'prods' => 'produits', 'art' => 'article', 'arts' => 'articles', 'mag' => 'magasin', 'btq' => 'boutique', 'tkt' => 'ticket', 'tkts' => 'tickets', 'fact' => 'facture',
        'cmd' => 'commande', 'cde' => 'commande', 'cdes' => 'commandes', 'inv' => 'inventaire', 'benef' => 'benefice', 'vte' => 'vente', 'vtes' => 'ventes', 'sem' => 'semaine', 'dispo' => 'disponible',
        'dispos' => 'disponibles', 'min' => 'minimum', 'max' => 'maximum', 'tel' => 'telephone', 'num' => 'numero', 'ref' => 'reference', 'refs' => 'references', 'cat' => 'categorie', 'rdv' => 'rendez vous',
        'msg' => 'message', 'pb' => 'probleme', 'pbs' => 'problemes', 'prblm' => 'probleme', 'mdp' => 'mot de passe', 'tva' => 'tva', 'ttc' => 'ttc', 'ht' => 'ht', 'momo' => 'mobile money', 'mm' => 'mobile money',
        'sa' => 'ca', 'chui' => 'je suis', 'jsuis' => 'je suis', 'jveux' => 'je veux', 'jveu' => 'je veux', 'jvoudrais' => 'je voudrais', 'fo' => 'il faut', 'faut' => 'il faut', 'peu' => 'peux', 'veu' => 'veux',
        'fait' => 'fais', 'fai' => 'fais', 'di' => 'dis', 'done' => 'donne', 'donn' => 'donne', 'montr' => 'montre', 'affich' => 'affiche', 'rajout' => 'rajoute', 'ajout' => 'ajoute', 'met' => 'mets',
    ];

    /** Mots courants de la langue, toujours considérés comme bien écrits (en plus du vocabulaire appris). */
    private const COMMON = 'a ai aie ait au aux avec avoir bien bon bonne ca car ce cela ces cet cette chaque chez ci combien comme comment dans de des deja depuis du elle en encore entre est et etre eu fait faire fais faut '
        . 'fois hier ici il ils j je jour jours l la le les leur lui m ma mais me meme mes moi moins mon n ne ni non nos notre nous on ont ou par pas peu peut peux plus pour pourquoi quand que quel quelle quels quelles '
        . 'qui quoi s sa sans se ses si son sont sous sur t ta te tes toi ton tous tout toute toutes tres tu un une va vers veux voir vos votre vous y aujourd hui demain matin soir midi semaine mois annee an ans '
        . 'dernier derniere derniers dernieres prochain prochaine premier premiere ce cette lundi mardi mercredi jeudi vendredi samedi dimanche janvier fevrier mars avril mai juin juillet aout septembre octobre novembre decembre '
        . 'donne donner montre montrer affiche afficher dis dire voir regarde regarder cherche chercher trouve trouver fais faire prepare preparer ajoute ajouter rajoute mets mettre retire retirer enleve enlever annule annuler '
        . 'ouvre ouvrir ferme fermer compare comparer explique expliquer calcule calculer verifie verifier liste lister sors sortir entre entrer augmente augmenter diminue diminuer '
        . 'salut bonjour bonsoir merci svp oui non ok daccord accord bien mal vite rapidement maintenant actuellement toujours jamais souvent beaucoup peu trop assez environ total totale nombre quantite montant valeur '
        . 'unite unites piece pieces bouteille bouteilles carton cartons sac sacs paquet paquets kilo kilos litre litres francs franc fcfa cfa argent somme prix cout couts '
        . 'trace traces cachette discretement secret secrets instructions regles consignes restrictions droits historique journal audit licence dette dettes credit encours idee idees conseil conseils '
        . 'premier deux trois quatre cinq six sept huit neuf dix vingt trente cent mille million millions marche marcher fonctionne fonctionner probleme problemes erreur erreurs possible besoin';

    /** Tournures fautives fréquentes, corrigées une fois les mots remis en forme. */
    private const PHRASES = [
        '/\b(cet|c|sette|cett|ce) semaine\b/u' => 'cette semaine', '/\b(ce|se|c) moi\b/u' => 'ce mois', '/\bdu moi\b/u' => 'du mois', '/\b(le|les|du|au) moi (dernier|passe|prochain)\b/u' => '$1 mois $2',
        '/\bmoi (dernier|passe)\b/u' => 'mois dernier', '/\b(semaine|annee) (passe|passee|pase|d avant)\b/u' => '$1 derniere', '/\bmois (passe|passee|pase|d avant)\b/u' => 'mois dernier',
        '/\bcet (annee|anne)\b/u' => 'cette annee', '/\b(es|est) ke\b/u' => 'est ce que', '/\bkes ki\b/u' => 'qu est ce qui', '/\bkes ke\b/u' => 'qu est ce que', '/\bya til\b/u' => 'y a t il',
        '/\bc quoi\b/u' => 'c est quoi', '/\bc combien\b/u' => 'c est combien',
    ];

    private static ?array $known = null;
    private static ?array $phon = null;
    private static ?array $entity = null;
    private static array $memo = [];

    public static function reset(): void
    {
        self::$known = self::$phon = self::$entity = null;
        self::$memo = [];
    }

    /** Mots des noms propres de la base (articles, clients, fournisseurs, utilisateurs, boutiques, magasins, catégories). */
    private static function entityWords(): array
    {
        if (self::$entity !== null) {
            return self::$entity;
        }
        $w = [];
        try {
            foreach (['SELECT name FROM products WHERE status = \'active\' LIMIT 20000', 'SELECT name FROM customers LIMIT 20000', 'SELECT name FROM suppliers LIMIT 5000', 'SELECT full_name FROM users',
                'SELECT username FROM users', 'SELECT name FROM stores', 'SELECT name FROM warehouses', 'SELECT name FROM categories', 'SELECT name FROM cash_registers'] as $sql) {
                foreach (DB::all($sql) as $r) {
                    foreach (explode(' ', Text::norm((string) reset($r))) as $t) {
                        if (strlen($t) >= 3 && !ctype_digit($t)) {
                            $w[$t] = true;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // base indisponible : on corrige sans les noms propres
        }
        return self::$entity = $w;
    }

    private static function known(): array
    {
        if (self::$known !== null) {
            return self::$known;
        }
        $k = Nlu::vocabulary();
        foreach (explode(' ', self::COMMON) as $w) {
            $k[$w] = true;
        }
        foreach (self::ABBR as $v) {
            foreach (explode(' ', $v) as $w) {
                if ($w !== '') {
                    $k[$w] = true;
                }
            }
        }
        $k += self::entityWords();
        self::$known = $k;
        self::$phon = [];
        foreach ($k as $w => $_) {
            $w = (string) $w;
            if (strlen($w) >= 4) {
                self::$phon[self::phonetic($w)] ??= $w;
            }
        }
        return self::$known;
    }

    /** Clé phonétique française simplifiée : deux mots qui se prononcent presque pareil ont la même clé. */
    public static function phonetic(string $w): string
    {
        $w = Text::norm($w);
        $w = preg_replace('/(.)\1+/', '$1', $w);                       // lettres doublées
        $w = strtr($w, ['eau' => 'o', 'au' => 'o', 'ph' => 'f', 'qu' => 'k', 'gu' => 'g', 'ck' => 'k', 'ch' => 'x', 'oeu' => 'e', 'eu' => 'e', 'ai' => 'e', 'ei' => 'e', 'ou' => 'u', 'oi' => 'wa', 'y' => 'i']);
        $w = preg_replace(['/c(?=[ei])/', '/c/', '/^h/', '/(?<=[a-z])h/', '/(ain|ein|aim|in|im)(?=[^aeiou]|$)/', '/(an|am|en|em)(?=[^aeiou]|$)/', '/(on|om)(?=[^aeiou]|$)/', '/g(?=[ei])/', '/z/', '/x$/'],
            ['s', 'k', '', '', 'I', 'A', 'O', 'j', 's', ''], $w);
        $w = preg_replace('/(er|ez|et|es|ent|e|s|t|d|p)$/', '', $w) ?: $w;   // finales muettes
        $w = preg_replace('/(.)\1+/', '$1', $w);
        return $w;
    }

    /** Distance d'édition avec inversion de deux lettres voisines comptée pour une seule faute. */
    public static function distance(string $a, string $b): int
    {
        $la = strlen($a);
        $lb = strlen($b);
        if (abs($la - $lb) > 2) {
            return 9;
        }
        $d = [];
        for ($i = 0; $i <= $la; $i++) {
            $d[$i] = [$i];
        }
        for ($j = 1; $j <= $lb; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $la; $i++) {
            for ($j = 1; $j <= $lb; $j++) {
                $c = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $c);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }
        return $d[$la][$lb];
    }

    /** Découpe un mot collé en mots connus (programmation dynamique, le moins de morceaux possible). */
    private static function segment(string $w): ?string
    {
        $n = strlen($w);
        if ($n < 7 || $n > 40) {
            return null;
        }
        $k = self::known();
        $best = array_fill(0, $n + 1, null);
        $best[0] = [];
        for ($i = 0; $i < $n; $i++) {
            if ($best[$i] === null) {
                continue;
            }
            for ($len = 1; $len <= min(20, $n - $i); $len++) {
                $part = substr($w, $i, $len);
                $ok = isset($k[$part]) && ($len >= 3 || in_array($part, ['de', 'du', 'la', 'le', 'un', 'et', 'en', 'au', 'ce', 'ma', 'je', 'il', 'a', 'l', 'd', 'j'], true));
                if ($ok && ($best[$i + $len] === null || count($best[$i]) + 1 < count($best[$i + $len]))) {
                    $best[$i + $len] = array_merge($best[$i], [$part]);
                }
            }
        }
        $r = $best[$n];
        if ($r === null || count($r) < 2 || count($r) > 5) {
            return null;
        }
        // Au moins un vrai mot (4 lettres ou plus) : évite de découper un nom propre en petits mots
        return count(array_filter($r, fn($p) => strlen($p) >= 4)) >= 1 ? implode(' ', $r) : null;
    }

    /** Corrige un mot (forme normalisée). Renvoie le mot tel quel s'il est connu ou si rien de sûr n'est trouvé. */
    public static function word(string $w): string
    {
        if ($w === '' || ctype_digit($w) || preg_match('/\d/', $w)) {
            return $w;
        }
        if (isset(self::$memo[$w])) {
            return self::$memo[$w];
        }
        $k = self::known();
        $out = $w;
        if (array_key_exists($w, self::ABBR) && (!isset($k[$w]) || in_array($w, ['o', 'sa', 'fait', 'faut', 'met', 'peu', 'ya', 'jai', 'min', 'max', 'art', 'cat'], true))) {
            $out = self::ABBR[$w];
        } elseif (isset($k[$w]) || strlen($w) < 3) {
            $out = $w;
        } else {
            // Deux lectures possibles d'un mot inconnu : une faute dans le mot (« coment » → « comment »),
            // ou une élision collée (« dier » → « d hier », « lanné » → « l année »). On garde la plus plausible.
            [$direct, $ds] = self::fuzzy($w);
            $eli = null;
            $es = 999;
            if (preg_match('/^(l|d|j|qu)([aeiouyh][a-z]{2,})$/', $w, $m)) {
                $rest = $m[2];
                if (array_key_exists($rest, self::ABBR) && self::ABBR[$rest] !== '') {
                    $eli = $m[1] . ' ' . self::ABBR[$rest];
                    $es = 1;
                } elseif (isset($k[$rest]) && strlen($rest) >= 4) {
                    $eli = $m[1] . ' ' . $rest;
                    $es = 2;
                } else {
                    [$rf, $rs] = self::fuzzy($rest);
                    if ($rf !== null) {
                        $eli = $m[1] . ' ' . $rf;
                        $es = $rs + 2; // une élision collée ET une faute : seulement si c'est nettement plus plausible
                    }
                }
            }
            if ($eli !== null && $es < $ds) {
                $out = $eli;
            } elseif ($direct !== null) {
                $out = $direct;
            } elseif (($sg = self::segment($w)) !== null) {
                $out = $sg;
            }
        }
        return self::$memo[$w] = $out;
    }

    /** Vrai si toutes les lettres de $a se retrouvent dans $b dans le même ordre (lettre(s) oubliée(s)). */
    private static function subsequence(string $a, string $b): bool
    {
        $i = 0;
        $la = strlen($a);
        for ($j = 0, $lb = strlen($b); $j < $lb && $i < $la; $j++) {
            if ($a[$i] === $b[$j]) {
                $i++;
            }
        }
        return $i === $la;
    }

    /** Mot connu le plus proche (frappe puis phonétique) et son score (plus petit = plus sûr) ; [null, 999] si aucun. */
    private static function fuzzy(string $w): array
    {
        $k = self::known();
        $len = strlen($w);
        if ($len < 4) {
            return [null, 999];
        }
        $max = $len >= 8 ? 2 : 1;
        $ent = self::entityWords();
        $lang = Nlu::vocabulary();
        $best = null;
        $bs = 999;
        foreach ($k as $v => $_) {
            $v = (string) $v;
            $lv = strlen($v);
            if ($lv < 4 || abs($lv - $len) > $max) {
                continue;
            }
            if ($v[0] !== $w[0] && !($len >= 6 && substr($v, 1, 3) === substr($w, 1, 3))) {
                continue; // la première lettre est presque toujours juste
            }
            $d = self::distance($w, $v);
            if ($d > $max) {
                continue;
            }
            // À distance égale : une lettre oubliée (« coment » → « comment ») et un mot de la langue ou du métier
            // passent avant un remplacement de lettre et avant un nom propre de la base.
            $score = $d * 10 - (self::subsequence($w, $v) ? 3 : 0) - (isset($lang[$v]) || !isset($ent[$v]) ? 2 : 0) + ($v[$lv - 1] === $w[$len - 1] ? 0 : 1);
            if ($score < $bs) {
                $bs = $score;
                $best = $v;
            }
        }
        if ($best !== null) {
            return [$best, $bs];
        }
        $ph = self::$phon[self::phonetic($w)] ?? null;
        return [$ph, $ph !== null ? 4 : 999];
    }

    /**
     * Question remise en forme pour le moteur (minuscules, sans accents, ponctuation « ? ; , » conservée).
     * @return array{text: string, changes: array<string,string>} texte corrigé et corrections faites (mot → correction)
     */
    public static function clean(string $question): array
    {
        $changes = [];
        $s = mb_strtolower($question);
        $s = str_replace(['’', '`', '´'], "'", $s);
        $s = preg_replace('/(\d)[\s\x{00A0}\x{202F}](?=\d{3}\b)/u', '$1', $s);        // « 50 000 » → 50000
        $s = preg_replace('/(?<=\d)(f|fcfa|cfa|frs|francs?)\b/u', ' $1', $s);          // « 5000f » → « 5000 f »
        $s = preg_replace('/(?<=[a-zà-ÿ])(?=\d)|(?<=\d)(?=[a-zà-ÿ]{3,})/u', ' ', $s); // « eau50 », « 50bouteilles »
        $out = preg_replace_callback('/[\p{L}]+/u', function ($m) use (&$changes) {
            $n = Text::norm($m[0]);
            if ($n === '') {
                return $m[0];
            }
            $c = self::word($n);
            if ($c !== $n) {
                $changes[$m[0]] = $c;
            }
            return $c;
        }, $s);
        $out = trim(preg_replace('/\s+/', ' ', (string) $out));
        foreach (self::PHRASES as $re => $to) {
            $out = preg_replace($re, $to, $out);
        }
        return ['text' => $out, 'changes' => $changes];
    }
}
