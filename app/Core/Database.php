<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Accès MySQL/MariaDB via PDO : requêtes préparées natives uniquement,
 * transactions imbriquées par points de sauvegarde, fuseau Africa/Niamey (+01:00).
 */
class Database
{
    private ?PDO $pdo = null;
    private int $transactionLevel = 0;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $c = $this->config;
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $c['host'],
                (int) $c['port'],
                $c['database']
            );
            if (!empty($c['socket'])) {
                $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $c['socket'], $c['database']);
            }
            $this->pdo = new PDO($dsn, (string) $c['username'], (string) $c['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $this->pdo->exec("SET time_zone = '+01:00'");
            $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        }
        return $this->pdo;
    }

    /** @param array<int|string, mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @return array<string, mixed>|null */
    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * INSERT simple. Les noms de colonnes viennent du code, jamais de l'utilisateur.
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $this->assertIdentifier($table);
        $columns = array_keys($data);
        array_walk($columns, fn (string $c) => $this->assertIdentifier($c));
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', array_map(fn ($c) => ':' . $c, $columns))
        );
        $this->query($sql, $data);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where égalités combinées par AND
     */
    public function update(string $table, array $data, array $where): int
    {
        $this->assertIdentifier($table);
        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $this->assertIdentifier($column);
            $set[] = "`{$column}` = :set_{$column}";
            $params["set_{$column}"] = $value;
        }
        $conditions = [];
        foreach ($where as $column => $value) {
            $this->assertIdentifier($column);
            $conditions[] = "`{$column}` = :where_{$column}";
            $params["where_{$column}"] = $value;
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $set), implode(' AND ', $conditions));
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Exécute $callback dans une transaction (point de sauvegarde si imbriquée).
     * Toute exception annule l'ensemble et est relancée.
     *
     * @template T
     * @param callable(Database): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $level = $this->transactionLevel;
        if ($level === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec("SAVEPOINT sp_{$level}");
        }
        $this->transactionLevel++;
        try {
            $result = $callback($this);
            $this->transactionLevel--;
            if ($level === 0) {
                $pdo->commit();
            } else {
                $pdo->exec("RELEASE SAVEPOINT sp_{$level}");
            }
            return $result;
        } catch (Throwable $e) {
            $this->transactionLevel--;
            if ($level === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec("ROLLBACK TO SAVEPOINT sp_{$level}");
            }
            throw $e;
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    private function assertIdentifier(string $name): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
            throw new \InvalidArgumentException("Identifiant SQL invalide : {$name}");
        }
    }
}
