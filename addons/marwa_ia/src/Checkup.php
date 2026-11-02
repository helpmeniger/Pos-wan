<?php
declare(strict_types=1);

namespace Addons\MarwaIa;

use App\Core\Auth;
use App\Core\DB;
use App\Services\BackupService;
use App\Services\LicenceService;

/**
 * Contrôle de configuration : « qu'ai-je oublié ? ». Passe en revue les réglages et les
 * données de base, et donne pour chaque point le lien de l'écran où le corriger.
 * Chaque contrôle ne s'affiche que si le rôle peut agir dessus.
 */
final class Checkup
{
    public static function isCheckup(string $n): bool
    {
        return (bool) preg_match('/\b(verifie\w*|controle\w*|diagnostic|audit)\b.{0,30}\b(config\w*|parametr\w*|reglages?|installation|boutique|logiciel|tout)\b|\b(qu ai je oublie|j ai oublie|ai je oublie|oubli\w* (de )?(config|parametr|regl)\w*|qu est ce que j ai oublie|qu est ce qui manque|que manque t il|ce qui manque|est ce que tout est bien (configure|parametre|regle)|ma configuration est elle|configuration complete|bien configure)\b/', $n);
    }

    /** @return array{ok:list<array>, todo:list<array>} chaque point : [niveau, texte, lien, libellé] */
    public static function run(): array
    {
        $todo = [];
        $ok = [];
        $add = function (bool $good, string $level, string $okText, string $badText, string $path, string $label) use (&$todo, &$ok) {
            if ($good) {
                $ok[] = ['ok', $okText, null, null];
            } else {
                $todo[] = [$level, $badText, $path, $label];
            }
        };
        $q = function (string $sql, array $p = []): int {
            try {
                return (int) (DB::one($sql, $p)['n'] ?? 0);
            } catch (\Throwable) {
                return 0;
            }
        };

        if (Auth::can('settings.manage')) {
            $name = trim((string) setting('company_name', ''));
            $add($name !== '' && !preg_match('/^(pos ?marwa|ma boutique|boutique|demo|test)$/i', $name), 'important', 'Nom de l\'entreprise renseigné.', 'Le <b>nom de l\'entreprise</b> n\'est pas personnalisé : il s\'imprime sur chaque ticket et facture.', '/settings', 'Paramètres');
            $add(trim((string) setting('company_phone', '')) !== '', 'important', 'Téléphone de l\'entreprise renseigné.', 'Le <b>téléphone</b> de l\'entreprise manque sur les tickets.', '/settings', 'Paramètres');
            $add(trim((string) setting('company_address', '')) !== '', 'conseil', 'Adresse renseignée.', 'L\'<b>adresse</b> de l\'entreprise n\'est pas renseignée.', '/settings', 'Paramètres');
            $add(trim((string) setting('company_logo', '')) !== '', 'conseil', 'Logo en place.', 'Pas de <b>logo</b> : vos tickets et factures seraient plus professionnels avec.', '/settings', 'Paramètres');
            $add(trim((string) setting('company_nif', '')) !== '', 'conseil', 'NIF renseigné.', 'Le <b>NIF</b> n\'est pas renseigné (obligatoire sur les factures aux entreprises).', '/settings', 'Paramètres');
            $methods = array_filter(explode(',', (string) setting('payment_methods', 'cash')));
            $add(count($methods) >= 2, 'conseil', 'Plusieurs moyens de paiement acceptés.', 'Seules les <b>espèces</b> sont activées : ajoutez le mobile money pour ne pas perdre de clients.', '/settings', 'Moyens de paiement');
            if (in_array('mobile_money', $methods, true)) {
                $add(trim((string) setting('mobile_money_providers', '')) !== '', 'conseil', 'Opérateurs mobile money listés.', 'Le mobile money est activé mais aucun <b>opérateur</b> (Airtel, Moov, Zamani…) n\'est listé.', '/settings', 'Paramètres');
            }
            $add($q('SELECT COUNT(*) n FROM printers WHERE active = 1') > 0, 'conseil', 'Imprimante configurée.', 'Aucune <b>imprimante</b> déclarée : les tickets passent par la fenêtre d\'impression du navigateur.', '/printers', 'Imprimantes');
        }
        if (Auth::can('backup.manage')) {
            try {
                $list = BackupService::list();
                $last = $list[0]['date'] ?? null;
                $age = $last ? (int) floor((time() - strtotime($last)) / 86400) : null;
                $add($age !== null && $age <= 7, 'urgent', 'Dernière sauvegarde : ' . ($last ? date('d/m/Y', strtotime($last)) : '') . '.', $last ? 'Dernière <b>sauvegarde</b> il y a ' . $age . ' jours : faites-en une et copiez-la sur une clé USB.' : 'Aucune <b>sauvegarde</b> trouvée : en cas de panne du PC, tout serait perdu.', '/backups', 'Sauvegardes');
            } catch (\Throwable) {
            }
        }
        if (Auth::can('settings.manage')) {
            try {
                $l = LicenceService::status();
                if ($l['state'] === 'licensed') {
                    $add($l['days_left'] === null || $l['days_left'] > 15, 'important', 'Licence active.', 'La <b>licence</b> expire dans ' . (int) $l['days_left'] . ' jours.', '/licence', 'Licence');
                } else {
                    $add(false, $l['state'] === 'expired' ? 'urgent' : 'important', '', $l['state'] === 'expired' ? 'La période d\'essai est <b>terminée</b> : activez votre licence.' : 'Version d\'essai : <b>' . (int) $l['days_left'] . ' jours</b> restants. Pensez à activer la licence.', '/licence', 'Licence');
                }
            } catch (\Throwable) {
            }
        }
        if (Auth::can('users.manage')) {
            $add($q("SELECT COUNT(*) n FROM users WHERE username = 'admin' AND active = 1 AND password_changed_at IS NULL") === 0, 'urgent', 'Pas de compte « admin » avec le mot de passe d\'origine.', 'Le compte <b>admin</b> n\'a jamais changé de mot de passe : changez-le tout de suite.', '/users', 'Utilisateurs');
            $cashiers = $q("SELECT COUNT(*) n FROM users u JOIN roles r ON r.id = u.role_id WHERE u.active = 1 AND r.code = 'cashier' AND u.register_id IS NULL");
            $add($cashiers === 0, 'conseil', 'Chaque caissier a sa caisse.', '<b>' . $cashiers . ' caissier(s)</b> sans caisse attribuée.', '/users', 'Utilisateurs');
        }
        if (Auth::can('products.manage') || Auth::can('stock.view')) {
            $active = $q("SELECT COUNT(*) n FROM products WHERE status = 'active'");
            $add($active > 0, 'urgent', $active . ' articles actifs.', 'Aucun <b>article</b> enregistré : commencez par là (ou importez votre liste Excel).', '/products/create', 'Ajouter un article');
            if ($active > 0) {
                $noCost = $q("SELECT COUNT(*) n FROM products WHERE status = 'active' AND cost_price <= 0");
                $add($noCost === 0, 'important', 'Tous les articles ont un prix d\'achat.', '<b>' . $noCost . ' article(s) sans prix d\'achat</b> : le bénéfice affiché est faux pour eux.', '/products', 'Articles');
                $loss = $q("SELECT COUNT(*) n FROM products WHERE status = 'active' AND cost_price > 0 AND sale_price > 0 AND sale_price < cost_price");
                $add($loss === 0, 'urgent', 'Aucun article vendu à perte.', '<b>' . $loss . ' article(s) vendus en dessous du prix d\'achat</b>.', '/products', 'Articles');
                $noCode = $q("SELECT COUNT(*) n FROM products WHERE status = 'active' AND (barcode IS NULL OR barcode = '')");
                $add($noCode * 5 < $active, 'conseil', 'La plupart des articles ont un code-barres.', '<b>' . $noCode . ' article(s) sans code-barres</b> : générez-les et imprimez les étiquettes pour vendre plus vite.', '/products/labels', 'Étiquettes');
                $noMin = $q("SELECT COUNT(*) n FROM products WHERE status = 'active' AND track_stock = 1 AND min_stock <= 0");
                $add($noMin * 2 < $active, 'conseil', 'Seuils d\'alerte de stock en place.', '<b>' . $noMin . ' article(s) sans stock minimum</b> : sans seuil, pas d\'alerte avant la rupture.', '/products', 'Articles');
                $neg = $q('SELECT COUNT(*) n FROM (SELECT product_id, SUM(quantity) q FROM stock_levels GROUP BY product_id HAVING q < 0) x');
                $add($neg === 0, 'important', 'Aucun stock négatif.', '<b>' . $neg . ' article(s) en stock négatif</b> : un achat ou un inventaire n\'a pas été saisi.', '/stock', 'Stock');
            }
        }
        if (Auth::can('stock.view')) {
            $draft = $q("SELECT COUNT(*) n FROM inventories WHERE status = 'draft' AND created_at < NOW() - INTERVAL 2 DAY");
            $add($draft === 0, 'important', 'Pas d\'inventaire oublié.', '<b>' . $draft . ' inventaire(s) commencé(s)</b> jamais validé(s) : le stock n\'a pas été corrigé.', '/inventories', 'Inventaires');
            $exp = $q('SELECT COUNT(*) n FROM stock_lots WHERE quantity > 0 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()');
            $add($exp === 0, 'urgent', 'Aucun lot périmé en stock.', '<b>' . $exp . ' lot(s) périmé(s)</b> encore en stock : retirez-les de la vente.', '/stock/expiry', 'Péremptions');
        }
        if (Auth::can('cash.view_all')) {
            $open = $q("SELECT COUNT(*) n FROM cash_sessions WHERE status = 'open' AND opened_at < NOW() - INTERVAL 24 HOUR");
            $add($open === 0, 'important', 'Toutes les caisses sont clôturées chaque jour.', '<b>' . $open . ' caisse(s) ouverte(s) depuis plus de 24 h</b> : clôturez chaque soir pour contrôler l\'argent.', '/cash/sessions', 'Sessions de caisse');
        }
        if (Auth::can('customers.view') || Auth::can('reports.financial')) {
            $over = $q('SELECT COUNT(*) n FROM customers WHERE active = 1 AND credit_limit > 0 AND balance > credit_limit');
            $add($over === 0, 'important', 'Aucun client au-dessus de son plafond de crédit.', '<b>' . $over . ' client(s) au-dessus de leur plafond de crédit</b> : relancez-les avant de leur vendre à crédit.', '/customers', 'Clients');
        }
        if (Auth::can('expenses.manage')) {
            $add($q("SELECT COUNT(*) n FROM expense_categories WHERE type = 'expense'") > 0, 'conseil', 'Catégories de dépenses créées.', 'Aucune <b>catégorie de dépenses</b> (loyer, électricité, salaires…) : le bénéfice net ne peut pas être calculé.', '/expenses', 'Dépenses');
        }
        $rank = ['urgent' => 0, 'important' => 1, 'conseil' => 2];
        usort($todo, fn($a, $b) => $rank[$a[0]] <=> $rank[$b[0]]);
        return ['ok' => $ok, 'todo' => $todo];
    }

    public static function answer(): array
    {
        $r = self::run();
        $total = count($r['ok']) + count($r['todo']);
        if ($total === 0) {
            return ['ok' => true, 'type' => 'chat', 'html' => '<p>Votre rôle ne donne pas accès aux réglages à contrôler. Demandez au gérant de lancer la vérification.</p>', 'actions' => [], 'suggestions' => [], 'spec' => null];
        }
        $score = (int) round(count($r['ok']) / $total * 100);
        $html = '<p class="h">Contrôle de votre configuration</p><div class="mia-score" style="--s:' . $score . '"><b>' . $score . ' %</b><span>' . count($r['ok']) . ' point(s) sur ' . $total . ' en ordre</span></div>';
        if ($r['todo']) {
            $labels = ['urgent' => 'À faire tout de suite', 'important' => 'Important', 'conseil' => 'Conseillé'];
            $html .= '<ul class="mia-check">';
            foreach ($r['todo'] as [$lvl, $txt, $path]) {
                $html .= '<li class="' . $lvl . '"><span class="lvl">' . $labels[$lvl] . '</span> ' . $txt . ' <a href="' . e(url($path)) . '">Corriger</a></li>';
            }
            $html .= '</ul>';
        } else {
            $html .= '<p>Tout est en ordre : rien n\'a été oublié. Bravo !</p>';
        }
        if ($r['ok']) {
            $html .= '<details><summary>Ce qui est déjà en ordre (' . count($r['ok']) . ')</summary><ul>' . implode('', array_map(fn($x) => '<li>' . $x[1] . '</li>', $r['ok'])) . '</ul></details>';
        }
        $html .= Wisdom::html(Wisdom::forTheme('erreurs'));
        $actions = [];
        foreach ($r['todo'] as [, , $path, $label]) {
            if (!in_array(url($path), array_column($actions, 'url'), true)) {
                $actions[] = ['label' => $label, 'url' => url($path), 'kind' => 'link'];
            }
        }
        return ['ok' => true, 'type' => 'checkup', 'html' => $html, 'score' => $score, 'actions' => array_slice($actions, 0, 4), 'suggestions' => ['Forme-moi à la caisse', 'Comment faire une sauvegarde ?', 'Bilan de la journée'], 'spec' => null];
    }

    /** Nombre de points à corriger (pour le briefing). */
    public static function count(): int
    {
        return count(array_filter(self::run()['todo'], fn($x) => $x[0] !== 'conseil'));
    }
}
