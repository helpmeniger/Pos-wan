<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Accès base de données via PDO et requêtes préparées exclusivement.
 * Les noms de tables/colonnes passés à insert()/update() proviennent du code,
 * jamais de l'utilisateur.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $depth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = Config::get('database');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int) $c['port'], $c['name']);
            self::$pdo = self::connect($dsn, $c['user'], $c['password']);
        }
        return self::$pdo;
    }

    public static function connect(string $dsn, string $user, string $password): PDO
    {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        // Aligne l'heure MySQL sur le fuseau de l'application (Niamey = UTC+1, sans heure d'été).
        $offset = (new \DateTimeImmutable('now'))->format('P');
        $pdo->exec("SET time_zone = '{$offset}', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }

    public static function setPdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$depth = 0;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function select(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(fn ($c) => "`$c`", $cols)),
            implode(', ', array_map(fn ($c) => ':' . $c, $cols))
        );
        self::query($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $col => $value) {
            $sets[] = "`$col` = :set_$col";
            $params['set_' . $col] = $value;
        }
        return self::execute(sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where), $params + $whereParams);
    }

    /**
     * Exécute $fn dans une transaction. Les transactions imbriquées sont fusionnées.
     * Rejoue automatiquement en cas de deadlock (1213) ou d'attente de verrou (1205).
     */
    public static function transaction(callable $fn, int $retries = 3): mixed
    {
        if (self::$depth > 0) {
            self::$depth++;
            try {
                return $fn();
            } finally {
                self::$depth--;
            }
        }
        $attempt = 0;
        while (true) {
            $attempt++;
            $pdo = self::pdo();
            $pdo->beginTransaction();
            self::$depth = 1;
            try {
                $result = $fn();
                $pdo->commit();
                self::$depth = 0;
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                self::$depth = 0;
                $code = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
                if (in_array($code, [1213, 1205], true) && $attempt < $retries) {
                    usleep(random_int(20000, 80000));
                    continue;
                }
                throw $e;
            }
        }
    }

    public static function inTransaction(): bool
    {
        return self::$depth > 0;
    }

    /** Construit "IN (:p0,:p1…)" avec ses paramètres. */
    public static function in(array $values, string $prefix = 'in'): array
    {
        $params = [];
        foreach (array_values($values) as $i => $v) {
            $params[$prefix . $i] = $v;
        }
        $placeholders = $params ? implode(',', array_map(fn ($k) => ':' . $k, array_keys($params))) : 'NULL';
        return [$placeholders, $params];
    }
}
