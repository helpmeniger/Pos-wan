<?php
/**
 * Exécution d'un script SQL (schéma, données de démonstration, restauration)
 * instruction par instruction, en respectant chaînes et commentaires.
 */
declare(strict_types=1);

final class SqlRunner
{
    /** Découpe un script SQL en instructions. */
    public static function split(string $sql): array
    {
        $statements = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';
            if ($quote !== null) {
                $buf .= $c;
                if ($c === '\\' && $quote !== '`') {
                    $buf .= $next;
                    $i++;
                } elseif ($c === $quote) {
                    if ($next === $quote) { // guillemet doublé
                        $buf .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($c === '-' && $next === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $buf .= "\n";
                continue;
            }
            if ($c === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $buf .= "\n";
                continue;
            }
            if ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;
                continue;
            }
            if ($c === ';') {
                if (trim($buf) !== '') {
                    $statements[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $statements[] = trim($buf);
        }
        return $statements;
    }

    /** Exécute toutes les instructions d'un fichier. Retourne le nombre exécuté. */
    public static function runFile(PDO $pdo, string $file): int
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Fichier SQL illisible : $file");
        }
        return self::run($pdo, $sql);
    }

    public static function run(PDO $pdo, string $sql): int
    {
        $n = 0;
        foreach (self::split($sql) as $stmt) {
            $pdo->exec($stmt);
            $n++;
        }
        return $n;
    }
}
