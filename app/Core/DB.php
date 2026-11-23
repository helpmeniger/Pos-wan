<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Accès base de données via PDO. Toutes les requêtes passent par des
 * requêtes préparées (protection contre l'injection SQL).
 */
final class DB
{
    private static ?PDO $pdo = null;

    /** Erreurs de connexion passagères (serveur qui redémarre, trop de connexions, connexion perdue). */
    private const CONNECT_RETRY = [1040, 1203, 2002, 2003, 2006, 2013];
    /** Interblocage ou attente de verrou entre deux ventes simultanées : la transaction entière est rejouée. */
    private const TX_RETRY = [1205, 1213];

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $c = config('database');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['database'], $c['charset']);
            // Base locale qui redémarre ou très sollicitée : quelques essais rapprochés (≈ 12 s au plus) avant
            // d'abandonner, pour que la caisse attende au lieu d'afficher une erreur.
            $delays = PHP_SAPI === 'cli' ? [0] : [0, 250, 500, 1000, 1500, 2000, 3000, 3500];
            foreach ($delays as $i => $ms) {
                usleep($ms * 1000);
                try {
                    // @ : l'avertissement PHP d'une connexion refusée deviendrait une autre exception ; l'erreur PDO suit
                    self::$pdo = @new PDO($dsn, (string) $c['username'], (string) $c['password'], [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::ATTR_STRINGIFY_FETCHES => false,
                        PDO::ATTR_TIMEOUT => 5,
                    ]);
                    break;
                } catch (\PDOException|\ErrorException $e) {
                    if ($i === count($delays) - 1 || !in_array(self::errorCode($e), self::CONNECT_RETRY, true)) {
                        throw $e;
                    }
                }
            }
            $offset = (new \DateTime())->format('P');
            // Attente de verrou courte (6 s au lieu de 50) : une vente bloquée par une autre est rejouée aussitôt
            // (voir transaction()) au lieu de laisser la caisse sans réponse.
            self::$pdo->exec("SET time_zone = '{$offset}', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO', innodb_lock_wait_timeout = 6");
        }
        return self::$pdo;
    }

    /** Code d'erreur MySQL/MariaDB d'une exception PDO (0 si inconnu). */
    public static function errorCode(\Throwable $e): int
    {
        if ($e instanceof \ErrorException) { // avertissement PHP converti (connexion coupée côté serveur)
            $m = $e->getMessage();
            return match (true) {
                str_contains($m, 'gone away') || str_contains($m, 'Send of') || str_contains($m, 'Broken pipe') => 2006,
                str_contains($m, 'Lost connection') || str_contains($m, 'Error while reading') => 2013,
                str_contains($m, 'refused') || str_contains($m, 'actively refused') => 2002,
                default => 0,
            };
        }
        if ($e instanceof \PDOException) {
            if (isset($e->errorInfo[1]) && is_numeric($e->errorInfo[1])) {
                return (int) $e->errorInfo[1];
            }
            if (preg_match('/\b(1040|1203|1205|1213|2002|2003|2006|2013)\b/', $e->getMessage(), $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    public static function setConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::connection()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $r = self::run($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Insertion ; les noms de colonnes viennent du code, jamais de l'utilisateur. */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        foreach ($cols as $c) {
            self::assertIdentifier($c);
        }
        self::assertIdentifier($table);
        $sql = sprintf('INSERT INTO `%s` (`%s`) VALUES (%s)', $table, implode('`,`', $cols), implode(',', array_fill(0, count($cols), '?')));
        self::run($sql, array_values($data));
        return (int) self::connection()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        self::assertIdentifier($table);
        $sets = [];
        foreach (array_keys($data) as $c) {
            self::assertIdentifier($c);
            $sets[] = "`$c` = ?";
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where);
        return self::run($sql, array_merge(array_values($data), $params))->rowCount();
    }

    /**
     * Exécute $fn dans une transaction ; rollback automatique en cas d'erreur.
     * Interblocage (1213) ou verrou trop long (1205) : tout a été annulé par la base, la transaction est rejouée
     * (3 essais en tout). Connexion perdue avant tout travail : reconnexion puis nouvel essai.
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::connection();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        for ($attempt = 1; ; $attempt++) {
            try {
                @$pdo->beginTransaction(); // @ : voir connection()
            } catch (\PDOException|\ErrorException $e) {
                if ($attempt < 3 && in_array(self::errorCode($e), self::CONNECT_RETRY, true)) {
                    self::$pdo = null; // connexion perdue (serveur redémarré) : nouvelle connexion
                    $pdo = self::connection();
                    continue;
                }
                throw $e;
            }
            try {
                $result = $fn();
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (Throwable) {
                    // connexion déjà perdue : la base a annulé la transaction d'elle-même
                }
                if ($attempt < 3 && in_array(self::errorCode($e), self::TX_RETRY, true)) {
                    Logger::warning('db.retry', ['attempt' => $attempt, 'code' => self::errorCode($e)]);
                    usleep(random_int(80, 300) * 1000 * $attempt);
                    continue;
                }
                throw $e;
            }
        }
    }

    private static function assertIdentifier(string $id): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $id)) {
            throw new \InvalidArgumentException('Identifiant SQL invalide.');
        }
    }
}
