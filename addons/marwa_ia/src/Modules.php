<?php
declare(strict_types=1);

namespace Addons\MarwaIa;

use App\Core\Addons;
use App\Core\Auth;
use App\Core\DB;

/**
 * État rapide des modules métier actifs (restaurant, hôtel, établissement, clinique,
 * banques, pressing) : seulement les modules installés et autorisés pour le rôle.
 */
final class Modules
{
    /** module => [expression, condition d'accès] */
    private const MAP = [
        'restaurant' => '/\b(restaurant|tables? (occupees?|libres?)|cuisine|salle du restaurant)\b/',
        'hotel' => '/\b(hotel|chambres?|occupation|taux d occupation|arrivees?|departs?|reservations?|clients? de l hotel)\b/',
        'ecole' => '/\b(ecole|etablissement|eleves?|scolarite|frais scolaires|impayes? (de l ecole|scolaires?)|parents?)\b/',
        'clinique' => '/\b(clinique|patients?|consultations?|hospitalis\w*|malades?|salle d attente)\b/',
        'banques' => '/\b(banques?|comptes? bancaires?|coffres?|solde (de|des) (mes )?(banques?|comptes?)|argent (en|a la) banque)\b/',
        'pressing' => '/\b(pressing|depots?|linge|vetements? (prets?|en retard))\b/',
    ];

    public static function detect(string $n): ?string
    {
        if (!preg_match('/\b(etat|point|situation|comment (va|vont|se passe)|combien|quoi de neuf|resume|tableau de bord|ou en est|aujourd hui|en ce moment|en cours|actuellement|ou en sont|statut|taux d occupation|occupation)\b/', $n)
            && !preg_match('/^(et )?(le |la |l |les )?(restaurant|hotel|ecole|etablissement|clinique|banques?|pressing)\s*\??$/', $n)) {
            return null;
        }
        foreach (self::MAP as $m => $re) {
            if (preg_match($re, $n)) {
                return self::available($m) ? $m : '!' . $m;
            }
        }
        return null;
    }

    public static function available(string $m): bool
    {
        try {
            return match ($m) {
                'restaurant' => feature('restaurant') && (Auth::can('restaurant.manage') || Auth::can('restaurant.order')),
                'hotel' => Addons::isActive('hotel') && Auth::can('hotel.view'),
                'ecole' => Addons::isActive('ecole') && (Auth::can('ecole.finance') || Auth::can('ecole.view')),
                'clinique' => Addons::isActive('clinique') && Auth::can('clinique.view'),
                'banques' => Addons::isActive('banques') && Auth::can('banques.view'),
                'pressing' => Addons::isActive('pressing') && Auth::can('pressing.view'),
                default => false,
            };
        } catch (\Throwable) {
            return false;
        }
    }

    /** Points clés d'un module : [titre, [constats], [[libellé, chemin]]] */
    public static function facts(string $m): array
    {
        $one = fn(string $sql, array $p = []) => DB::one($sql, $p) ?: [];
        $today = date('Y-m-d');
        switch ($m) {
            case 'restaurant':
                $o = $one("SELECT COUNT(*) n, COALESCE(SUM(open_table),0) t FROM restaurant_orders WHERE status IN ('open','billed')");
                $tables = (int) ($one('SELECT COUNT(*) n FROM restaurant_tables WHERE active = 1')['n'] ?? 0);
                return ['Restaurant', ['<b>' . (int) $o['n'] . '</b> commande(s) en cours, <b>' . (int) $o['t'] . ' / ' . $tables . '</b> table(s) occupée(s).'], [['Plan de salle', '/restaurant']]];
            case 'hotel':
                $rooms = (int) ($one('SELECT COUNT(*) n FROM hotel_rooms WHERE active = 1')['n'] ?? 0);
                $occ = (int) ($one("SELECT COUNT(*) n FROM hotel_stays WHERE status = 'en_cours'")['n'] ?? 0);
                $arr = (int) ($one("SELECT COUNT(*) n FROM hotel_stays WHERE status IN ('reservee','confirmee') AND arrival = ?", [$today])['n'] ?? 0);
                $dep = (int) ($one("SELECT COUNT(*) n FROM hotel_stays WHERE status = 'en_cours' AND departure <= ?", [$today])['n'] ?? 0);
                $rate = $rooms > 0 ? round($occ / $rooms * 100) : 0;
                return ['Hôtel', ['Taux d\'occupation : <b>' . $rate . ' %</b> (' . $occ . ' chambre(s) occupée(s) sur ' . $rooms . ').', '<b>' . $arr . '</b> arrivée(s) attendue(s) et <b>' . $dep . '</b> départ(s) prévu(s) aujourd\'hui.'], [['Tableau de bord hôtel', '/hotel'], ['Plan des chambres', '/hotel/plan']]];
            case 'ecole':
                $f = ['Établissement', [], [['Tableau de bord', '/ecole']]];
                if (Auth::can('ecole.finance')) {
                    $r = $one('SELECT COUNT(DISTINCT e.id) n, COALESCE(SUM(e.montant - e.paye),0) d FROM ecole_echeances e WHERE e.annule = 0 AND e.paye < e.montant AND e.date_limite IS NOT NULL AND e.date_limite < CURDATE()');
                    $f[1][] = 'Échéances dépassées non soldées : <b>' . (int) $r['n'] . '</b>, reste à recouvrer <b>' . money($r['d']) . '</b>.';
                    $f[2][] = ['Caisse établissement', '/ecole/caisse'];
                } else {
                    $f[1][] = 'Le suivi des paiements demande le droit « finances de l\'établissement ».';
                }
                return $f;
            case 'clinique':
                $w = (int) ($one("SELECT COUNT(*) n FROM clinic_visits WHERE status = 'waiting' AND DATE(created_at) = ?", [$today])['n'] ?? 0);
                $s = (int) ($one("SELECT COUNT(*) n FROM clinic_stays WHERE status = 'admitted'")['n'] ?? 0);
                $b = (int) ($one("SELECT COUNT(*) n FROM clinic_bills WHERE status = 'open'")['n'] ?? 0);
                return ['Clinique', ['<b>' . $w . '</b> patient(s) en salle d\'attente aujourd\'hui.', '<b>' . $s . '</b> patient(s) hospitalisé(s).', '<b>' . $b . '</b> facture(s) patient ouverte(s).'], [['Accueil clinique', '/clinique'], ['Hospitalisation', '/clinique/hospitalisation']]];
            case 'banques':
                $rows = DB::all('SELECT name, balance FROM bank_accounts WHERE active = 1 ORDER BY balance DESC LIMIT 6');
                $tot = array_sum(array_column($rows, 'balance'));
                return ['Banques et coffres', array_merge(['Total disponible : <b>' . money($tot) . '</b>.'], array_map(fn($r) => e($r['name']) . ' : ' . money($r['balance']), $rows)), [['Banques et coffres', '/banques'], ['Clôtures à verser', '/banques/versements']]];
            case 'pressing':
                $ready = (int) ($one("SELECT COUNT(*) n FROM pressing_orders WHERE status = 'ready'")['n'] ?? 0);
                $late = (int) ($one("SELECT COUNT(*) n FROM pressing_orders WHERE status IN ('received','processing') AND due_date < CURDATE()")['n'] ?? 0);
                $due = $one("SELECT COALESCE(SUM(total - paid),0) d FROM pressing_orders WHERE status <> 'cancelled' AND total > paid");
                return ['Pressing', ['<b>' . $ready . '</b> dépôt(s) prêt(s) à remettre : prévenez les clients.', '<b>' . $late . '</b> dépôt(s) en retard sur la date promise.', 'Reste à encaisser : <b>' . money($due['d'] ?? 0) . '</b>.'], [['Dépôts pressing', '/pressing'], ['Écran des dépôts', '/pressing/ecran']]];
        }
        return ['', [], []];
    }

    public static function answer(string $m): array
    {
        if ($m[0] === '!') {
            $m = substr($m, 1);
            $names = ['restaurant' => 'Restaurant', 'hotel' => 'Hôtel', 'ecole' => 'Établissement', 'clinique' => 'Clinique', 'banques' => 'Banques', 'pressing' => 'Pressing'];
            $active = $m === 'restaurant' ? feature('restaurant') : Addons::isActive($m);
            $html = $active ? '<p>Votre rôle ne donne pas accès au module <b>' . $names[$m] . '</b>. Demandez au gérant.</p>'
                : '<p>Le module <b>' . $names[$m] . '</b> n\'est pas activé sur ce logiciel.' . (Auth::can('addons.manage') || Auth::can('settings.manage') ? ' Vous pouvez l\'installer dans Système → Modules complémentaires' . ($m === 'restaurant' ? ' ou l\'activer dans les Paramètres (mode de fonctionnement)' : '') . '.' : ' Demandez à l\'administrateur.') . '</p>';
            $act = !$active && Auth::can('addons.manage') && $m !== 'restaurant' ? [['label' => 'Modules complémentaires', 'url' => url('/addons'), 'kind' => 'link']] : [];
            return ['ok' => true, 'type' => 'chat', 'html' => $html, 'actions' => $act, 'suggestions' => ['Bilan de la journée', 'Que sais-tu faire ?'], 'spec' => null];
        }
        try {
            [$title, $facts, $links] = self::facts($m);
        } catch (\Throwable) {
            return ['ok' => true, 'type' => 'chat', 'html' => '<p>Je n\'arrive pas à lire l\'état de ce module pour le moment (module pas encore initialisé ?).</p>', 'actions' => [], 'suggestions' => [], 'spec' => null];
        }
        $html = '<p class="h">' . e($title) . ' : la situation maintenant</p><ul class="mia-facts">' . implode('', array_map(fn($f) => '<li>' . $f . '</li>', $facts)) . '</ul>';
        return ['ok' => true, 'type' => 'module', 'html' => $html, 'actions' => array_map(fn($l) => ['label' => $l[0], 'url' => url($l[1]), 'kind' => 'link'], $links), 'suggestions' => ['Bilan de la journée', 'Leçon : ' . (Trainer::LESSONS[$m][0] ?? 'Prise en main de POS Marwa')], 'spec' => null];
    }

    /** Une ligne par module actif, pour le briefing. */
    public static function briefing(): array
    {
        $out = [];
        foreach (array_keys(self::MAP) as $m) {
            if (self::available($m) && $m !== 'banques') {
                try {
                    $f = self::facts($m);
                    if ($f[1]) {
                        $out[] = '<b>' . e($f[0]) . '</b> : ' . strip_tags($f[1][0], '<b>');
                    }
                } catch (\Throwable) {
                }
            }
        }
        return $out;
    }
}
