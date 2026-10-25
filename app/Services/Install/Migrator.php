<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Core\Database;
use App\Support\SqlScriptRunner;
use PDO;

/**
 * Création du schéma, données de référence et migrations SQL versionnées.
 * Utilisé par « php bin/console migrate » et par l'assistant d'installation web.
 */
final class Migrator
{
    public function __construct(private readonly string $root)
    {
    }

    /** Connexion au serveur MySQL sans base sélectionnée (création / suppression de base). */
    public static function serverPdo(array $c): PDO
    {
        $dsn = !empty($c['socket'])
            ? sprintf('mysql:unix_socket=%s;charset=utf8mb4', $c['socket'])
            : sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], (int) $c['port']);
        return new PDO($dsn, (string) $c['username'], (string) $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    public static function assertDatabaseName(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new \InvalidArgumentException('Nom de base invalide (lettres, chiffres et « _ » uniquement).');
        }
        return $name;
    }

    public function createDatabase(array $dbConfig, bool $dropFirst = false): void
    {
        $name = self::assertDatabaseName((string) $dbConfig['database']);
        $server = self::serverPdo($dbConfig);
        if ($dropFirst) {
            $server->exec("DROP DATABASE IF EXISTS `{$name}`");
        }
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    /**
     * @param callable(string): void|null $log
     * @return int nombre de migrations appliquées
     */
    public function migrate(Database $db, ?callable $log = null): int
    {
        $log ??= static function (string $m): void {
        };
        $runner = new SqlScriptRunner($db->pdo());
        $hasSchema = (bool) $db->fetchValue(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'"
        );
        if (!$hasSchema) {
            $tables = (int) $db->fetchValue("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
            if ($tables > 0) {
                throw new \RuntimeException("La base contient déjà {$tables} table(s) qui ne proviennent pas de JOB NIGER : choisissez une base vide ou un autre nom.");
            }
            $log('Création du schéma initial…');
            try {
                $n = $runner->runFile($this->root . '/database/schema.sql');
                $log("  {$n} instructions exécutées.");
                foreach (glob($this->root . '/database/seeders/0*.sql') ?: [] as $seed) {
                    $log('Données de référence : ' . basename($seed));
                    $runner->runFile($seed);
                }
            } catch (\Throwable $e) {
                // La base était vide : on retire tout ce qui vient d'être créé pour permettre une nouvelle tentative
                $this->dropEverything($db);
                throw $e;
            }
        }
        $applied = array_column($db->fetchAll('SELECT version FROM schema_migrations'), 'version');
        $count = 0;
        foreach (glob($this->root . '/database/migrations/*.sql') ?: [] as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $applied, true)) {
                continue;
            }
            $log("Migration {$version}…");
            $runner->runFile($file);
            $db->insert('schema_migrations', ['version' => $version]);
            $count++;
        }
        return $count;
    }

    private function dropEverything(Database $db): void
    {
        $pdo = $db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $objects = $db->fetchAll(
            'SELECT table_name AS name, table_type AS type FROM information_schema.tables WHERE table_schema = DATABASE()'
        );
        foreach ($objects as $o) {
            $name = str_replace('`', '', (string) $o['name']);
            $pdo->exec(($o['type'] === 'VIEW' ? 'DROP VIEW IF EXISTS' : 'DROP TABLE IF EXISTS') . " `{$name}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
