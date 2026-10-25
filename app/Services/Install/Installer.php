<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Core\Config;
use App\Core\Database;
use App\Services\Auth\PasswordHasher;
use App\Services\Auth\StaffAccountService;
use PDOException;

/**
 * Installation complète en une étape : test de connexion, création de la base, schéma et
 * données de référence, compte administrateur, fichier .env, verrou d'installation.
 */
final class Installer
{
    public function __construct(private readonly string $root, private readonly Config $config)
    {
    }

    /**
     * @param array<string, mixed> $data données validées du formulaire
     * @return array{ok: bool, error?: string, env?: string, env_written?: bool}
     */
    public function install(array $data, string $appUrl, string $basePath): array
    {
        $dbConfig = [
            'host' => $data['db_host'],
            'port' => (int) $data['db_port'],
            'database' => Migrator::assertDatabaseName((string) $data['db_name']),
            'username' => $data['db_user'],
            'password' => (string) ($data['db_password'] ?? ''),
            'socket' => '',
        ];

        // 1. Connexion au serveur MySQL / MariaDB
        try {
            $server = Migrator::serverPdo($dbConfig);
            $version = (string) $server->query('SELECT VERSION()')->fetchColumn();
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => self::explainConnectionError($e, $dbConfig)];
        }
        if (!self::versionSupported($version)) {
            return ['ok' => false, 'error' => "Version du serveur non prise en charge ({$version}) : MySQL 8.0.16+ ou MariaDB 10.6+ requis."];
        }

        // 2. Base, schéma, données de référence, migrations
        $migrator = new Migrator($this->root);
        $db = new Database($dbConfig);
        try {
            $migrator->createDatabase($dbConfig);
            $migrator->migrate($db);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), '1419') || stripos($e->getMessage(), 'binary logging') !== false) {
                return ['ok' => false, 'error' => 'MySQL refuse la création des déclencheurs (journalisation binaire active). '
                    . 'Utilisez le compte « root » pendant l\'installation, ou exécutez une fois en root : '
                    . 'SET GLOBAL log_bin_trust_function_creators = 1;'];
            }
            return ['ok' => false, 'error' => 'Erreur pendant la création des tables : ' . $e->getMessage()];
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        // 3. Compte administrateur (mot de passe choisi dans le formulaire)
        $hasAdmin = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.code = 'SUPER_ADMIN'"
        );
        if ($hasAdmin === 0) {
            try {
                (new StaffAccountService($db, new PasswordHasher(), $this->config))->create(
                    (string) $data['admin_phone'],
                    (string) $data['admin_first_name'],
                    (string) $data['admin_last_name'],
                    $data['admin_email'] ?? null,
                    'SUPER_ADMIN',
                    (string) $data['admin_password'],
                );
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'Création de l\'administrateur impossible : ' . $e->getMessage()];
            }
        }

        // 4. Fichier .env (secret applicatif généré) : sa présence marque l'application comme installée
        $env = $this->buildEnv($dbConfig, $appUrl, $basePath);
        $written = @file_put_contents($this->root . '/.env', $env) !== false;
        if ($written) {
            @chmod($this->root . '/.env', 0600);
        }
        return ['ok' => true, 'env' => $env, 'env_written' => $written];
    }

    private function buildEnv(array $db, string $appUrl, string $basePath): string
    {
        $template = (string) @file_get_contents($this->root . '/.env.example');
        $values = [
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'false',
            'APP_URL' => $appUrl,
            'APP_BASE_PATH' => $basePath,
            'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
            'DB_HOST' => (string) $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'],
            'DB_PASSWORD' => (string) $db['password'],
            'DB_TEST_DATABASE' => $db['database'] . '_test',
        ];
        foreach ($values as $key => $value) {
            $line = $key . '=' . self::quote($value);
            $template = preg_match('/^' . $key . '=.*$/m', $template)
                ? (string) preg_replace_callback('/^' . $key . '=.*$/m', fn () => $line, $template)
                : $template . PHP_EOL . $line;
        }
        return "# Généré par l'assistant d'installation le " . date('d/m/Y H:i') . PHP_EOL . $template;
    }

    private static function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_:\/.\-+=]+$/', $value)) {
            return $value;
        }
        return '"' . addcslashes($value, "\\\"") . '"';
    }

    private static function versionSupported(string $version): bool
    {
        if (stripos($version, 'mariadb') !== false) {
            return version_compare(preg_replace('/^(\d+\.\d+\.\d+).*/', '$1', $version) ?? '0', '10.6.0', '>=');
        }
        return version_compare(preg_replace('/^(\d+\.\d+\.\d+).*/', '$1', $version) ?? '0', '8.0.16', '>=');
    }

    private static function explainConnectionError(PDOException $e, array $c): string
    {
        $msg = $e->getMessage();
        if (str_contains($msg, '[1045]')) {
            return "Accès refusé pour l'utilisateur « {$c['username']} » : vérifiez l'utilisateur et le mot de passe MySQL "
                . '(WampServer : utilisateur « root », mot de passe vide par défaut).';
        }
        if (str_contains($msg, '[2002]') || str_contains($msg, '[2006]') || stripos($msg, 'refused') !== false) {
            return "Aucun serveur MySQL ne répond sur {$c['host']}:{$c['port']}. Vérifiez que l'icône WampServer est verte. "
                . 'Avec WampServer, MySQL écoute souvent sur le port 3306 et MariaDB sur le port 3307.';
        }
        return 'Connexion impossible : ' . $msg;
    }
}
