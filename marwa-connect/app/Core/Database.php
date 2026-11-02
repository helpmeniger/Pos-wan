<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Accès base de données (PDO MySQL/MariaDB) avec requêtes préparées exclusivement.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $transactionLevel = 0;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect(
                (string) config('database.host'),
                (int) config('database.port'),
                (string) config('database.database'),
                (string) config('database.username'),
                (string) config('database.password')
            );
        }
        return self::$pdo;
    }

    public static function connect(string $host, int $port, ?string $dbName, string $user, string $pass): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
        if ($dbName) {
            $dsn .= ';dbname=' . $dbName;
        }
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $offset = (new \DateTime())->format('P');
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '{$offset}', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }

    public static function setConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        foreach ($params as $key => $value) {
            $param = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($param, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::ident($table),
            implode(', ', array_map([self::class, 'ident'], $cols)),
            implode(', ', array_map(static fn ($c) => ':' . $c, $cols))
        );
        self::query($sql, $data);
        return (int) self::connection()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $col => $value) {
            $sets[] = self::ident($col) . ' = :set_' . $col;
            $params['set_' . $col] = $value;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', self::ident($table), implode(', ', $sets), $where);
        return self::query($sql, array_merge($params, $whereParams))->rowCount();
    }

    /** Exécute une fonction dans une transaction (supporte l'imbrication via savepoints). */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        if (self::$transactionLevel === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT sp' . self::$transactionLevel);
        }
        self::$transactionLevel++;
        try {
            $result = $callback($pdo);
            self::$transactionLevel--;
            if (self::$transactionLevel === 0) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT sp' . self::$transactionLevel);
            }
            return $result;
        } catch (Throwable $e) {
            self::$transactionLevel--;
            if (self::$transactionLevel === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT sp' . self::$transactionLevel);
            }
            throw $e;
        }
    }

    /** Protège un identifiant SQL (table/colonne). */
    public static function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException('Identifiant SQL invalide : ' . $name);
        }
        return '`' . $name . '`';
    }
}
