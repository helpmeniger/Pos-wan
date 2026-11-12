<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

use Addons\MarwaIa\Knowledge;
use Addons\MarwaIa\Text;
use App\Core\Addons;
use App\Core\DB;
use App\Core\Router;
use App\Services\Menu;

/**
 * MARWA APPLICATION KNOWLEDGE ENGINE — inventaire automatique de l'application.
 *
 * Découvre à partir du code et de la base : modules (manifestes), menus, routes et leurs permissions, tables, colonnes,
 * clés étrangères, permissions, clés de réglage (jamais leurs valeurs), sections de la documentation.
 * L'index (ai_documentation_index) est reconstruit automatiquement dès que la version du logiciel, les modules actifs,
 * le schéma ou les permissions changent : la connaissance suit l'application au lieu de vieillir.
 */
final class AppMap
{
    /** Empreinte de ce qui fait évoluer la connaissance. */
    public static function signature(): string
    {
        $mods = [];
        foreach (Addons::active() as $code => $m) {
            $mods[] = $code . '@' . ($m['version'] ?? '?');
        }
        $schema = DB::one('SELECT COUNT(*) n, MD5(GROUP_CONCAT(CONCAT(TABLE_NAME, ".", COLUMN_NAME) ORDER BY TABLE_NAME, COLUMN_NAME)) h FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()');
        $perms = DB::value('SELECT CONCAT(COUNT(*), "-", COALESCE(MAX(id),0)) FROM permissions');
        return hash('sha256', (string) config('app.version') . '|' . implode(',', $mods) . '|' . $schema['n'] . $schema['h'] . '|' . $perms);
    }

    /** Reconstruit l'index si l'application a changé (vérifié au plus toutes les 10 minutes). */
    public static function ensureFresh(bool $force = false): bool
    {
        $checked = (int) setting('ai_index_checked_at', '0');
        if (!$force && time() - $checked < 600 && DB::value('SELECT 1 FROM ai_documentation_index LIMIT 1')) {
            return false;
        }
        setting_set('ai_index_checked_at', (string) time());
        $sig = self::signature();
        if (!$force && $sig === setting('ai_index_signature', '')) {
            return false;
        }
        self::rebuild();
        setting_set('ai_index_signature', $sig);
        return true;
    }

    /** Routes déclarées par le cœur et les modules actifs. */
    public static function routes(): array
    {
        $router = new Router();
        (static function (Router $router): void {
            require BASE_PATH . '/routes/web.php';
        })($router);
        Addons::registerRoutes($router);
        return $router->routes();
    }

    /** Entrées de menu (cœur + modules), avec leur permission. */
    public static function menu(): array
    {
        $out = [];
        $groups = Menu::groups();
        foreach (Addons::menu() as $g => $items) {
            $groups[$g] = array_merge($groups[$g] ?? [], $items);
        }
        foreach ($groups as $g => $items) {
            foreach ($items as $i) {
                $out[] = ['group' => $g, 'label' => $i[0], 'url' => $i[2], 'perm' => $i[3] ?? null, 'feature' => $i[5] ?? null];
            }
        }
        return $out;
    }

    public static function rebuild(): int
    {
        $rows = [];
        $add = function (string $kind, string $ref, string $title, ?string $body = null, ?string $module = null, ?string $perm = null) use (&$rows) {
            $rows[$kind . '|' . $ref] = [$kind, mb_substr($ref, 0, 190), mb_substr($title, 0, 255), $body !== null ? mb_substr($body, 0, 60000) : null, $module, $perm];
        };
        foreach (Addons::discover() as $code => $m) {
            $active = Addons::isActive($code);
            $add('module', $code, (string) ($m['name'] ?? $code), ($active ? '[actif] ' : '[non actif] ') . (string) ($m['description'] ?? '') . ' Version ' . ($m['version'] ?? '?') . '.', $code, null);
        }
        foreach (self::menu() as $i) {
            $add('menu', $i['url'] . '#' . $i['label'], $i['label'], 'Menu « ' . $i['group'] . ' » → ' . $i['label'] . ' (' . $i['url'] . ')', $i['group'], $i['perm']);
        }
        foreach (self::routes() as $r) {
            if ($r['public'] || !str_starts_with($r['pattern'], '/')) {
                continue;
            }
            $add('route', $r['method'] . ' ' . $r['pattern'], $r['method'] . ' ' . $r['pattern'], $r['action'], explode('/', trim($r['pattern'], '/'))[0] ?: 'accueil', $r['perm']);
        }
        foreach (DB::all('SELECT code, module, label FROM permissions') as $p) {
            $add('permission', $p['code'], $p['label'], 'Droit « ' . $p['code'] . ' » (module ' . $p['module'] . ').', $p['module'], null);
        }
        foreach (DataDictionary::schema() as $t => $d) {
            $add('table', $t, $t, ($d['description'] ? $d['description'] . ' ' : '') . 'Colonnes : ' . implode(', ', array_map(fn($c) => $c['name'] . ' (' . $c['label'] . ')', $d['columns'])) . '.'
                . ($d['fks'] ? ' Liens : ' . implode(', ', $d['fks']) . '.' : ''), null, null);
        }
        foreach (DB::all('SELECT skey FROM settings ORDER BY skey') as $sk) {
            $add('setting', $sk['skey'], $sk['skey'], 'Paramètre « ' . $sk['skey'] . ' » (valeur non indexée).', null, 'settings.manage');
        }
        foreach (Knowledge::sections() as $i => $sec) {
            $add('doc', 'doc' . $i . '-' . mb_substr((string) $sec['t'], 0, 120), (string) $sec['t'], mb_substr(strip_tags((string) $sec['x']), 0, 4000), mb_substr((string) $sec['s'], 0, 60), null);
        }
        DB::transaction(function () use ($rows) {
            DB::run('DELETE FROM ai_documentation_index');
            foreach (array_chunk(array_values($rows), 200) as $chunk) {
                DB::run('INSERT INTO ai_documentation_index (kind, ref, title, body, module, permission) VALUES ' . implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?)')), array_merge(...$chunk));
            }
        });
        return count($rows);
    }

    /** Statistiques de l'inventaire (pour « que sais-tu de l'application ? » et l'administration). */
    public static function stats(): array
    {
        self::ensureFresh();
        return array_column(DB::all('SELECT kind, COUNT(*) n FROM ai_documentation_index GROUP BY kind'), 'n', 'kind');
    }

    /** Recherche dans l'inventaire, filtrée par les droits de l'utilisateur. @return array[] */
    public static function search(string $q, UserContext $u, array $kinds = ['menu', 'module', 'doc'], int $limit = 6): array
    {
        self::ensureFresh();
        $words = array_values(array_filter(Text::words($q), fn($w) => strlen($w) >= 3));
        if (!$words) {
            return [];
        }
        $like = [];
        $p = $kinds;
        foreach (array_slice($words, 0, 6) as $w) {
            $like[] = '(title LIKE ? OR body LIKE ?)';
            $st = Text::stem($w);
            array_push($p, "%$st%", "%$st%");
        }
        $rows = DB::all('SELECT kind, ref, title, body, module, permission FROM ai_documentation_index WHERE kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ') AND (' . implode(' OR ', $like) . ') LIMIT 300', $p);
        $scored = [];
        foreach ($rows as $r) {
            if ($r['permission'] && !$u->allows([$r['permission']])) {
                continue;
            }
            $t = Text::norm($r['title']);
            $b = Text::norm((string) $r['body']);
            $s = 0.0;
            foreach ($words as $w) {
                $st = Text::stem($w);
                $s += (str_contains($t, $st) ? 3 : 0) + (str_contains($b, $st) ? 1 : 0);
            }
            $s += ['menu' => 1.5, 'module' => 1, 'doc' => 0.5][$r['kind']] ?? 0;
            $scored[] = $r + ['score' => $s];
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $limit);
    }

    /** Page du menu correspondant à une demande de navigation (« ouvre les inventaires »). */
    public static function navigate(string $q, UserContext $u): ?array
    {
        $r = self::search($q, $u, ['menu'], 1);
        if (!$r || $r[0]['score'] < 3) {
            return null;
        }
        $url = explode('#', $r[0]['ref'])[0];
        return ['label' => $r[0]['title'], 'url' => $url];
    }
}
