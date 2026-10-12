<?php
declare(strict_types=1);

namespace Verifia;

use PDO;

/** Connexion PDO unique ; toutes les requêtes passent par des requêtes préparées. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                (string) Config::get('db.dsn'),
                (string) Config::get('db.user'),
                (string) Config::get('db.password'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]
            );
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    /**
     * Requête préparée. Hors transaction, une instruction victime d'un interblocage est rejouée
     * (InnoDB n'a annulé que cette instruction). Dans une transaction, c'est transaction() qui rejoue l'ensemble.
     */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $pdo = self::pdo();
        for ($attempt = 1; ; $attempt++) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            } catch (\PDOException $e) {
                if ($attempt > 3 || $pdo->inTransaction() || !self::isDeadlock($e)) {
                    throw $e;
                }
                usleep(random_int(5_000, 40_000) * $attempt);
            }
        }
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        foreach ($cols as $c) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $c)) {
                throw new \InvalidArgumentException("Colonne invalide : $c");
            }
        }
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(',', $cols),
            implode(',', array_map(fn($c) => ':' . $c, $cols))
        );
        self::run($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Exécute $fn dans une transaction. Un interblocage InnoDB (1213) ou un dépassement d'attente de verrou (1205)
     * entre processus concurrents (workers, sessions) fait rejouer la transaction, jusqu'à $retries fois.
     */
    public static function transaction(callable $fn, int $retries = 3): mixed
    {
        $pdo = self::pdo();
        for ($attempt = 1; ; $attempt++) {
            $pdo->beginTransaction();
            try {
                $r = $fn($pdo);
                $pdo->commit();
                return $r;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($attempt <= $retries && self::isDeadlock($e)) {
                    usleep(random_int(10_000, 60_000) * $attempt);
                    continue;
                }
                throw $e;
            }
        }
    }

    public static function isDeadlock(\Throwable $e): bool
    {
        return $e instanceof \PDOException && in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true);
    }

    /** Heure courante UTC au format DATETIME (toutes les dates sont stockées en UTC). */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
