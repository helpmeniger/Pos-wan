<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/** Création du schéma, migrations et données de référence. */
final class InstallService
{
    public static function lockFile(): string
    {
        return STORAGE_PATH . '/installed.lock';
    }

    public static function isInstalled(): bool
    {
        return is_file(self::lockFile());
    }

    /** Migrations du logiciel (database/migrations) pas encore appliquées à la base. */
    public static function pendingMigrations(): array
    {
        $files = array_map(fn($f) => basename($f, '.sql'), glob(BASE_PATH . '/database/migrations/*.sql') ?: []);
        sort($files);
        try {
            $done = array_column(DB::all("SELECT version FROM schema_migrations WHERE version NOT LIKE 'addon:%'"), 'version');
        } catch (\Throwable) {
            return []; // base non encore installée
        }
        return array_values(array_diff($files, $done));
    }

    /**
     * Mise à jour automatique après remplacement des fichiers (WampServer, sans ligne de
     * commande) : si des migrations sont en attente, sauvegarde de sécurité puis migration.
     * Un verrou MySQL évite deux exécutions simultanées ; en cas d'échec, nouvel essai
     * au plus tôt 10 minutes plus tard (l'erreur est journalisée).
     */
    public static function autoMigrate(): array
    {
        if (!self::isInstalled() || !self::pendingMigrations()) {
            return [];
        }
        $flag = STORAGE_PATH . '/cache/migration_failed';
        if (is_file($flag) && filemtime($flag) > time() - 600) {
            return [];
        }
        if ((int) DB::value("SELECT GET_LOCK('poswan_migrate', 30)") !== 1) {
            return [];
        }
        try {
            if (!self::pendingMigrations()) {
                return [];
            }
            @set_time_limit(600);
            BackupService::create('avant_migration');
            $applied = self::migrate();
            \App\Core\Logger::info('Migrations appliquées automatiquement', ['migrations' => $applied]);
            @unlink($flag);
            return $applied;
        } catch (\Throwable $e) {
            \App\Core\Logger::error('Échec de la migration automatique : ' . $e->getMessage());
            @file_put_contents($flag, $e->getMessage());
            return [];
        } finally {
            DB::value("SELECT RELEASE_LOCK('poswan_migrate')");
        }
    }

    public static function runSqlFile(string $file): void
    {
        $sql = (string) file_get_contents($file);
        // Retire les commentaires de ligne puis découpe sur « ; » en fin de ligne
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (preg_split('/;\s*$/m', $sql) ?: [] as $stmt) {
            if (trim($stmt) !== '') {
                DB::connection()->exec($stmt);
            }
        }
    }

    public static function createSchema(): void
    {
        self::runSqlFile(BASE_PATH . '/database/schema.sql');
        DB::run("INSERT IGNORE INTO schema_migrations (version) VALUES ('0001_schema_initial')");
        self::migrate();
    }

    /** Applique les migrations database/migrations/NNNN_nom.sql non encore exécutées. */
    public static function migrate(): array
    {
        $done = array_column(DB::all('SELECT version FROM schema_migrations'), 'version');
        $applied = [];
        $files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $f) {
            $v = basename($f, '.sql');
            if (in_array($v, $done, true)) {
                continue;
            }
            self::runSqlFile($f);
            DB::run('INSERT INTO schema_migrations (version) VALUES (?)', [$v]);
            $applied[] = $v;
        }
        self::seedPermissions();
        return $applied;
    }

    /** Synchronise permissions et rôles système (idempotent). */
    public static function seedPermissions(): void
    {
        $existing = array_column(DB::all('SELECT code FROM permissions'), 'code');
        $new = [];
        foreach (Catalog::PERMISSIONS as $module => $perms) {
            foreach ($perms as $code => $label) {
                if (!in_array($code, $existing, true)) {
                    $new[] = $code;
                }
                DB::run('INSERT INTO permissions (code, module, label) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE module = VALUES(module), label = VALUES(label)', [$code, $module, $label]);
            }
        }
        // Mise à jour : les NOUVELLES permissions sont accordées aux rôles système existants
        // qui les prévoient (les choix faits par l'administrateur ne sont pas touchés).
        if ($existing && $new) {
            foreach (Catalog::ROLES as $code => [, , $perms]) {
                $roleId = DB::value('SELECT id FROM roles WHERE code = ? AND is_system = 1', [$code]);
                if (!$roleId) {
                    continue;
                }
                $grant = $perms === '*' ? $new : (is_string($perms) ? array_diff($new, explode(',', substr($perms, 8))) : array_intersect($new, $perms));
                foreach ($grant as $p) {
                    DB::run('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE code = ?', [$roleId, $p]);
                }
            }
        }
        $allCodes = array_merge(...array_values(array_map('array_keys', Catalog::PERMISSIONS)));
        foreach (Catalog::ROLES as $code => [$name, $desc, $perms]) {
            $exists = DB::value('SELECT id FROM roles WHERE code = ?', [$code]);
            if ($exists) {
                continue; // ne jamais écraser les permissions personnalisées par l'administrateur
            }
            $roleId = DB::insert('roles', ['code' => $code, 'name' => $name, 'description' => $desc, 'is_system' => 1]);
            if ($perms === '*') {
                $list = $allCodes;
            } elseif (is_string($perms) && str_starts_with($perms, '*except:')) {
                $list = array_diff($allCodes, explode(',', substr($perms, 8)));
            } else {
                $list = $perms;
            }
            foreach ($list as $p) {
                DB::run('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE code = ?', [$roleId, $p]);
            }
        }
    }

    public static function applyBusinessType(string $type): void
    {
        if (!isset(Catalog::BUSINESS_TYPES[$type])) {
            throw new BusinessException('Type de commerce inconnu.');
        }
        setting_set('business_type', $type);
        $enabled = Catalog::BUSINESS_TYPES[$type][1];
        foreach (array_keys(Catalog::FEATURES) as $f) {
            setting_set('feature_' . $f, in_array($f, $enabled, true) ? '1' : '0');
        }
    }

    /**
     * Installation complète.
     * @param array $d {company, business_type, admin_username, admin_name, admin_password, store_name, demo(bool)}
     */
    public static function install(array $d): void
    {
        if (self::isInstalled()) {
            throw new BusinessException('POS Wan est déjà installé.');
        }
        if ($err = Auth::passwordPolicyError((string) $d['admin_password'], (string) $d['admin_username'])) {
            throw new BusinessException($err);
        }
        if (!preg_match('/^[a-z0-9._-]{3,50}$/i', (string) $d['admin_username'])) {
            throw new BusinessException('Identifiant invalide (3 à 50 caractères : lettres, chiffres, . _ -).');
        }
        self::createSchema();
        DB::transaction(function () use ($d) {
            $defaults = [
                'company_name' => $d['company'], 'company_address' => 'Niamey, Niger', 'company_phone' => '', 'company_nif' => '', 'company_rccm' => '',
                'ticket_footer' => 'Merci de votre visite !', 'ticket_width' => '80', 'currency_symbol' => 'F',
                'max_discount_percent' => '10', 'allow_negative_stock' => '0', 'require_cash_session' => '1',
                'cancel_max_hours' => '24', 'return_max_days' => '30', 'credit_default_days' => '30',
                'expiry_alert_days' => '60', 'pin_login_enabled' => '1', 'rfid_enabled' => '0',
                'payment_methods' => 'cash,card,mobile_money,transfer', 'backup_keep' => '15', 'loyalty_amount_per_point' => '1000',
                'default_tax_rate' => '0',
            ];
            foreach ($defaults as $k => $v) {
                setting_set($k, (string) $v);
            }
            self::applyBusinessType((string) $d['business_type']);
            $storeId = DB::insert('stores', ['name' => $d['store_name'] ?: $d['company'], 'code' => 'B01', 'address' => 'Niamey']);
            $whId = DB::insert('warehouses', ['store_id' => $storeId, 'name' => 'Magasin principal', 'code' => 'E01', 'is_sales_point' => 1]);
            $regId = DB::insert('cash_registers', ['store_id' => $storeId, 'warehouse_id' => $whId, 'name' => 'Caisse 1']);
            $roleId = (int) DB::value("SELECT id FROM roles WHERE code = 'admin'");
            DB::insert('users', [
                'username' => $d['admin_username'], 'full_name' => $d['admin_name'] ?: 'Administrateur',
                'password_hash' => Auth::hash((string) $d['admin_password']), 'role_id' => $roleId,
                'store_id' => $storeId, 'register_id' => $regId, 'password_changed_at' => date('Y-m-d H:i:s'),
            ]);
            foreach ([['Pièce', 'pce', 0], ['Kilogramme', 'kg', 1], ['Litre', 'L', 1], ['Mètre', 'm', 1], ['Boîte', 'bte', 0], ['Sac', 'sac', 0]] as [$n, $s, $dec]) {
                DB::run('INSERT IGNORE INTO units (name, short_name, allow_decimal) VALUES (?, ?, ?)', [$n, $s, $dec]);
            }
            foreach ([['Loyer', 'expense'], ['Électricité (NIGELEC)', 'expense'], ['Eau (SEEN)', 'expense'], ['Salaires', 'expense'], ['Transport', 'expense'], ['Téléphone / Internet', 'expense'], ['Impôts et taxes', 'expense'], ['Entretien', 'expense'], ['Divers', 'expense'], ['Recette diverse', 'income'], ['Apport du propriétaire', 'income']] as [$n, $t]) {
                DB::run('INSERT IGNORE INTO expense_categories (name, type) VALUES (?, ?)', [$n, $t]);
            }
            foreach (Sequence::DEFAULTS as $name => $prefix) {
                DB::run('INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES (?, ?, 1, ?)', [$name, $prefix, (int) date('Y')]);
            }
        });
        if (!empty($d['demo'])) {
            DemoSeeder::run((string) $d['business_type']);
        }
        file_put_contents(self::lockFile(), date('c') . "\n");
        \App\Core\Audit::log('system.install', null, null, ['business_type' => $d['business_type'], 'demo' => !empty($d['demo'])], null);
    }
}
