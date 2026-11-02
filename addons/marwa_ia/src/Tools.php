<?php
declare(strict_types=1);

namespace Addons\MarwaIa;

use App\Core\Auth;
use App\Services\ReportService;

/**
 * Petits outils du gérant : calculs commerciaux (prix de vente, marge, TVA, remise, seuil
 * de rentabilité) et lexique de gestion expliqué simplement.
 */
final class Tools
{
    // ------------------------------------------------------------------ nombres

    /** Nombres de la phrase : « 3 750 », « 1.500 », « 2,5 », « 30 % », « 50 mille », « 1,2 million ». */
    public static function numbers(string $q): array
    {
        $s = mb_strtolower(str_replace(["\u{00A0}", "\u{202F}"], ' ', $q));
        $out = [];
        if (preg_match_all('/(?<![\d,.])(\d{1,3}(?:[ .]\d{3})+|\d+)(?:[,.](\d{1,2})(?!\d))?\s*(%|pour ?cents?|mille|k\b|millions?|milliards?)?/u', $s, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $x) {
                $v = (float) (str_replace([' ', '.'], '', $x[1][0]) . (isset($x[2]) && $x[2][0] !== '' ? '.' . $x[2][0] : ''));
                $u = $x[3][0] ?? '';
                $v *= match (true) {
                    $u === 'mille' || $u === 'k' => 1000,
                    str_starts_with($u, 'million') => 1e6,
                    str_starts_with($u, 'milliard') => 1e9,
                    default => 1,
                };
                $out[] = ['v' => $v, 'pct' => $u === '%' || str_starts_with($u, 'pour'), 'pos' => $x[0][1]];
            }
        }
        return $out;
    }

    private static function f(float $v): string
    {
        return money(round($v));
    }

    private static function p(float $v): string
    {
        return str_replace('.', ',', (string) round($v, 1)) . ' %';
    }

    // ------------------------------------------------------------------ calculs

    /** Calcul commercial reconnu et résolu, ou null. */
    public static function calc(string $q): ?array
    {
        $n = Text::norm($q);
        $nums = self::numbers($q);
        $plain = array_values(array_filter($nums, fn($x) => !$x['pct']));
        $pcts = array_values(array_filter($nums, fn($x) => $x['pct']));
        $vatRate = (float) str_replace(',', '.', (string) setting('vat_rate', '19'));

        // Seuil de rentabilité
        if (preg_match('/\b(seuil de rentabilite|point mort|combien (dois|faut) (je|il) vendre pour (couvrir|payer|ne pas perdre))\b/', $n)) {
            if (!$plain) {
                return self::out('Seuil de rentabilité', '<p>Donnez-moi vos <b>charges fixes du mois</b> (loyer, salaires, électricité…) et, si vous le connaissez, votre taux de marge. Exemple : « seuil de rentabilité avec 450 000 F de charges et 25 % de marge ».</p>');
            }
            $fixed = $plain[0]['v'];
            $rate = $pcts[0]['v'] ?? null;
            $src = 'que vous avez indiqué';
            if ($rate === null && Auth::can('reports.financial')) {
                $s = ReportService::summary(date('Y-m-d', strtotime('-29 days')), date('Y-m-d'));
                if ($s['net'] > 0 && $s['margin'] > 0) {
                    $rate = $s['margin'] / $s['net'] * 100;
                    $src = 'de votre boutique sur les 30 derniers jours';
                }
            }
            if ($rate === null || $rate <= 0) {
                return self::out('Seuil de rentabilité', '<p>Il me manque votre <b>taux de marge</b> (en % du prix de vente), par exemple « avec 25 % de marge ».</p>');
            }
            $ca = $fixed / ($rate / 100);
            $days = (int) date('t');
            return self::out('Seuil de rentabilité', '<p>Avec <b>' . self::f($fixed) . '</b> de charges fixes et un taux de marge de <b>' . self::p($rate) . '</b> (' . $src . ') :</p>'
                . '<div class="mia-calc"><span>Chiffre d\'affaires minimum</span><b>' . self::f($ca) . '</b><small>par mois, soit environ ' . self::f($ca / $days) . ' par jour</small></div>'
                . '<p class="small">Calcul : charges fixes ÷ taux de marge. En dessous, vous perdez de l\'argent ; au-dessus, chaque vente rapporte sa marge.</p>');
        }

        // TVA : HT ↔ TTC
        if (preg_match('/\b(ttc|ht|hors taxes?|toutes taxes|tva)\b/', $n) && $plain && preg_match('/\b(ttc|ht|hors taxes?|toutes taxes|tva|calcul\w*|combien|convertir|donne|fait)\b/', $n)) {
            $rate = $pcts[0]['v'] ?? $vatRate;
            $v = $plain[0]['v'];
            $fromTtc = preg_match('/\b(\d[\d ]*\s*(f|fcfa)?\s*ttc|ttc en ht|ttc vers ht|en ht|le ht|hors taxe d|prix ht de)\b/', $n) && !preg_match('/\b(en ttc|le ttc|ttc de)\b/', $n);
            if ($fromTtc) {
                $ht = $v / (1 + $rate / 100);
                $html = '<div class="mia-calc"><span>' . self::f($v) . ' TTC</span><b>' . self::f($ht) . ' HT</b><small>TVA ' . self::p($rate) . ' : ' . self::f($v - $ht) . '</small></div><p class="small">Calcul : TTC ÷ (1 + ' . self::p($rate) . ').</p>';
            } else {
                $ttc = $v * (1 + $rate / 100);
                $html = '<div class="mia-calc"><span>' . self::f($v) . ' HT</span><b>' . self::f($ttc) . ' TTC</b><small>TVA ' . self::p($rate) . ' : ' . self::f($ttc - $v) . '</small></div><p class="small">Calcul : HT × (1 + ' . self::p($rate) . ').</p>';
            }
            return self::out('Calcul de TVA', $html . (isset($pcts[0]) ? '' : '<p class="small">Taux utilisé : celui de vos paramètres (' . self::p($vatRate) . '). Précisez « à 5 % » pour un autre taux.</p>'));
        }

        // Remise
        if (preg_match('/\b(remise|reduction|rabais|ristourne|promo\w*|solde)\b/', $n) && $plain && $pcts) {
            $v = $plain[0]['v'];
            $r = $pcts[0]['v'];
            $after = $v * (1 - $r / 100);
            return self::out('Calcul de remise', '<div class="mia-calc"><span>' . self::f($v) . ' − ' . self::p($r) . '</span><b>' . self::f($after) . '</b><small>remise de ' . self::f($v - $after) . '</small></div>'
                . '<p class="small">Attention : une remise se prend sur votre marge. Vérifiez que le prix reste au-dessus du prix d\'achat.</p>');
        }

        $buy = preg_match('/\b(achete|achetes|achat|achats|cout|coute|coutent|revient|prix d achat|pa)\b/', $n);
        $sell = preg_match('/\b(vend|vends|vendu|vendre|vente|revends?|prix de vente|pv)\b/', $n);

        // Prix de vente à partir du coût et d'une marge en %
        if ($buy && $pcts && count($plain) === 1 && preg_match('/\b(prix de vente|a quel prix|quel prix|combien vendre|vendre a combien|je vends a combien|vendre combien|prix conseille|marge de|avec \d+)\b/', $n)) {
            $c = $plain[0]['v'];
            $m = $pcts[0]['v'];
            $html = '<p>Prix d\'achat <b>' . self::f($c) . '</b>, marge voulue <b>' . self::p($m) . '</b> :</p>'
                . '<div class="mia-calc"><span>Marge de ' . self::p($m) . ' sur le coût</span><b>' . self::f($c * (1 + $m / 100)) . '</b><small>bénéfice ' . self::f($c * $m / 100) . ' par unité</small></div>';
            if ($m < 100) {
                $pv = $c / (1 - $m / 100);
                $html .= '<div class="mia-calc"><span>' . self::p($m) . ' du prix de vente</span><b>' . self::f($pv) . '</b><small>bénéfice ' . self::f($pv - $c) . ' par unité</small></div>';
            }
            $html .= '<p class="small">Le premier calcul ajoute ' . self::p($m) . ' au coût (taux de marge) ; le second garantit que ' . self::p($m) . ' du prix de vente est du bénéfice (taux de marque). Arrondissez ensuite à un prix « rond » que le client retient.</p>';
            return self::out('Calcul du prix de vente', $html);
        }

        // Marge à partir du prix d'achat et du prix de vente
        if ($buy && $sell && count($plain) >= 2) {
            [$a, $b] = [$plain[0]['v'], $plain[1]['v']];
            $firstIsSell = preg_match('/\b(vend|vends|vendu|vente|prix de vente|pv)\b.*\b(achete|achat|cout|revient|prix d achat)\b/', $n);
            [$c, $p] = $firstIsSell ? [$b, $a] : [$a, $b];
            if ($c <= 0 || $p <= 0) {
                return null;
            }
            $mg = $p - $c;
            $html = '<p>Acheté <b>' . self::f($c) . '</b>, vendu <b>' . self::f($p) . '</b> :</p><div class="mia-calcs">'
                . '<div class="mia-calc"><span>Marge par unité</span><b>' . self::f($mg) . '</b></div>'
                . '<div class="mia-calc"><span>Taux de marge (sur le coût)</span><b>' . self::p($mg / $c * 100) . '</b></div>'
                . '<div class="mia-calc"><span>Taux de marque (sur la vente)</span><b>' . self::p($mg / $p * 100) . '</b></div>'
                . '<div class="mia-calc"><span>Coefficient</span><b>' . str_replace('.', ',', (string) round($p / $c, 2)) . '</b></div></div>';
            if ($mg < 0) {
                $html .= '<p class="mia-bad"><b>Attention : vous vendez à perte</b> (' . self::f(-$mg) . ' perdus par unité).</p>';
            } elseif ($mg / $p < 0.1) {
                $html .= '<p class="small">Marge faible : avec la casse, les pertes et les frais, cet article rapporte peu.</p>';
            }
            if (count($plain) >= 3) {
                $qty = $plain[2]['v'];
                $html .= '<p>Pour <b>' . (float) $qty . '</b> unités : bénéfice brut <b>' . self::f($mg * $qty) . '</b>.</p>';
            }
            return self::out('Calcul de marge', $html);
        }
        return null;
    }

    private static function out(string $title, string $html): array
    {
        return ['ok' => true, 'type' => 'calc', 'html' => '<p class="h">' . e($title) . '</p>' . $html, 'actions' => [], 'suggestions' => ['Prix de vente pour un achat à 1 000 F avec 25 % de marge', '5 000 F HT en TTC', 'Seuil de rentabilité avec 300 000 F de charges'], 'spec' => null];
    }

    // ------------------------------------------------------------------ lexique

    /** terme => [variantes (texte normalisé), définition] */
    public const GLOSSARY = [
        'CMP (coût moyen pondéré)' => ['cmp|cout moyen pondere|cout moyen|prix moyen pondere', 'Le coût d\'achat moyen de votre stock. Si vous aviez 10 unités à 100 F et en recevez 10 à 120 F, le CMP devient 110 F. POS Marwa le recalcule à chaque réception : c\'est lui qui sert à calculer votre bénéfice.'],
        'Marge' => ['marge|marge brute|marge commerciale', 'Ce qu\'il reste du prix de vente après avoir retiré le prix d\'achat : vendu 1 500 F, acheté 1 000 F, marge 500 F. Ce n\'est pas encore le bénéfice : il faut ensuite payer les charges.'],
        'Taux de marge et taux de marque' => ['taux de marge|taux de marque|marge en pourcentage', 'Pour un article acheté 1 000 F et vendu 1 250 F (marge 250 F) : le <b>taux de marge</b> se calcule sur le coût (250 ÷ 1 000 = 25 %), le <b>taux de marque</b> sur le prix de vente (250 ÷ 1 250 = 20 %).'],
        'Coefficient' => ['coefficient|coef|coefficient multiplicateur', 'Le nombre par lequel on multiplie le prix d\'achat pour obtenir le prix de vente. Coefficient 1,25 : acheté 1 000 F, vendu 1 250 F.'],
        'Chiffre d\'affaires' => ['chiffre d affaires|chiffre d affaire|\bca\b', 'Le total des ventes encaissées ou facturées sur une période. Beaucoup de chiffre d\'affaires ne veut pas dire beaucoup de bénéfice.'],
        'Bénéfice net' => ['benefice net|resultat net|benefice', 'Marge brute − charges (loyer, salaires, électricité, transport…). C\'est ce que l\'entreprise gagne réellement.'],
        'Créance' => ['creance|creances|credit client', 'L\'argent que vos clients vous doivent (ventes à crédit non payées). Une créance n\'est pas de l\'argent en caisse : relancez-la.'],
        'Dette fournisseur' => ['dette fournisseur|dettes fournisseurs|dette', 'L\'argent que vous devez à vos fournisseurs pour des marchandises reçues et pas encore payées.'],
        'Trésorerie' => ['tresorerie|liquidite|liquidites', 'L\'argent réellement disponible : caisse + banques + mobile money. On peut être rentable et manquer de trésorerie si trop d\'argent dort dans le stock ou les crédits clients.'],
        'Fond de caisse' => ['fond de caisse|fonds de caisse', 'La monnaie laissée dans le tiroir à l\'ouverture pour rendre la monnaie. Elle est comptée à l\'ouverture et retrouvée à la clôture.'],
        'Écart de caisse' => ['ecart de caisse|ecart|manque de caisse|surplus de caisse', 'La différence entre l\'argent compté et l\'argent théorique calculé par le logiciel. Tout écart doit être commenté à la clôture.'],
        'FEFO' => ['fefo|premier perime premier sorti|fifo', '« Premier périmé, premier sorti » : on vend d\'abord les lots qui expirent le plus tôt. POS Marwa l\'applique automatiquement aux articles suivis par lot.'],
        'Stock minimum (seuil d\'alerte)' => ['stock minimum|stock mini|seuil d alerte|stock de securite|stock min', 'La quantité en dessous de laquelle il faut recommander. POS Marwa vous alerte quand le stock l\'atteint.'],
        'Rupture de stock' => ['rupture|rupture de stock', 'Un article à zéro alors que des clients le demandent : ce sont des ventes perdues, et parfois des clients perdus.'],
        'Stock dormant' => ['stock dormant|articles dormants|dormant|invendus', 'Des articles qui ne se vendent plus depuis longtemps : c\'est de l\'argent immobilisé. Promotion, lot ou retour fournisseur.'],
        'Rotation du stock' => ['rotation|rotation du stock|rotation des stocks', 'Le nombre de fois où le stock est vendu et renouvelé sur une période. Plus elle est rapide, moins votre argent dort sur les étagères.'],
        'Inventaire' => ['inventaire', 'Le comptage physique du stock, comparé aux quantités du logiciel. Les écarts révèlent les pertes, la casse ou les erreurs de saisie.'],
        'Panier moyen' => ['panier moyen|ticket moyen', 'Chiffre d\'affaires ÷ nombre de ventes. Pour l\'augmenter : articles complémentaires, lots, conseils au client.'],
        'Seuil de rentabilité' => ['seuil de rentabilite|point mort', 'Le chiffre d\'affaires minimum pour couvrir toutes les charges : charges fixes ÷ taux de marque. Demandez-moi « seuil de rentabilité avec 300 000 F de charges ».'],
        'TVA, HT et TTC' => ['tva|\bht\b|\bttc\b|hors taxe|toutes taxes', 'La TVA est un impôt collecté pour l\'État. HT = hors taxes, TTC = toutes taxes comprises (HT + TVA). Demandez-moi « 10 000 F HT en TTC ».'],
        'Conditionnement' => ['conditionnement|conditionnements|carton|pack', 'Un format de vente groupé (carton de 24, pack de 6) avec son prix et son code-barres. Le stock reste compté à l\'unité.'],
        'Contre-passation' => ['contre passation|contre passer|contrepasser|contre passe', 'Une écriture inverse qui annule une opération sans l\'effacer : l\'historique reste complet et vérifiable.'],
        'Plafond de crédit' => ['plafond|plafond de credit|limite de credit', 'Le montant maximum qu\'un client peut vous devoir. Au-delà, un responsable doit autoriser la vente.'],
        'Devis et bon de livraison' => ['devis|bon de livraison|proforma|pro forma|facture proforma', 'Le devis (ou proforma) propose un prix au client avant la vente ; le bon de livraison accompagne la marchandise remise. Les deux se transforment en vente dans POS Marwa.'],
        'SYSCOHADA' => ['syscohada|ohada|plan comptable', 'Le système comptable des pays de l\'espace OHADA, dont le Niger. Le module Comptabilité de POS Marwa en suit le plan de comptes.'],
        'NIF et RCCM' => ['nif|rccm|registre du commerce', 'NIF : numéro d\'identification fiscale. RCCM : registre du commerce. Ils s\'impriment sur vos factures (Paramètres).'],
        'Mobile money' => ['mobile money|momo', 'Le paiement par téléphone (Airtel Money, Moov Money, Zamani Cash…). En caisse : fenêtre d\'encaissement → Mobile money → opérateur et référence.'],
    ];

    public static function isDefinition(string $n): bool
    {
        return (bool) preg_match('/\b(c est quoi|c quoi|qu est ce que|qu est ce qu|que veut dire|que signifie|ca veut dire quoi|veut dire quoi|definition|definis|definir|signification|explique moi (le|la|les|l|ce qu est))\b/', $n);
    }

    public static function define(string $q): ?array
    {
        $n = Text::norm($q);
        if (!self::isDefinition($n)) {
            return null;
        }
        $best = null;
        $len = 0;
        foreach (self::GLOSSARY as $term => [$re, $def]) {
            if (preg_match('/(?<![a-z])(' . $re . ')(?![a-z])/', $n, $m) && strlen($m[1]) > $len) {
                $len = strlen($m[1]);
                $best = $term;
            }
        }
        if ($best === null) {
            return null;
        }
        $html = '<p class="h">' . e($best) . '</p><p>' . self::GLOSSARY[$best][1] . '</p>';
        $others = array_values(array_filter(['CMP (coût moyen pondéré)', 'Trésorerie', 'Taux de marge et taux de marque', 'FEFO', 'Seuil de rentabilité'], fn($t) => $t !== $best));
        return ['ok' => true, 'type' => 'glossary', 'html' => $html, 'actions' => [], 'suggestions' => array_map(fn($t) => 'C\'est quoi ' . mb_strtolower(explode(' (', $t)[0]) . ' ?', array_slice($others, 0, 3)), 'spec' => null];
    }
}
