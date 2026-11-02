<?php
declare(strict_types=1);

namespace Addons\MarwaIa;

use App\Core\Addons;
use App\Core\Auth;
use App\Core\DB;
use App\Services\ReportService;

/**
 * Conseiller en gestion : idées concrètes par thème (adaptées au commerce au Niger et en Afrique
 * de l'Ouest), complétées par des constats calculés sur les vraies données de la boutique et
 * par les écrans de POS Marwa qui permettent de passer à l'action.
 */
final class Advisor
{
    /** thème => [mots-clés, titre, idées, outils POS Marwa [libellé, chemin], thème de citation] */
    public const THEMES = [
        'ventes' => ['augmenter vendre plus chiffre affaires booster developper croissance attirer nouveaux clients ventes baissent marche pas', 'Augmenter les ventes', [
            'Mettez les <b>meilleures ventes</b> à hauteur des yeux et près de la caisse ; ne les laissez jamais en rupture (elles font souvent plus de la moitié du chiffre).',
            'Proposez un <b>article complémentaire</b> à chaque vente : sucre avec le thé, piles avec la lampe, savon avec la lessive.',
            'Créez des <b>lots</b> (conditionnements) : « pack famille », carton, demi-carton — le client achète plus, vous écoulez plus vite.',
            'Utilisez les <b>heures creuses</b> (rapport « affluence par heure ») pour une petite promotion ciblée.',
            'Annoncez vos nouveautés et promotions sur votre <b>statut WhatsApp</b> et dans les groupes du quartier, avec photo et prix.',
            'Acceptez tous les moyens de paiement : <b>mobile money</b> (Airtel, Moov, Zamani…), carte, crédit encadré : un client qui ne peut pas payer part ailleurs.',
            'Un <b>écran client</b> qui diffuse vos promotions pendant l\'attente déclenche des achats.'],
            [['Meilleures ventes', '/reports/products'], ['Affluence par heure', '/reports/sales'], ['Écran client', '/settings/display']], 'ventes'],
        'fidelite' => ['fideliser fidelite clients reviennent garder clients habitues satisfaction service client relation', 'Fidéliser les clients', [
            'Enregistrez le <b>nom et le téléphone</b> des bons clients à la caisse : vous saurez qui achète quoi et quand.',
            'Repérez vos <b>meilleurs clients</b> (demandez-moi « meilleurs clients ») et remerciez-les : petit cadeau, prix de gros, priorité.',
            'Envoyez un message WhatsApp quand l\'article qu\'un client aime revient en stock.',
            'Un service rapide compte : caisse ouverte à l\'heure, monnaie prête, ticket propre avec QR code.',
            'Faites du <b>crédit</b> seulement aux clients fiables, avec un plafond : un client trop endetté ne revient plus, par gêne.',
            'Écoutez les demandes d\'articles que vous n\'avez pas : notez-les, c\'est votre prochaine commande.'],
            [['Clients & crédits', '/customers']], 'clients'],
        'pertes' => ['vol vols voler perte pertes casse disparait disparition ecart manque coulage fraude triche detourne', 'Réduire les pertes et les vols', [
            'Faites un <b>inventaire tournant</b> : une catégorie par semaine ; les écarts montrent où ça fuit.',
            'Chaque caissier ouvre <b>sa propre caisse</b> avec son compte : en cas d\'écart, on sait qui expliquer.',
            'Ne corrigez jamais un stock « à la main » : ajustement avec <b>motif obligatoire</b>, pour comprendre les pertes.',
            'Les remises, prix modifiés et annulations passent par l\'<b>autorisation d\'un responsable</b> (déjà prévu dans POS Marwa).',
            'Comparez les <b>écarts de caisse</b> par caissier chaque semaine : un écart répété n\'est pas un hasard.',
            'Pour les articles chers ou volés souvent : rangement près de la caisse, comptage quotidien, et si besoin le module de surveillance par caméra.'],
            [['Inventaires', '/inventories'], ['Sessions de caisse', '/cash/sessions'], ['Ajustement de stock', '/stock/adjust']], 'pertes'],
        'stock' => ['stock rupture commander commande approvisionner reapprovisionner trop stock surstock dormant perime peremption fournisseur', 'Mieux gérer le stock', [
            'Fixez un <b>seuil d\'alerte</b> réaliste pour chaque article : ventes par jour × délai du fournisseur, plus une marge de sécurité.',
            'Commandez d\'après les ventes réelles : demandez-moi « que dois-je commander ? ».',
            'Les <b>articles dormants</b> immobilisent votre argent : promotion, lot, prix de gros ou retour fournisseur.',
            'Pour les produits datés : saisissez lot et péremption ; POS Marwa vend d\'abord ceux qui périment le plus tôt (FEFO).',
            'Négociez avec vos fournisseurs : délai de paiement, livraison groupée, prix au carton.'],
            [['État des stocks', '/stock'], ['Péremptions', '/stock/expiry'], ['Commandes fournisseurs', '/purchases']], 'stock'],
        'prix' => ['prix fixer prix marge benefice rentable rentabilite coefficient cher concurrent concurrence remise promotion', 'Fixer les bons prix et protéger la marge', [
            'Partez toujours du <b>coût moyen</b> (CMP) : prix = coût ÷ (1 − taux de marge). Demandez-moi « prix de vente pour un coût de 180 avec 28 % de marge ».',
            'Vérifiez qu\'aucun article n\'est vendu <b>sous son coût</b> (demandez-moi « vérifie ma configuration »).',
            'Une remise de 10 % sur un article à 20 % de marge enlève la <b>moitié du bénéfice</b> : préférez les lots et les prix de gros.',
            'Suivez la marge par catégorie : certaines font le chiffre, d\'autres font le bénéfice.',
            'Quand le prix d\'achat monte, mettez à jour le prix de vente dès la réception (colonne « nouveau prix de vente »).'],
            [['Bénéfices & marges', '/reports/profit'], ['Articles', '/products']], 'croissance'],
        'tresorerie' => ['tresorerie argent liquidite cash manque argent epargne epargner economiser banque credit dette dettes payer fournisseur rembourser', 'Gérer l\'argent (trésorerie)', [
            'Séparez strictement l\'argent de la boutique et l\'argent personnel ; un retrait du propriétaire s\'enregistre comme une <b>sortie</b>.',
            'Versez les espèces de chaque clôture à la banque ou au coffre (module Banques) : moins de risque, chiffres clairs.',
            'Relancez les <b>créances</b> chaque semaine ; demandez-moi « qui me doit de l\'argent ? ».',
            'Payez les fournisseurs à l\'échéance, pas avant : votre argent travaille plus longtemps dans le stock.',
            'Gardez une <b>réserve</b> égale à un mois de charges fixes (loyer, salaires, électricité).',
            'Suivez les dépenses par catégorie chaque mois : les petites dépenses répétées pèsent lourd.'],
            [['Banques et coffres', '/banques'], ['Créances & dettes', '/reports/debts'], ['Dépenses & recettes', '/expenses']], 'tresorerie'],
        'depenses' => ['depenses charges reduire couts frais economie electricite loyer transport', 'Réduire les charges', [
            'Classez chaque dépense dans une <b>catégorie</b> : vous verrez en fin de mois où part l\'argent.',
            'Comparez chaque mois la part des dépenses dans le chiffre d\'affaires.',
            'Électricité : éteignez les appareils inutiles, réfrigérateurs bien réglés, éclairage LED.',
            'Transport : regroupez les achats chez le fournisseur, faites-vous livrer quand c\'est gratuit.',
            'Une dépense saisie tout de suite évite les écarts de caisse inexpliqués le soir.'],
            [['Dépenses & recettes', '/expenses'], ['Bénéfices & marges', '/reports/profit']], 'epargne'],
        'personnel' => ['personnel employe employes caissier equipe motiver motivation salaire former formation recruter confiance', 'Gérer et motiver l\'équipe', [
            'Un <b>compte par employé</b>, avec le rôle juste : chacun ne voit et ne fait que son travail.',
            'Formez les nouveaux avec moi : demandez « formation » — leçons courtes et quiz.',
            'Fixez des objectifs simples (ventes du jour, zéro écart de caisse) et félicitez publiquement les bons résultats.',
            'Le rapport « ventes par vendeur » permet une prime juste et transparente.',
            'Changez les mots de passe quand un employé part, et révoquez ses appareils.'],
            [['Utilisateurs', '/users'], ['Rôles & permissions', '/roles']], 'equipe'],
        'restaurant' => ['restaurant maquis cuisine plat plats menu table tables serveur couverts gaspillage', 'Idées pour le restaurant', [
            'Les <b>fiches techniques</b> (ingrédients par portion) donnent le vrai coût de chaque plat et suivent le stock des ingrédients.',
            'Un « plat du jour » à prix rond écoule les produits du jour et accélère le service.',
            'Suivez les annulations après envoi en cuisine : ce sont des pertes.',
            'Proposez la vente à emporter et la livraison avec le numéro du client : il recommandera.'],
            [['Plan de salle', '/restaurant'], ['Fiches techniques', '/recipes']], 'equipe'],
        'pharmacie' => ['pharmacie medicament medicaments ordonnance peremption lot officine', 'Idées pour la pharmacie', [
            'Chaque semaine, regardez les produits qui périment dans 3 mois : vente prioritaire ou retour fournisseur.',
            'Saisissez toujours lot et péremption à la réception : la vente FEFO et les rappels de lots en dépendent.',
            'Gardez un stock de sécurité sur les médicaments essentiels : une rupture fait perdre le client et la confiance.',
            'Le registre des ordonnances doit être complet et à jour en cas de contrôle.'],
            [['Péremptions', '/stock/expiry'], ['Registre des ordonnances', '/reports/prescriptions']], 'prudence'],
        'hotel' => ['hotel chambre chambres occupation remplir nuitee reservation client hotel', 'Idées pour l\'hôtel', [
            'Suivez le <b>taux d\'occupation</b> et le prix moyen : remplir à prix cassé n\'est pas toujours rentable.',
            'Tarif week-end et tarif « long séjour » attirent des clients différents.',
            'Faites l\'audit de nuit chaque matin : les nuitées oubliées sont de l\'argent perdu.',
            'Une chambre propre à l\'heure (suivi du ménage) se loue plus vite.'],
            [['Hôtel', '/hotel']], 'clients'],
        'ecole' => ['ecole etablissement eleve eleves scolarite frais impayes parents', 'Idées pour l\'établissement', [
            'Un échéancier clair dès l\'inscription et un reçu à chaque versement réduisent les impayés.',
            'Relancez les parents avant la date limite, poliment, avec le reste à payer exact.',
            'Les bulletins à l\'heure renforcent la confiance des familles.'],
            [['Établissement', '/ecole']], 'formation'],
        'pressing' => ['pressing linge vetements depot depots lavage repassage', 'Idées pour le pressing', [
            'Notez les <b>défauts</b> au dépôt : c\'est la meilleure protection contre les litiges.',
            'Prévenez le client par WhatsApp dès que son linge est prêt : il vient plus vite, vous encaissez plus vite.',
            'L\'option EXPRESS et la livraison à domicile sont des services à vendre, pas à offrir.',
            'Suivez les dépôts non retirés : au-delà de 90 jours, appliquez vos conditions.'],
            [['Pressing', '/pressing'], ['Suivi des vêtements', '/pressing/suivi']], 'clients'],
    ];

    /** Question de gestion (idées, conseils, stratégie) ? */
    public static function isAdvice(string $n): bool
    {
        return (bool) preg_match('/\b(idees?|conseils?|astuces?|strategies?|suggestions?|recommandations?|que faire pour|que dois je faire|comment (augmenter|ameliorer|booster|developper|fideliser|reduire|eviter|limiter|gerer mieux|mieux gerer|attirer|vendre plus|gagner plus|faire plus|economiser|epargner|motiver|remplir|lutter|stopper|arreter|fixer (le|mes|un) prix|calculer mon prix)|aide moi a (augmenter|vendre|gerer|reduire|fideliser)|mes ventes (baissent|diminuent)|je perds|ca ne marche pas|ma boutique ne marche|pourquoi je ne gagne)\b/', $n);
    }

    public static function answer(string $q): array
    {
        $n = Text::norm($q);
        $stems = Text::stems($q);
        $best = null;
        $bs = 0;
        foreach (self::THEMES as $k => $t) {
            $keys = Text::stems($t[0] . ' ' . $t[1]);
            $hit = 0;
            foreach ($stems as $w) {
                foreach ($keys as $kk) {
                    if ($w === $kk || (strlen($w) >= 4 && strlen($kk) >= 4 && (str_starts_with($kk, $w) || str_starts_with($w, $kk)))) {
                        $hit++;
                        break;
                    }
                }
            }
            if ($hit > $bs) {
                $bs = $hit;
                $best = $k;
            }
        }
        $best ??= 'ventes';
        $t = self::THEMES[$best];
        $html = '<p class="h">' . e($t[1]) . '</p>';
        $facts = self::facts($best);
        if ($facts) {
            $html .= '<p><b>Ce que je vois dans vos chiffres :</b></p><ul class="mia-facts">' . implode('', array_map(fn($f) => '<li>' . $f . '</li>', $facts)) . '</ul>';
        }
        $html .= '<p><b>Idées à mettre en place :</b></p><ol>' . implode('', array_map(fn($i) => '<li>' . $i . '</li>', $t[2])) . '</ol>';
        $html .= Wisdom::html(Wisdom::forTheme($t[4]));
        $actions = array_map(fn($l) => ['label' => $l[0], 'url' => url($l[1]), 'kind' => 'link'], array_slice($t[3], 0, 3));
        $others = array_values(array_filter(['ventes' => 'Comment augmenter mes ventes ?', 'fidelite' => 'Idées pour fidéliser mes clients', 'pertes' => 'Comment réduire les pertes et les vols ?',
            'tresorerie' => 'Conseils pour ma trésorerie', 'prix' => 'Comment fixer mes prix ?', 'stock' => 'Conseils pour mon stock'], fn($v, $k) => $k !== $best, ARRAY_FILTER_USE_BOTH));
        return ['ok' => true, 'type' => 'advice', 'title' => 'Conseils de gestion', 'html' => $html, 'actions' => $actions, 'suggestions' => array_slice($others, 0, 3), 'spec' => null];
    }

    /** Constats chiffrés liés au thème (seulement ce que le rôle peut voir). */
    public static function facts(string $theme): array
    {
        $f = [];
        $from = date('Y-m-d', strtotime('-29 days'));
        $to = date('Y-m-d');
        try {
            if (in_array($theme, ['ventes', 'prix', 'stock', 'fidelite'], true) && Auth::can('reports.view')) {
                $s = ReportService::summary($from, $to);
                $p = ReportService::summary(date('Y-m-d', strtotime('-59 days')), date('Y-m-d', strtotime('-30 days')));
                if ($s['tickets'] > 0) {
                    $var = $p['net'] > 0 ? round(($s['net'] - $p['net']) / $p['net'] * 100) : null;
                    $f[] = '30 derniers jours : <b>' . money($s['net']) . '</b> pour ' . $s['tickets'] . ' ventes, panier moyen ' . money($s['avg_basket']) . ($var !== null ? ' (' . ($var >= 0 ? '+' : '') . $var . ' % par rapport aux 30 jours précédents)' : '') . '.';
                }
                $top = ReportService::topProducts($from, $to, null, 3);
                if ($top) {
                    $f[] = 'Vos 3 locomotives : ' . e(implode(', ', array_column($top, 'name'))) . '.';
                }
                $hours = ReportService::byHour($from, $to);
                if (count($hours) >= 3) {
                    usort($hours, fn($a, $b) => $a['total'] <=> $b['total']);
                    $f[] = 'Heure la plus calme : ' . sprintf('%02d h – %02d h', $hours[0]['h'], $hours[0]['h'] + 1) . ' : bon moment pour une promotion.';
                }
                if (Auth::can('reports.financial') && $s['discounts'] > 0 && $s['margin'] > 0) {
                    $f[] = 'Les remises ont coûté ' . money($s['discounts']) . ', soit ' . round($s['discounts'] / $s['margin'] * 100) . ' % de la marge.';
                }
            }
            if (in_array($theme, ['stock', 'tresorerie', 'ventes'], true) && Auth::can('stock.view')) {
                $dormant = DB::one("SELECT COUNT(*) n, COALESCE(SUM(st.q * p.cost_price),0) v FROM products p JOIN (SELECT product_id, SUM(quantity) q FROM stock_levels GROUP BY product_id) st ON st.product_id = p.id
                    WHERE p.status = 'active' AND p.track_stock = 1 AND st.q > 0 AND NOT EXISTS (SELECT 1 FROM sale_items y JOIN sales x ON x.id = y.sale_id WHERE y.product_id = p.id AND x.status <> 'cancelled' AND x.created_at >= NOW() - INTERVAL 30 DAY)");
                if ((int) $dormant['n'] > 0) {
                    $f[] = '<b>' . (int) $dormant['n'] . ' article(s)</b> ne se sont pas vendus depuis 30 jours : ' . money($dormant['v']) . ' immobilisés.';
                }
                $low = count(array_filter(ReportService::lowStock(200), fn($r) => (float) $r['qty'] <= 0));
                if ($low) {
                    $f[] = '<b>' . $low . ' article(s) en rupture</b> : ce sont des ventes perdues chaque jour.';
                }
            }
            if (in_array($theme, ['tresorerie', 'fidelite'], true) && Auth::can('reports.financial')) {
                $rc = ReportService::receivables();
                if ($rc > 0) {
                    $f[] = 'Vos clients vous doivent <b>' . money($rc) . '</b>.';
                }
                $rp = ReportService::payables();
                if ($rp > 0) {
                    $f[] = 'Vous devez ' . money($rp) . ' à vos fournisseurs.';
                }
            }
            if (in_array($theme, ['pertes', 'personnel'], true) && Auth::can('cash.view_all')) {
                $gap = DB::one("SELECT COUNT(*) n, COALESCE(SUM(difference),0) d FROM cash_sessions WHERE status = 'closed' AND closed_at >= NOW() - INTERVAL 30 DAY AND difference < -0.009");
                $f[] = (int) $gap['n'] > 0 ? '<b>' . (int) $gap['n'] . ' clôture(s)</b> avec un manque ce mois-ci, total ' . money($gap['d']) . '.' : 'Aucun manque de caisse sur les 30 derniers jours : bravo.';
            }
            if ($theme === 'depenses' && Auth::can('expenses.manage')) {
                $e = DB::all("SELECT c.name, SUM(e.amount) t FROM expenses e JOIN expense_categories c ON c.id = e.category_id WHERE e.status = 'valid' AND e.type = 'expense' AND e.expense_date >= ? GROUP BY c.name ORDER BY t DESC LIMIT 3", [$from]);
                if ($e) {
                    $f[] = 'Premiers postes de dépenses sur 30 jours : ' . e(implode(', ', array_map(fn($r) => $r['name'] . ' ' . money($r['t']), $e))) . '.';
                }
            }
        } catch (\Throwable) {
            // Les constats sont un plus : jamais d'erreur visible pour un conseil
        }
        return $f;
    }

    /** Thèmes métier proposés selon les modules actifs. */
    public static function activityThemes(): array
    {
        $map = ['restaurant' => null, 'hotel' => 'hotel', 'ecole' => 'ecole', 'pressing' => 'pressing'];
        $out = [];
        foreach ($map as $theme => $addon) {
            if ($addon === null ? feature('restaurant') : Addons::isActive($addon)) {
                $out[] = $theme;
            }
        }
        return $out;
    }
}
