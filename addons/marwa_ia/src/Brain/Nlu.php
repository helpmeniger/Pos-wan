<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

use Addons\MarwaIa\Text;
use App\Core\DB;

/**
 * MARWA NATURAL LANGUAGE UNDERSTANDING ENGINE.
 *
 * 1. Normalisation (accents, ponctuation), correction des fautes de frappe sur le vocabulaire du métier.
 * 2. Mise en forme canonique : racines + synonymes (« marchandise » → stock, « émis » → généré, « CA » → chiffre…).
 * 3. Classification par similarité avec une bibliothèque d'exemples (intégrés + validés par l'administrateur, table
 *    ai_intent_examples) : coefficient de Dice pondéré par la rareté des mots + similarité de trigrammes de caractères.
 * 4. Paramètres : période (une ou deux), boutique / magasin, article, client, vendeur, nombre, mode de tri.
 * 5. Découpage des questions contenant plusieurs demandes.
 * Le contexte de conversation (« et hier ? ») est résolu par Brain avec l'état mémorisé.
 */
final class Nlu
{
    public const THRESHOLD = 0.44;

    /** Exemples intégrés (français professionnel, familier, abrégé). */
    public const EXAMPLES = [
        'stock_value' => ['valeur du stock', 'donne moi la valeur du stock', 'quelle est la valeur totale de ma marchandise', 'combien valent les articles actuellement en magasin', 'mon stock represente combien d argent',
            'combien j ai de stock', 'combien vaut mon stock', 'valeur de la marchandise', 'estimation du stock', 'mon inventaire vaut combien', 'combien d argent dort dans le stock'],
        'stock_value_sale' => ['valeur du stock au prix de vente', 'combien je gagne si je vends tout le stock', 'stock au prix de vente', 'valeur de vente du stock'],
        'stock_by_location' => ['valeur du stock par boutique', 'stock par magasin', 'repartition du stock par boutique', 'stock de chaque boutique', 'valeur du stock de chaque magasin'],
        'stock_count' => ['combien d articles sont disponibles', 'nombre d articles en stock', 'combien de produits en stock', 'combien de references disponibles', 'quantite totale en stock'],
        'stock_out' => ['quels sont les produits en rupture', 'articles en rupture', 'ruptures de stock', 'produits epuises', 'quels articles sont finis', 'ce qui manque en stock', 'produits a zero'],
        'stock_low' => ['quels produits ont un stock faible', 'stock faible', 'articles sous le seuil', 'alertes de stock', 'produits presque finis', 'stock bas'],
        'product_stock' => ['combien il reste de riz', 'stock de l article', 'quantite restante du produit', 'combien de sucre en stock', 'il reste combien de cartons'],
        'dormant' => ['quels produits n ont pas ete vendus depuis 30 jours', 'articles qui ne se vendent pas', 'produits invendus', 'stock dormant', 'articles sans vente depuis un mois'],
        'reorder' => ['quels produits dois je recommander', 'que dois je commander', 'liste de reapprovisionnement', 'quoi racheter', 'produits a commander chez le fournisseur'],
        'expiring' => ['produits qui perime bientot', 'lots perimes', 'dates de peremption proches', 'produits expires'],
        'transfers' => ['transferts de stock', 'derniers transferts entre magasins', 'combien de transferts', 'mouvements entre depots'],
        'adjustments' => ['ajustements de stock', 'pertes de stock', 'casse et pertes', 'corrections de stock'],
        'inventories' => ['inventaires en cours', 'dernier inventaire', 'etat des inventaires'],
        'sales_summary' => ['combien ai je vendu aujourd hui', 'combien de ventes aujourd hui', 'ventes du jour', 'combien de ventes ont ete realisees hier', 'combien de tickets ont ete vendus aujourd hui',
            'nombre de tickets generes aujourd hui', 'combien de tickets ont ete crees', 'combien de tickets emis', 'le total des tickets produits', 'nombre de tickets sortis', 'j ai vendu combien', 'montant des ventes', 'combien j ai encaisse'],
        'sales_compare' => ['compare les ventes de cette semaine avec la semaine derniere', 'fais la comparaison des ventes', 'evolution des ventes par rapport au mois dernier', 'ventes en hausse ou en baisse', 'comparaison des ventes'],
        'revenue' => ['quel est le chiffre d affaires de ce mois', 'chiffre d affaires mensuel', 'ca du mois', 'chiffre d affaires', 'quel est mon ca', 'recette du mois'],
        'best_day' => ['quelle est la meilleure journee de vente', 'meilleur jour de vente', 'jour record', 'quel jour j ai le plus vendu'],
        'top_products' => ['quels sont les cinq produits les plus vendus', 'meilleures ventes', 'top des articles', 'produits les plus vendus', 'ce qui se vend le mieux', 'articles vedettes'],
        'most_profitable' => ['quels sont les produits les plus rentables', 'articles qui rapportent le plus', 'meilleure marge par produit', 'produits les plus rentables'],
        'product_growth' => ['quels produits ont augmente en vente', 'articles en hausse', 'produits dont les ventes progressent'],
        'sales_by_user' => ['donne moi les ventes par utilisateur', 'ventes par caissier', 'ventes par vendeur', 'chiffre par employe'],
        'top_cashier' => ['quel caissier a realise le plus de ventes', 'meilleur vendeur', 'qui a le plus vendu', 'meilleur caissier'],
        'sales_by_store' => ['ventes par boutique', 'chiffre de chaque boutique', 'ventes de chaque magasin'],
        'compare_stores' => ['compare les performances de mes boutiques', 'quel magasin realise le meilleur chiffre d affaires', 'quelle boutique vend le plus', 'classement des boutiques'],
        'sales_by_method' => ['ventes par mode de paiement', 'repartition especes mobile money carte', 'combien en mobile money', 'par moyen de paiement'],
        'sales_by_hour' => ['heure de pointe', 'a quelle heure je vends le plus', 'affluence par heure'],
        'sales_by_category' => ['ventes par categorie', 'ventes par rayon', 'quelle categorie vend le plus'],
        'last_sale' => ['quelle est la date et l heure du dernier ticket', 'dernier ticket cree', 'derniere vente', 'qui a fait la derniere vente', 'quel utilisateur a genere le dernier ticket', 'a quelle heure la derniere vente'],
        'last_sales' => ['donne moi les derniers tickets generes', 'dernieres ventes', 'liste des derniers tickets', 'les 10 dernieres ventes'],
        'cancelled_sales' => ['ventes annulees', 'combien de ventes annulees', 'tickets annules', 'annulations'],
        'returns' => ['retours clients', 'remboursements clients', 'combien de retours', 'articles retournes'],
        'discounts_tax' => ['remises et tva du mois', 'tva et remises', 'total des remises', 'combien de tva', 'tva collectee', 'montant brut et net', 'remises accordees'],
        'sales_decline' => ['pourquoi mes ventes ont elles diminue cette semaine', 'pourquoi les ventes baissent', 'explique la baisse des ventes', 'pourquoi je vends moins'],
        'cash_balance' => ['quel est le solde actuel de la caisse', 'combien il y a dans la caisse', 'argent en caisse', 'solde de caisse', 'especes dans le tiroir'],
        'cash_in' => ['combien d argent est entre aujourd hui', 'entrees de caisse', 'encaissements du jour', 'argent entre'],
        'cash_out' => ['combien d argent est sorti', 'sorties de caisse', 'decaissements', 'argent sorti de la caisse'],
        'cash_difference' => ['quelle est la difference entre le solde theorique et le solde reel', 'ecart de caisse', 'manquant en caisse', 'ecarts aux clotures'],
        'last_cash_out' => ['qui a effectue la derniere sortie de caisse', 'derniere sortie de caisse', 'qui a sorti de l argent', 'dernier retrait de caisse'],
        'customers_count' => ['combien de clients sont enregistres', 'nombre de clients', 'combien j ai de clients'],
        'customer_debts' => ['quels clients ont des dettes', 'qui me doit de l argent', 'montant total des creances', 'clients debiteurs', 'dettes clients', 'credits clients en cours'],
        'top_customer' => ['quel client a realise le plus d achats', 'meilleur client', 'meilleurs clients', 'qui achete le plus'],
        'supplier_payables' => ['quels fournisseurs doivent etre payes', 'dettes fournisseurs', 'combien je dois aux fournisseurs', 'fournisseurs a payer'],
        'purchases_total' => ['quel est le montant des achats', 'total des achats', 'combien j ai achete', 'achats du mois', 'receptions de marchandise'],
        'purchase_orders' => ['commandes fournisseurs en cours', 'bons de commande en attente', 'commandes non recues'],
        'profit' => ['quel est le benefice estime', 'combien j ai gagne', 'benefice du mois', 'marge du mois', 'est ce que je gagne de l argent', 'resultat du mois'],
        'expenses_total' => ['combien ai je depense cette semaine', 'total des depenses', 'depenses du mois', 'mes charges', 'combien de frais'],
        'expenses_trend' => ['analyse les depenses des trois derniers mois', 'evolution des depenses', 'depenses sur trois mois'],
        'income_vs_expenses' => ['recettes et depenses du mois', 'mes recettes et mes depenses', 'compare les recettes et les depenses', 'recettes contre depenses', 'entrees et sorties d argent du mois'],
        'daily_report' => ['fais moi un bilan de la journee', 'bilan du jour', 'resume de la journee', 'point de la journee', 'comment s est passee la journee'],
        'activity_summary' => ['fais moi un resume complet de mon activite', 'bilan du mois', 'situation de mon entreprise', 'comment va mon commerce', 'resume de l activite'],
        'anomalies' => ['identifie les anomalies dans les transactions', 'anomalies', 'operations suspectes', 'transactions inhabituelles', 'y a t il des fraudes', 'controle des operations'],
        'last_transactions' => ['donne moi les dernieres transactions', 'dernieres operations', 'derniers mouvements', 'activite recente'],
        'epay_summary' => ['paiements electroniques', 'transactions marwa pay', 'paiements par carte du mois', 'combien de paiements mobiles'],
        'fiscal_summary' => ['certifications fiscales', 'factures certifiees', 'factures en attente de certification', 'fiscal connect'],
        'users_count' => ['combien d utilisateurs', 'liste des employes du logiciel', 'nombre de comptes utilisateurs'],
        'whoami' => ['qui suis je', 'quels sont mes droits', 'mon profil', 'a quoi j ai acces', 'quel est mon role'],
        'wifi_tickets' => ['combien de tickets wifi ont ete generes', 'tickets wifi disponibles', 'combien de tickets sont encore disponibles', 'combien de tickets ont ete utilises', 'combien de tickets sont expires', 'tickets marwa connect', 'tickets internet vendus'],
    ];

    /** Synonymes → forme canonique (appliqués après racinisation). */
    public const SYN = [
        'marchandis' => 'stock', 'inventair' => 'stock', 'magasin' => 'magasin', 'boutiqu' => 'boutique',
        'valent' => 'valeur', 'vaut' => 'valeur', 'valoir' => 'valeur', 'represent' => 'valeur', 'estim' => 'valeur', 'argent' => 'argent', 'sous' => 'argent', 'fric' => 'argent',
        'ticket' => 'vente', 'factur' => 'vente', 'vendu' => 'vente', 'vend' => 'vente', 'vendr' => 'vente', 'vent' => 'vente', 'transaction' => 'operation', 'operation' => 'operation',
        'gener' => 'genere', 'cre' => 'genere', 'cree' => 'genere', 'emi' => 'genere', 'emis' => 'genere', 'sorti' => 'sortie', 'produit' => 'article', 'reference' => 'article', 'articl' => 'article',
        'epuis' => 'rupture', 'fini' => 'rupture', 'manqu' => 'rupture', 'ca' => 'chiffre', 'recett' => 'recette', 'encaiss' => 'entree', 'decaiss' => 'sortie', 'retrait' => 'sortie',
        'benefic' => 'benefice', 'gagn' => 'benefice', 'margin' => 'marge', 'profit' => 'benefice', 'depens' => 'depense', 'charg' => 'depense', 'frai' => 'depense',
        'caissier' => 'vendeur', 'employ' => 'vendeur', 'utilisateur' => 'vendeur', 'vendeur' => 'vendeur', 'dett' => 'dette', 'creanc' => 'dette', 'doiv' => 'dette', 'doit' => 'dette', 'doi' => 'dette', 'largent' => 'argent',
        'combien' => 'combien', 'quantit' => 'combien', 'nombr' => 'combien', 'wifi' => 'wifi', 'internet' => 'wifi', 'hotspot' => 'wifi', 'connect' => 'wifi',
        'rentabl' => 'rentable', 'rapport' => 'rentable', 'hausse' => 'augment', 'augment' => 'augment', 'progress' => 'augment', 'baiss' => 'diminu', 'diminu' => 'diminu', 'chut' => 'diminu',
        'anomali' => 'anomalie', 'suspect' => 'anomalie', 'fraud' => 'anomalie', 'inhabituel' => 'anomalie', 'bilan' => 'bilan', 'resum' => 'bilan', 'synthes' => 'bilan',
        'compar' => 'compare', 'evolu' => 'compare', 'versu' => 'compare', 'record' => 'meilleur', 'mieux' => 'meilleur', 'top' => 'meilleur', 'meilleur' => 'meilleur',
    ];

    /** Mots vides propres à l'analyse d'intention (on garde « combien », « dernier », « meilleur », « plus »…). */
    private const STOP = ['le', 'la', 'les', 'l', 'un', 'une', 'des', 'de', 'du', 'd', 'au', 'aux', 'a', 'et', 'ou', 'en', 'pour', 'sur', 'avec', 'moi', 'me', 'm', 'mon', 'ma', 'mes', 'je', 'j', 'tu', 'te', 't',
        'ton', 'ta', 'tes', 'il', 'elle', 'on', 'nous', 'vous', 'ils', 'se', 's', 'ce', 'cet', 'cette', 'ces', 'ca', 'cela', 'qui', 'que', 'qu', 'est', 'sont', 'etre', 'ai', 'as', 'avoir', 'svp', 'stp', 'merci',
        'donne', 'donner', 'donnez', 'montre', 'montrer', 'affiche', 'afficher', 'veux', 'voudrais', 'peux', 'pouvez', 'dis', 'dire', 'fais', 'faire', 'fait', 'y', 'ne', 'pas', 'dans', 'chez', 'mais', 'donc', 'si',
        'bonjour', 'salut', 'quel', 'quelle', 'quels', 'quelles', 'actuellement', 'actuel', 'actuelle', 'total', 'totale', 'ont', 'ete', 'été', 'eu', 'sil', 'plait', 'please', 'marwa', 'ia', 'pos'];

    private const MONTHS = ['janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin', 'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre', 'janv', 'fev', 'sept', 'oct', 'nov', 'dec'];

    private static ?array $library = null;
    private static ?array $idf = null;
    private static ?array $vocab = null;

    // ------------------------------------------------------------------ mise en forme

    /** Corrige les fautes de frappe d'après le vocabulaire des exemples. */
    public static function correct(string $norm): string
    {
        self::load();
        $out = [];
        foreach (explode(' ', $norm) as $w) {
            if ($w === '' || isset(self::$vocab[$w]) || strlen($w) < 4 || ctype_digit($w)) {
                $out[] = $w;
                continue;
            }
            $best = null;
            $bd = 9;
            foreach (self::$vocab as $v => $_) {
                $v = (string) $v;
                if (abs(strlen($v) - strlen($w)) <= 2 && $v[0] === $w[0]) {
                    $d = levenshtein($w, $v);
                    if ($d < $bd) {
                        $bd = $d;
                        $best = $v;
                    }
                }
            }
            $out[] = $best !== null && $bd <= (strlen($w) >= 7 ? 2 : 1) ? $best : $w;
        }
        return implode(' ', $out);
    }

    /** Jetons canoniques d'un texte normalisé. */
    public static function tokens(string $norm): array
    {
        $t = [];
        foreach (explode(' ', $norm) as $w) {
            // Les dates (« 1er », « 2001 », « janvier ») servent à la période, pas à l'intention
            if ($w === '' || in_array($w, self::STOP, true) || preg_match('/^\d+(er|e|eme)?$/', $w) || in_array($w, self::MONTHS, true)) {
                continue;
            }
            $st = Text::stem($w);
            $c = self::SYN[$w] ?? self::SYN[$st] ?? null;
            if ($c === null) {
                foreach (self::SYN as $k => $v) {
                    if (strlen($k) >= 4 && str_starts_with($st, $k)) {
                        $c = $v;
                        break;
                    }
                }
            }
            $t[] = $c ?? $st;
        }
        return array_values(array_unique($t));
    }

    private static function trigrams(string $s): array
    {
        $s = '  ' . $s . ' ';
        $g = [];
        for ($i = 0, $l = strlen($s) - 2; $i < $l; $i++) {
            $g[substr($s, $i, 3)] = true;
        }
        return $g;
    }

    /** Bibliothèque (exemples intégrés + exemples validés en base), pondérations et vocabulaire. */
    private static function load(): void
    {
        if (self::$library !== null) {
            return;
        }
        $ex = self::EXAMPLES;
        try {
            foreach (DB::all("SELECT e.intent_code, e.normalized FROM ai_intent_examples e LEFT JOIN ai_intents i ON i.code = e.intent_code WHERE e.source <> 'builtin' AND (i.active IS NULL OR i.active = 1)") as $r) {
                $ex[$r['intent_code']][] = $r['normalized'];
            }
        } catch (\Throwable) {
        }
        self::$vocab = [];
        foreach ($ex as $list) {
            foreach ($list as $e) {
                foreach (explode(' ', Text::norm($e)) as $w) {
                    if ($w !== '') {
                        self::$vocab[$w] = true;
                    }
                }
            }
        }
        foreach (array_keys(self::SYN) as $k) {
            self::$vocab[$k] = true;
        }
        $docs = 0;
        $df = [];
        $lib = [];
        foreach ($ex as $intent => $list) {
            foreach ($list as $e) {
                $n = Text::norm($e);
                $tok = self::tokens($n);
                $lib[] = [$intent, $tok, self::trigrams($n)];
                $docs++;
                foreach ($tok as $t) {
                    $df[$t] = ($df[$t] ?? 0) + 1;
                }
            }
        }
        self::$idf = array_map(fn($d) => log(1 + $docs / $d), $df);
        self::$library = $lib;
    }

    public static function reset(): void
    {
        self::$library = self::$idf = self::$vocab = null;
    }

    private static function w(string $t): float
    {
        return self::$idf[$t] ?? 2.5; // mot absent des exemples : poids modéré
    }

    /** @return array{intent: ?string, score: float, second: ?string, second_score: float, tokens: string[]} */
    public static function classify(string $question): array
    {
        self::load();
        $norm = self::correct(Text::norm($question));
        $q = self::tokens($norm);
        $qg = self::trigrams($norm);
        $best = [];
        $wq = array_sum(array_map([self::class, 'w'], $q));
        foreach (self::$library as [$intent, $tok, $grams]) {
            $inter = array_intersect($q, $tok);
            $wi = array_sum(array_map([self::class, 'w'], $inter));
            $we = array_sum(array_map([self::class, 'w'], $tok));
            $dice = ($wq + $we) > 0 ? 2 * $wi / ($wq + $we) : 0;
            $common = count(array_intersect_key($qg, $grams));
            $tri = $common / max(1, sqrt(count($qg) * count($grams)));
            $s = 0.75 * $dice + 0.25 * $tri;
            if ($s > ($best[$intent] ?? 0)) {
                $best[$intent] = $s;
            }
        }
        arsort($best);
        $keys = array_keys($best);
        return ['intent' => $keys[0] ?? null, 'score' => round((float) ($best[$keys[0] ?? ''] ?? 0), 3), 'second' => $keys[1] ?? null,
            'second_score' => round((float) ($best[$keys[1] ?? ''] ?? 0), 3), 'tokens' => $q, 'norm' => $norm];
    }

    // ------------------------------------------------------------------ paramètres

    /** Extraction des paramètres de la question. */
    public static function slots(string $question, UserContext $u): array
    {
        $n = Text::norm($question);
        $s = [];
        // Deux périodes (« cette semaine avec la semaine dernière »)
        $parts = preg_split('/\s+(?:avec|par rapport a|par rapport au|vs|versus|contre|comparee? a|et)\s+(?=(?:la|le|l|les|cette|ce|hier|avant hier|aujourd hui|semaine|mois|annee|janvier|fevrier|mars|avril|mai|juin|juillet|aout|septembre|octobre|novembre|decembre|du|depuis)\b)/', $n);
        if (count($parts) >= 2) {
            $p1 = Text::period($parts[0]);
            $p2 = Text::period(implode(' ', array_slice($parts, 1)));
            if ($p1 && $p2 && $p1 !== $p2) {
                $s['period'] = $p1;
                $s['period2'] = $p2;
            }
        }
        if (empty($s['period']) && ($p = Text::period($question))) {
            $s['period'] = $p;
        }
        // Année explicite (« ventes de l'année 1999 », « en 2025 ») : jamais confondue avec l'année en cours
        if (preg_match('/\b((?:19|20)\d{2})\b/', $n, $y) && (empty($s['period']) || substr((string) $s['period']['from'], 0, 4) !== $y[1])) {
            $s['period'] = ['from' => $y[1] . '-01-01', 'to' => $y[1] . '-12-31', 'label' => 'en ' . $y[1]];
            unset($s['period2']);
        }
        // Nombre (« les 5 produits », « top 10 », « cinq »)
        $nums = ['deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6, 'sept' => 7, 'huit' => 8, 'dix' => 10, 'quinze' => 15, 'vingt' => 20];
        if (preg_match('/\b(?:top|les|des|premiers?|meilleurs?)\s+(\d{1,2}|' . implode('|', array_keys($nums)) . ')\b/', $n, $m)) {
            $s['limit'] = ctype_digit($m[1]) ? (int) $m[1] : $nums[$m[1]];
        }
        if (preg_match('/\b(\d{1,3})\s+(?:derniers?\s+)?jours?\b/', $n, $m) && empty($s['period'])) {
            $s['days'] = max(1, min(365, (int) $m[1]));
        }
        if (preg_match('/\b(en montant|en valeur|chiffre|en argent|rapporte)\b/', $n)) {
            $s['by'] = 'revenue';
        }
        // Boutique ou magasin nommés
        if ($loc = self::location($n, $u)) {
            $s['location'] = $loc;
        }
        return $s;
    }

    /** Boutique ou magasin cité (nom exact ou approché), limité au périmètre de l'utilisateur. */
    public static function location(string $n, UserContext $u): ?array
    {
        $words = array_values(array_filter(explode(' ', $n), fn($w) => strlen($w) >= 3 && !in_array($w, self::STOP, true)));
        $cands = [];
        $storeIds = $u->storeIds();
        foreach (DB::all('SELECT id, name FROM stores WHERE active = 1') as $r) {
            if ($storeIds === null || in_array((int) $r['id'], $storeIds, true)) {
                $cands[] = ['type' => 'store', 'id' => (int) $r['id'], 'name' => $r['name']];
            }
        }
        $wh = $u->warehouseIds();
        foreach (DB::all('SELECT id, name FROM warehouses WHERE active = 1') as $r) {
            if ($wh === null || in_array((int) $r['id'], $wh, true)) {
                $cands[] = ['type' => 'warehouse', 'id' => (int) $r['id'], 'name' => $r['name']];
            }
        }
        $best = null;
        $bs = 0.0;
        foreach ($cands as $c) {
            $sc = Text::itemScore($words, $c['name']);
            $nameWords = Text::words($c['name']);
            // Le simple mot générique « boutique » / « magasin » ne suffit pas s'il y en a plusieurs
            $generic = count($nameWords) > 0 && count(array_diff($nameWords, ['boutique', 'magasin', 'depot', 'principal', 'principale'])) > 0
                && !array_intersect(array_diff($nameWords, ['boutique', 'magasin', 'depot', 'principal', 'principale']), $words);
            if ($sc > $bs && !$generic) {
                $bs = $sc;
                $best = $c;
            }
        }
        if ($best && $bs >= 0.6) {
            return $best;
        }
        // « la boutique » / « le magasin » sans nom : seulement s'il n'y en a qu'un de ce type dans le périmètre
        foreach (['boutique' => 'store', 'magasin' => 'warehouse', 'depot' => 'warehouse'] as $word => $type) {
            if (preg_match('/\b(la|le|du|de la|au|celui de la|celui du)\s+' . $word . '\b/', $n)) {
                $list = array_values(array_filter($cands, fn($c) => $c['type'] === $type && str_contains(Text::norm($c['name']), $word)));
                if (count($list) === 1) {
                    return $list[0];
                }
                $list = array_values(array_filter($cands, fn($c) => $c['type'] === $type));
                if (count($list) === 1) {
                    return $list[0];
                }
                return ['type' => $type, 'id' => 0, 'name' => $word, 'ambiguous' => array_column($list, 'name')];
            }
        }
        return null;
    }

    /** Article cité (pour « combien il reste de riz »). */
    public static function product(string $question, array $intentTokens): ?array
    {
        $words = array_values(array_filter(Text::words($question), fn($w) => strlen($w) >= 3 && !in_array($w, ['combien', 'reste', 'stock', 'quantite', 'article', 'produit', 'restante', 'disponible', 'encore'], true)));
        if (!$words) {
            return null;
        }
        $like = [];
        $p = [];
        foreach (array_slice($words, 0, 4) as $w) {
            $like[] = 'name LIKE ?';
            $p[] = '%' . $w . '%';
        }
        $best = null;
        $bs = 0.0;
        foreach (DB::all("SELECT id, name FROM products WHERE status = 'active' AND (" . implode(' OR ', $like) . ') LIMIT 40', $p) as $r) {
            $sc = Text::itemScore($words, $r['name']);
            if ($sc > $bs) {
                $bs = $sc;
                $best = ['id' => (int) $r['id'], 'name' => $r['name']];
            }
        }
        return $bs >= 0.5 ? $best : null;
    }

    /** Vendeur cité (« ventes de Moussa »). */
    public static function user(string $question): ?array
    {
        $n = Text::norm($question);
        if (!preg_match('/\b(?:de|par|du|des|d)\s+(?:l\s+|la\s+|le\s+)?((?:caissier(?:e)?\s+|vendeur(?:se)?\s+|utilisateur\s+)?)([a-z][a-z ]{2,40})/', $n, $m)) {
            return null;
        }
        $m[1] = trim($m[1] . ' ' . $m[2]); // le mot « caissier » peut être l'identifiant lui-même
        $skip = ['aujourd', 'hui', 'hier', 'semaine', 'mois', 'annee', 'jour', 'journee', 'derniere', 'dernier', 'cette', 'ce', 'cet', 'en', 'pdf', 'excel', 'boutique', 'magasin', 'caisse', 'stock', 'mes', 'mon', 'ma', 'vente', 'ventes', 'janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin', 'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'];
        $words = array_values(array_filter(Text::words($m[1]), fn($w) => strlen($w) >= 3 && !in_array($w, $skip, true)));
        if (!$words) {
            return null;
        }
        $best = null;
        $bs = 0.0;
        foreach (DB::all('SELECT id, username, full_name FROM users WHERE active = 1') as $r) {
            $named = array_values(array_diff($words, ['caissier', 'caissiere', 'vendeur', 'vendeuse', 'utilisateur']));
            $sc = in_array(Text::norm((string) $r['username']), $words, true) ? 1.0 : ($named ? Text::itemScore($named, $r['full_name']) : 0.0);
            if ($sc > $bs) {
                $bs = $sc;
                $best = ['id' => (int) $r['id'], 'name' => $r['full_name']];
            }
        }
        return $bs >= 0.75 ? $best : null;
    }

    /** Découpe une question qui contient plusieurs demandes. */
    public static function split(string $question): array
    {
        $out = [];
        // Ponctuation d'abord (avant normalisation, qui supprime « ? » et « ; »), puis les connecteurs.
        foreach (preg_split('/[?;]+/u', $question) as $raw) {
            $parts = preg_split('/\s*(?:\bpuis\b|\bensuite\b|\bet aussi\b|,\s*et\b|\bet\s+(?=(?:combien|quel|quelle|quels|quelles|donne|montre|fais|la valeur|le chiffre|les produits|les ventes|le solde|le benefice|la liste|les depenses|qui)\b))\s*/iu', Text::norm($raw));
            foreach ($parts as $p) {
                $p = trim($p);
                if (mb_strlen($p) >= 6) {
                    $out[] = $p;
                }
            }
        }
        return $out;
    }
}
