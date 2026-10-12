<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Core\Database;
use PDO;

/** Exécution des fichiers SQL de migration (gère DELIMITER pour compatibilité phpMyAdmin). */
final class SqlRunner
{
    public static function split(string $sql): array
    {
        $statements = [];
        $delimiter = ';';
        $buffer = '';
        foreach (preg_split('/\R/', $sql) as $line) {
            $trim = trim($line);
            if (preg_match('/^DELIMITER\s+(\S+)$/i', $trim, $m)) {
                $delimiter = $m[1];
                continue;
            }
            if ($buffer === '' && ($trim === '' || str_starts_with($trim, '--'))) {
                continue;
            }
            $buffer .= $line . "\n";
            if (str_ends_with(rtrim($line), $delimiter) && !self::insideQuotes($buffer)) {
                $statements[] = trim(substr(rtrim($buffer), 0, -strlen($delimiter)));
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }
        return array_values(array_filter($statements, fn ($s) => $s !== ''));
    }

    private static function insideQuotes(string $s): bool
    {
        $s = preg_replace("/''/", '', $s);
        return substr_count((string) $s, "'") % 2 === 1;
    }

    public static function migrate(string $dbName, bool $fresh = false): array
    {
        $c = Config::get('database');
        $server = Database::connect(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], (int) $c['port']), $c['user'], $c['password']);
        $log = [];
        if ($fresh) {
            $server->exec("DROP DATABASE IF EXISTS `$dbName`");
            $log[] = "Base `$dbName` supprimée.";
        }
        $server->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = Database::connect(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int) $c['port'], $dbName), $c['user'], $c['password']);
        Database::setPdo($pdo);

        $hasTable = (bool) $pdo->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn();
        $applied = $hasTable ? $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN) : [];

        $files = glob(MARWA_ROOT . '/database/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $applied, true)) {
                continue;
            }
            foreach (self::split((string) file_get_contents($file)) as $stmt) {
                $pdo->exec($stmt);
            }
            $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
            $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
            $log[] = "Migration appliquée : $version";
        }
        if (count($log) === ($fresh ? 1 : 0)) {
            $log[] = 'Aucune migration en attente.';
        }
        return $log;
    }
}
