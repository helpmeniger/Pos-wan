<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Accès base de données. Toutes les requêtes utilisent des requêtes préparées
 * (protection contre les injections SQL).
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Env::get('DB_HOST', '127.0.0.1'), Env::int('DB_PORT', 3306), Env::get('DB_NAME', 'smart_guard_ai'));
            self::$pdo = new PDO($dsn, (string) Env::get('DB_USER', 'root'), (string) Env::get('DB_PASS', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $offset = (new \DateTime())->format('P');
            self::$pdo->exec("SET time_zone = '{$offset}'");
        }
        return self::$pdo;
    }

    public static function setPdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $name = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_BOOL,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $v, $type);
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

    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /** INSERT à partir d'un tableau associatif (les noms de colonnes sont validés). */
    public static function insert(string $table, array $data): int
    {
        self::assertIdentifier($table);
        $cols = array_keys($data);
        array_walk($cols, [self::class, 'assertIdentifier']);
        $sql = sprintf('INSERT INTO `%s` (`%s`) VALUES (%s)', $table, implode('`,`', $cols),
            implode(',', array_map(static fn ($c) => ':' . $c, $cols)));
        self::query($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        self::assertIdentifier($table);
        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            self::assertIdentifier($col);
            $sets[] = "`{$col}` = :set_{$col}";
            $params['set_' . $col] = $val;
        }
        return self::execute(sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where), $params + $whereParams);
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function assertIdentifier(string $name): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('Identifiant SQL invalide : ' . $name);
        }
    }
}
