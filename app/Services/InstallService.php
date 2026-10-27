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
            if (trim($stmt) === '') {
                continue;
            }
            try {
                DB::connection()->exec($stmt);
            } catch (\PDOException $e) {
                // Reprise après une installation ou une mise à jour interrompue : table, colonne ou
                // index déjà créés par l'essai précédent (MySQL n'a pas « ADD COLUMN IF NOT EXISTS »).
                if (!in_array((int) ($e->errorInfo[1] ?? 0), [1050, 1060, 1061], true)) {
                    throw $e;
                }
                \App\Core\Logger::info('Déjà en place, ignoré : ' . mb_substr(trim($stmt), 0, 120));
            }
        }
    }

    /**
     * Vérifie la base AVANT toute écriture : connexion, droits de création, et refus d'une base
     * qui contient les tables d'une autre application (rien n'y est modifié).
     */
    public static function checkDatabase(): void
    {
        $pdo = DB::connection();
        $name = (string) DB::value('SELECT DATABASE()');
        $tables = array_values(array_diff(array_column(DB::all('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'), 't'), ['pm_install_test']));
        if ($tables && !in_array('schema_migrations', $tables, true)) {
            throw new BusinessException(sprintf('La base « %s » contient déjà %d table(s) d\'une autre application (%s…). POS Marwa doit être installé dans une base vide : créez une nouvelle base dans cPanel. Aucune table n\'a été modifiée.', $name, count($tables), implode(', ', array_slice($tables, 0, 3))));
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS pm_install_test (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        try {
            $pdo->exec('ALTER TABLE pm_install_test ADD COLUMN v INT NULL, ADD INDEX idx_v (v)');
        } catch (\PDOException $e) {
            if (!in_array((int) ($e->errorInfo[1] ?? 0), [1060, 1061], true)) {
                throw $e;
            }
        }
        $pdo->exec('DROP TABLE pm_install_test');
    }

    /** Message clair, en français, pour une erreur MySQL rencontrée pendant l'installation. */
    public static function explainDbError(\PDOException $e, string $db): string
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        if (!$code && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) {
            $code = (int) $m[1];
        }
        $detail = trim((string) preg_replace('/^SQLSTATE\[\w+\]:?\s*(\[\d+\]\s*)?([^:\[]*:\s*\d{4}\s+)?/', '', $e->getMessage()));
        $msg = match (true) {
            $code === 1045 => 'Utilisateur ou mot de passe de la base refusé. Dans cPanel → « Bases de données MySQL », vérifiez le nom COMPLET de l\'utilisateur (ex. moncompte_posmarwa) ; en cas de doute, changez son mot de passe et saisissez-le de nouveau ici.',
            in_array($code, [1044, 1142, 1227], true) => 'L\'utilisateur n\'a pas les droits nécessaires sur la base « ' . $db . ' ». Dans cPanel → « Bases de données MySQL » → « Ajouter un utilisateur à une base de données », choisissez l\'utilisateur et la base, puis cochez « TOUS LES PRIVILÈGES ».',
            $code === 1049 => 'La base « ' . $db . ' » n\'existe pas. Créez-la dans cPanel ; sur o2switch son nom complet commence par votre identifiant cPanel (ex. moncompte_posmarwa) : saisissez ce nom complet.',
            in_array($code, [2002, 2003, 2005, 2006], true) => 'Serveur MySQL injoignable avec l\'hôte indiqué. Sur o2switch (et WampServer), l\'hôte est « localhost ».',
            default => 'Erreur MySQL' . ($code ? ' n° ' . $code : '') . ' pendant l\'installation. Les tables déjà créées sont conservées : corrigez la cause puis cliquez de nouveau sur « Installer », l\'installation reprendra où elle s\'est arrêtée.',
        };
        return $msg . ($detail !== '' ? ' — Détail MySQL : ' . mb_substr($detail, 0, 300) : '');
    }

    /** Nombre d'utilisateurs d'une installation POS Marwa déjà présente dans la base (0 sinon). */
    public static function existingUsers(): int
    {
        try {
            return (int) DB::value('SELECT COUNT(*) FROM users');
        } catch (\PDOException) {
            return 0;
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
    public static function install(array $d): string
    {
        if (self::isInstalled()) {
            throw new BusinessException('POS Marwa est déjà installé.');
        }
        self::checkDatabase();
        // Base contenant déjà POS Marwa (installation précédente terminée, ou fichiers réinstallés) :
        // rien n'est effacé ; sauvegarde, mise à jour de la structure, puis fermeture de l'assistant.
        if (self::existingUsers() > 0) {
            try {
                BackupService::create('avant_reprise_installation');
            } catch (\Throwable $e) {
                throw new BusinessException('Cette base contient déjà POS Marwa, mais la sauvegarde de sécurité a échoué (' . $e->getMessage() . ') : rien n\'a été modifié. Vérifiez que storage/ est inscriptible.');
            }
            self::migrate();
            file_put_contents(self::lockFile(), date('c') . "\n");
            \App\Core\Audit::log('system.install_resume', null, null, ['users' => self::existingUsers()], null);
            return 'existing';
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
        return 'new';
    }
}
