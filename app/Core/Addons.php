<?php
declare(strict_types=1);

namespace App\Core;

use App\Services\BackupService;
use App\Services\InstallService;

/**
 * Modules complémentaires (« addons »), à la manière d'Odoo : un module est un dossier
 * déposé dans addons/<code>/ ; il apparaît dans Gestion → Modules où un administrateur
 * l'installe (sauvegarde automatique + création de ses tables), l'active ou le désactive.
 *
 * Contenu d'un module (seul manifest.json est obligatoire) :
 *   manifest.json   nom, version, description, permissions, menu, paramètres…
 *   routes.php      routes du module ($router disponible, comme routes/web.php)
 *   src/            classes PHP, espace de noms Addons\<CodeEnCamelCase>\
 *   views/          gabarits, appelés par view('<code>::nom')
 *   migrations/     NNNN_nom.sql, appliquées à l'installation et aux mises à jour
 *   lang/           traductions (en.php, ar.php…) clé = texte français
 *   public/         CSS / JS / images, publiés dans public/assets/addons/<code>/
 *
 * Un module est du code PHP exécuté avec les droits de l'application : n'installer que
 * des modules de confiance. Désactiver un module conserve ses données.
 */
final class Addons
{
    public const CODE_REGEX = '/^[a-z][a-z0-9_]{1,40}$/';

    private static ?array $installed = null;
    private static ?array $discovered = null;

    public static function dir(): string
    {
        return BASE_PATH . '/addons';
    }

    /** « mes_notes » → « MesNotes » (espace de noms Addons\MesNotes\). */
    public static function studly(string $code): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $code)));
    }

    /** Chargement automatique des classes Addons\<Module>\… depuis addons/<code>/src/. */
    public static function registerAutoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            if (!str_starts_with($class, 'Addons\\')) {
                return;
            }
            $parts = explode('\\', substr($class, 7), 2);
            if (count($parts) !== 2) {
                return;
            }
            foreach (self::discover() as $code => $m) {
                if (self::studly($code) === $parts[0]) {
                    $file = self::dir() . '/' . $code . '/src/' . str_replace('\\', '/', $parts[1]) . '.php';
                    if (is_file($file)) {
                        require $file;
                    }
                    return;
                }
            }
        });
    }

    /**
     * Modules présents dans addons/ (installés ou non), avec leur manifeste validé.
     * @return array<string, array> code => manifeste + ['errors' => string[]]
     */
    public static function discover(bool $refresh = false): array
    {
        if (self::$discovered !== null && !$refresh) {
            return self::$discovered;
        }
        $list = [];
        foreach (glob(self::dir() . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $folder = basename($path);
            if (!preg_match(self::CODE_REGEX, $folder)) {
                continue; // dossiers techniques ou noms invalides ignorés
            }
            $m = ['code' => $folder, 'errors' => []];
            $file = $path . '/manifest.json';
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (!is_array($data)) {
                $m['name'] = $folder;
                $m['errors'][] = is_file($file) ? 'manifest.json illisible (JSON invalide).' : 'manifest.json absent.';
                $list[$folder] = $m;
                continue;
            }
            $m = array_merge($data, $m);
            if (($data['code'] ?? $folder) !== $folder) {
                $m['errors'][] = 'Le code du manifeste doit être identique au nom du dossier (« ' . $folder . ' »).';
            }
            if (trim((string) ($data['name'] ?? '')) === '') {
                $m['errors'][] = 'Nom du module manquant.';
            }
            if (!preg_match('/^\d+\.\d+(\.\d+)?$/', (string) ($data['version'] ?? ''))) {
                $m['errors'][] = 'Version invalide (attendu : 1.0.0).';
            }
            $req = (string) ($data['requires'] ?? '');
            if ($req !== '' && version_compare((string) config('app.version'), $req, '<')) {
                $m['errors'][] = 'Nécessite POS Wan ' . $req . ' ou plus récent (version actuelle : ' . config('app.version') . ').';
            }
            foreach (array_keys((array) ($data['permissions'] ?? [])) as $p) {
                if (!preg_match('/^[a-z][a-z0-9_]*\.[a-z0-9_]+$/', (string) $p)) {
                    $m['errors'][] = 'Permission invalide : ' . $p;
                }
            }
            foreach ((array) ($data['menu'] ?? []) as $it) {
                if (!str_starts_with((string) ($it['url'] ?? ''), '/') || str_starts_with((string) $it['url'], '//')) {
                    $m['errors'][] = 'Entrée de menu invalide (adresse locale « /… » attendue).';
                }
            }
            $list[$folder] = $m;
        }
        ksort($list);
        return self::$discovered = $list;
    }

    /** Modules installés (table addons) : code => ligne. */
    public static function installed(): array
    {
        if (self::$installed !== null) {
            return self::$installed;
        }
        try {
            $rows = DB::all('SELECT * FROM addons');
        } catch (\Throwable) {
            return self::$installed = []; // avant installation / migration
        }
        return self::$installed = array_column($rows, null, 'code');
    }

    public static function reset(): void
    {
        self::$installed = null;
        self::$discovered = null;
    }

    /** Modules actifs et utilisables (installés, activés, dossier présent et valide). */
    public static function active(): array
    {
        $out = [];
        foreach (self::installed() as $code => $row) {
            $m = self::discover()[$code] ?? null;
            if ((int) $row['active'] === 1 && $m && !$m['errors']) {
                $out[$code] = $m;
            }
        }
        return $out;
    }

    public static function isActive(string $code): bool
    {
        return isset(self::active()[$code]);
    }

    /** État affiché dans la page Modules. */
    public static function status(string $code): string
    {
        $m = self::discover()[$code] ?? null;
        $row = self::installed()[$code] ?? null;
        if (!$m) {
            return 'missing';
        }
        if ($m['errors']) {
            return 'error';
        }
        if (!$row) {
            return 'available';
        }
        if (version_compare((string) $m['version'], (string) $row['version'], '>')) {
            return 'upgrade';
        }
        return (int) $row['active'] === 1 ? 'active' : 'inactive';
    }

    // ------------------------------------------------------------------ intégration

    public static function registerRoutes(Router $router): void
    {
        foreach (self::active() as $code => $m) {
            $file = self::dir() . '/' . $code . '/routes.php';
            if (is_file($file)) {
                (static function (string $__file, Router $router): void {
                    require $__file;
                })($file, $router);
            }
        }
    }

    /** Entrées de menu des modules actifs : groupe => [[libellé, icône, url, permission, couleur], …]. */
    public static function menu(): array
    {
        $groups = [];
        foreach (self::active() as $m) {
            foreach ((array) ($m['menu'] ?? []) as $it) {
                $g = (string) ($it['group'] ?? $m['name']);
                $groups[$g][] = [(string) $it['label'], (string) ($it['icon'] ?? 'bi-puzzle'), (string) $it['url'], isset($it['permission']) ? (string) $it['permission'] : null, (string) ($it['color'] ?? '#5d6d7e')];
            }
        }
        return $groups;
    }

    /** Permissions des modules installés, groupées par module (pour le catalogue des rôles). */
    public static function permissions(): array
    {
        $out = [];
        foreach (self::installed() as $code => $row) {
            $m = self::discover()[$code] ?? null;
            if ($m && !$m['errors'] && !empty($m['permissions'])) {
                $out['Module : ' . $m['name']] = array_map('strval', (array) $m['permissions']);
            }
        }
        return $out;
    }

    /** Traductions d'un module actif pour une langue. */
    public static function dictionary(string $locale): array
    {
        $d = [];
        foreach (array_keys(self::active()) as $code) {
            $f = self::dir() . '/' . $code . '/lang/' . $locale . '.php';
            if (is_file($f)) {
                $x = require $f;
                if (is_array($x)) {
                    $d += $x;
                }
            }
        }
        return $d;
    }

    /** Chemin d'une vue « code::nom » (null si le module n'est pas actif). */
    public static function viewFile(string $template): ?string
    {
        [$code, $name] = explode('::', $template, 2);
        if (!self::isActive($code) || !preg_match('#^[a-z0-9_/-]+$#i', $name) || str_contains($name, '..')) {
            return null;
        }
        return self::dir() . '/' . $code . '/views/' . $name . '.php';
    }

    // ------------------------------------------------------------------ gestion

    private static function manifestOrFail(string $code): array
    {
        if (!preg_match(self::CODE_REGEX, $code)) {
            throw new BusinessException('Code de module invalide.');
        }
        $m = self::discover(true)[$code] ?? null;
        if (!$m) {
            throw new BusinessException('Module introuvable dans le dossier addons/.');
        }
        if ($m['errors']) {
            throw new BusinessException('Module « ' . $m['name'] . ' » invalide : ' . implode(' ', $m['errors']));
        }
        return $m;
    }

    /** Installe ou met à jour un module : sauvegarde, migrations, paramètres, permissions, fichiers publics. */
    public static function install(string $code, bool $backup = true): array
    {
        $m = self::manifestOrFail($code);
        $row = self::installed()[$code] ?? null;
        if ($backup) {
            BackupService::create(($row ? 'avant_maj_module_' : 'avant_module_') . $code);
        }
        $done = array_column(DB::all('SELECT version FROM schema_migrations WHERE version LIKE ?', ['addon:' . $code . ':%']), 'version');
        $applied = [];
        $files = glob(self::dir() . '/' . $code . '/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $f) {
            $v = 'addon:' . $code . ':' . basename($f, '.sql');
            if (in_array($v, $done, true)) {
                continue;
            }
            InstallService::runSqlFile($f);
            DB::run('INSERT INTO schema_migrations (version) VALUES (?)', [$v]);
            $applied[] = basename($f, '.sql');
        }
        foreach ((array) ($m['settings'] ?? []) as $k => $v) {
            if (preg_match('/^[a-z0-9_]{1,60}$/', (string) $k) && DB::value('SELECT 1 FROM settings WHERE skey = ?', [$k]) === null) {
                setting_set((string) $k, (string) $v); // valeur par défaut, jamais écrasée
            }
        }
        if ($row) {
            DB::update('addons', ['version' => $m['version'], 'name' => $m['name']], 'code = ?', [$code]);
        } else {
            DB::insert('addons', ['code' => $code, 'name' => $m['name'], 'version' => $m['version'], 'active' => 1]);
        }
        self::$installed = null;
        self::grantPermissions($m);
        self::publishAssets($code);
        Audit::log($row ? 'addon.upgrade' : 'addon.install', 'addon', null, ['code' => $code, 'version' => $m['version'], 'migrations' => $applied]);
        return $applied;
    }

    /** Enregistre les permissions du module et les accorde aux rôles prévus (et à l'administrateur). */
    private static function grantPermissions(array $m): void
    {
        $perms = (array) ($m['permissions'] ?? []);
        foreach ($perms as $p => $label) {
            DB::run('INSERT INTO permissions (code, module, label) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE module = VALUES(module), label = VALUES(label)', [$p, 'Module : ' . $m['name'], (string) $label]);
        }
        // Nouveaux rôles proposés par le module (créés une seule fois, modifiables ensuite dans
        // Rôles & permissions ; jamais écrasés lors d'une mise à jour)
        foreach ((array) ($m['new_roles'] ?? []) as $code => $def) {
            if (!preg_match('/^[a-z][a-z0-9_]{1,40}$/', (string) $code) || DB::value('SELECT id FROM roles WHERE code = ?', [$code])) {
                continue;
            }
            $roleId = DB::insert('roles', ['code' => $code, 'name' => mb_substr((string) ($def['name'] ?? $code), 0, 100), 'description' => mb_substr((string) ($def['description'] ?? ''), 0, 255) ?: null, 'is_system' => 0]);
            foreach ((array) ($def['permissions'] ?? []) as $p) {
                DB::run('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE code = ?', [$roleId, (string) $p]);
            }
        }
        $grants = (array) ($m['roles'] ?? []);
        $grants['admin'] = array_keys($perms); // l'administrateur général reçoit toujours tout
        foreach ($grants as $role => $list) {
            $roleId = DB::value('SELECT id FROM roles WHERE code = ?', [(string) $role]);
            if (!$roleId) {
                continue;
            }
            foreach ((array) $list as $p) {
                if (isset($perms[$p])) {
                    DB::run('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE code = ?', [$roleId, $p]);
                }
            }
        }
    }

    /** Copie addons/<code>/public/ vers public/assets/addons/<code>/ (fichiers statiques seulement). */
    public static function publishAssets(string $code): void
    {
        $src = self::dir() . '/' . $code . '/public';
        if (!is_dir($src)) {
            return;
        }
        $dst = PUBLIC_PATH . '/assets/addons/' . $code;
        $allowed = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'woff', 'woff2', 'ttf', 'json', 'txt'];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($src) + 1);
            if (!$f->isFile() || !in_array(strtolower($f->getExtension()), $allowed, true) || str_contains($rel, '..')) {
                continue; // jamais de .php ni de .htaccess dans le dossier public
            }
            $target = $dst . '/' . $rel;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            copy($f->getPathname(), $target);
        }
    }

    public static function setActive(string $code, bool $active): void
    {
        $row = self::installed()[$code] ?? null;
        if (!$row) {
            throw new BusinessException('Module non installé.');
        }
        if ($active) {
            self::manifestOrFail($code);
        }
        DB::update('addons', ['active' => $active ? 1 : 0], 'code = ?', [$code]);
        self::$installed = null;
        Audit::log($active ? 'addon.enable' : 'addon.disable', 'addon', null, ['code' => $code]);
    }
}
