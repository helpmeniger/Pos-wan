<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Logger;
use App\Core\Migrator;
use PDO;
use RuntimeException;

/**
 * Installation initiale : vérification des prérequis, création de la base,
 * migrations, données de référence, compte super administrateur et fichier .env.
 */
final class InstallerService
{
    public static function requirements(): array
    {
        $checks = [];
        $add = static function (string $label, bool $ok, string $detail = '', bool $required = true) use (&$checks): void {
            $checks[] = compact('label', 'ok', 'detail', 'required');
        };
        $add('PHP 8.2 ou supérieur', version_compare(PHP_VERSION, '8.2.0', '>='), 'Version actuelle : ' . PHP_VERSION);
        foreach (['pdo_mysql' => 'Pilote PDO MySQL', 'mbstring' => 'mbstring', 'openssl' => 'OpenSSL (API-SSL MikroTik, chiffrement)', 'json' => 'JSON', 'ctype' => 'ctype'] as $ext => $label) {
            $add('Extension ' . $label, extension_loaded($ext));
        }
        $add('Extension sodium (chiffrement)', extension_loaded('sodium'), 'Sinon OpenSSL AES-256-GCM est utilisé', false);
        $add('Extension curl (paiements, notifications)', extension_loaded('curl'), 'Nécessaire à partir de la phase 6', false);
        $add('Extension gd (logos, QR codes)', extension_loaded('gd'), 'Recommandée pour l\'impression', false);
        $add('Fonction stream_socket_client (API RouterOS)', function_exists('stream_socket_client'));
        foreach (['storage', 'storage/logs', 'storage/backups', 'storage/exports', 'storage/cache', 'storage/sessions'] as $dir) {
            $add("Dossier {$dir} accessible en écriture", is_writable(BASE_PATH . '/' . $dir));
        }
        $envWritable = is_file(BASE_PATH . '/.env') ? is_writable(BASE_PATH . '/.env') : is_writable(BASE_PATH);
        $add('Fichier .env accessible en écriture', $envWritable, BASE_PATH);
        return $checks;
    }

    public static function requirementsMet(): bool
    {
        foreach (self::requirements() as $c) {
            if ($c['required'] && !$c['ok']) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array{db_host:string,db_port:int,db_name:string,db_user:string,db_password:string,app_url:string,
     *              company_name:string,admin_name:string,admin_username:string,admin_email:string,admin_password:string} $d
     */
    public static function install(array $d): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $d['db_name'])) {
            throw new RuntimeException('Nom de base de données invalide (lettres, chiffres et _ uniquement).');
        }
        // 1. Connexion au serveur et création de la base si nécessaire.
        $server = Database::connect($d['db_host'], $d['db_port'], null, $d['db_user'], $d['db_password']);
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$d['db_name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = Database::connect($d['db_host'], $d['db_port'], $d['db_name'], $d['db_user'], $d['db_password']);
        Database::setConnection($pdo);

        // 2. Fichier .env (clé de chiffrement conservée si déjà présente).
        $envFile = BASE_PATH . '/.env';
        $appKey = (string) Env::get('APP_KEY', '');
        if ($appKey === '') {
            $appKey = Crypto::generateKey();
        }
        $env = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => false,
            'APP_URL' => rtrim($d['app_url'], '/'),
            'APP_TIMEZONE' => 'Africa/Niamey',
            'APP_KEY' => $appKey,
            'DB_HOST' => $d['db_host'],
            'DB_PORT' => $d['db_port'],
            'DB_DATABASE' => $d['db_name'],
            'DB_USERNAME' => $d['db_user'],
            'DB_PASSWORD' => $d['db_password'],
            'LOG_LEVEL' => 'info',
            'SESSION_SECURE_COOKIE' => str_starts_with($d['app_url'], 'https://'),
            'MYSQLDUMP_PATH' => '',
        ];
        if (!Env::write($envFile, $env)) {
            throw new RuntimeException('Impossible d\'écrire le fichier .env.');
        }
        Env::load($envFile);

        // 3. Schéma et données de référence.
        $migrator = new Migrator($pdo);
        $migrator->migrate();
        $migrator->seed();

        // 4. Super administrateur.
        $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'super_admin'")->fetchColumn();
        $exists = $pdo->prepare('SELECT id FROM users WHERE email = ? OR username = ?');
        $exists->execute([$d['admin_email'], $d['admin_username']]);
        if ($exists->fetchColumn()) {
            throw new RuntimeException('Un utilisateur avec cet e-mail ou cet identifiant existe déjà dans cette base.');
        }
        $userId = Database::insert('users', [
            'role_id' => $roleId,
            'full_name' => $d['admin_name'],
            'username' => $d['admin_username'],
            'email' => mb_strtolower($d['admin_email']),
            'password_hash' => Auth::hash($d['admin_password']),
            'status' => 'active',
            'password_changed_at' => date('Y-m-d H:i:s'),
        ]);
        SettingsService::set('company.name', $d['company_name'], 'string', 'company', $userId);

        // 5. Verrou d'installation.
        file_put_contents(BASE_PATH . '/storage/installed.lock', json_encode([
            'installed_at' => date('c'),
            'version' => config('app.version'),
        ]));
        AuditService::log('system.install', 'user', $userId, 'Installation de MARWA CONNECT', null, null, $userId);
        Logger::info('Installation terminée', ['admin' => $d['admin_username']]);
    }
}
