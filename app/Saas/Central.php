<?php
declare(strict_types=1);

namespace App\Saas;

use PDO;
use PDOStatement;

/**
 * Connexion à la base CENTRALE du service (clients, offres, paiements, console).
 * Distincte de DB:: qui, dans l'espace d'un client, pointe sur la base de ce client.
 */
final class Central
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $c = config('saas.central');
            self::$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']), $c['username'], $c['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            self::$pdo->exec("SET time_zone = '" . (new \DateTime())->format('P') . "', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
        }
        return self::$pdo;
    }

    public static function setConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function run(string $sql, array $p = []): PDOStatement
    {
        $st = self::connection()->prepare($sql);
        $st->execute($p);
        return $st;
    }

    public static function all(string $sql, array $p = []): array
    {
        return self::run($sql, $p)->fetchAll();
    }

    public static function one(string $sql, array $p = []): ?array
    {
        $r = self::run($sql, $p)->fetch();
        return $r === false ? null : $r;
    }

    public static function value(string $sql, array $p = []): mixed
    {
        $v = self::run($sql, $p)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        foreach (array_merge([$table], array_keys($data)) as $id) {
            self::ident($id);
        }
        self::run(sprintf('INSERT INTO `%s` (`%s`) VALUES (%s)', $table, implode('`,`', array_keys($data)), implode(',', array_fill(0, count($data), '?'))), array_values($data));
        return (int) self::connection()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        self::ident($table);
        $sets = [];
        foreach (array_keys($data) as $c) {
            self::ident($c);
            $sets[] = "`$c` = ?";
        }
        return self::run(sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where), array_merge(array_values($data), $params))->rowCount();
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::connection();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Applique les fichiers database/saas/*.sql pas encore exécutés (idempotent). */
    public static function migrate(): array
    {
        $pdo = self::connection();
        $pdo->exec('CREATE TABLE IF NOT EXISTS saas_migrations (version VARCHAR(80) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $done = array_column(self::all('SELECT version FROM saas_migrations'), 'version');
        $files = glob(BASE_PATH . '/database/saas/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $f) {
            $v = basename($f, '.sql');
            if (in_array($v, $done, true)) {
                continue;
            }
            $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($f));
            foreach (preg_split('/;\s*$/m', (string) $sql) ?: [] as $stmt) {
                if (trim($stmt) !== '') {
                    $pdo->exec($stmt);
                }
            }
            self::run('INSERT INTO saas_migrations (version) VALUES (?)', [$v]);
            $applied[] = $v;
        }
        return $applied;
    }

    public static function setting(string $key, ?string $default = null): ?string
    {
        $v = self::value('SELECT svalue FROM saas_settings WHERE skey = ?', [$key]);
        return $v === null ? $default : (string) $v;
    }

    public static function settingSet(string $key, ?string $value): void
    {
        self::run('INSERT INTO saas_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$key, $value]);
    }

    public static function audit(string $action, ?int $tenantId = null, array $details = [], ?int $adminId = null): void
    {
        self::insert('saas_audit', [
            'admin_id' => ($adminId ?? ConsoleAuth::id()) ?: null, 'tenant_id' => $tenantId, 'action' => $action,
            'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            'ip' => PHP_SAPI === 'cli' ? 'cli' : \App\Core\Request::ip(),
        ]);
    }

    /** Limitation simple par IP (inscriptions, connexions console, recherche d'espace). */
    public static function throttle(string $kind, int $max, int $minutes): bool
    {
        $ip = PHP_SAPI === 'cli' ? 'cli' : \App\Core\Request::ip();
        self::run('DELETE FROM saas_throttle WHERE created_at < NOW() - INTERVAL 1 DAY');
        $n = (int) self::value('SELECT COUNT(*) FROM saas_throttle WHERE kind = ? AND ip = ? AND created_at >= NOW() - INTERVAL ? MINUTE', [$kind, $ip, $minutes]);
        if ($n >= $max) {
            return false;
        }
        self::run('INSERT INTO saas_throttle (kind, ip) VALUES (?, ?)', [$kind, $ip]);
        return true;
    }

    private static function ident(string $id): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $id)) {
            throw new \InvalidArgumentException('Identifiant SQL invalide.');
        }
    }
}
