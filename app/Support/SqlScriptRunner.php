<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Exécute un script SQL « mysql CLI » via PDO : gère la directive DELIMITER (triggers),
 * les commentaires -- et /* *\/, les chaînes entre quotes. Les instructions
 * CREATE DATABASE et USE sont ignorées : la base est celle de la connexion (.env).
 */
final class SqlScriptRunner
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function runFile(string $file): int
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException("Fichier SQL illisible : {$file}");
        }
        return $this->run($sql);
    }

    public function run(string $sql): int
    {
        $count = 0;
        foreach (self::split($sql) as $statement) {
            if (preg_match('/^\s*(CREATE\s+DATABASE|USE\s)/i', $statement)) {
                continue;
            }
            // query() + lecture complète : certaines instructions (SELECT, EXECUTE…) renvoient un résultat
            // qui doit être consommé avant l'instruction suivante
            $stmt = $this->pdo->query($statement);
            if ($stmt !== false) {
                do {
                    if ($stmt->columnCount() > 0) {
                        $stmt->fetchAll();
                    }
                } while ($stmt->nextRowset());
                $stmt->closeCursor();
            }
            $count++;
        }
        return $count;
    }

    /** @return list<string> */
    public static function split(string $sql): array
    {
        $statements = [];
        $delimiter = ';';
        $buffer = '';
        $len = strlen($sql);
        $i = 0;
        $quote = null;
        $atLineStart = true;

        while ($i < $len) {
            $ch = $sql[$i];

            if ($quote === null && $atLineStart) {
                // Directive DELIMITER (en début de ligne, hors chaîne)
                if (preg_match('/\GDELIMITER[ \t]+(\S+)[ \t]*(\r?\n|$)/Ai', $sql, $m, 0, $i)) {
                    $delimiter = $m[1];
                    $i += strlen($m[0]);
                    continue;
                }
            }

            if ($quote === null) {
                if ($ch === '-' && ($sql[$i + 1] ?? '') === '-' && in_array($sql[$i + 2] ?? "\n", [' ', "\t", "\n", "\r"], true)) {
                    $end = strpos($sql, "\n", $i);
                    $i = $end === false ? $len : $end + 1;
                    $atLineStart = true;
                    $buffer .= "\n";
                    continue;
                }
                if ($ch === '#') {
                    $end = strpos($sql, "\n", $i);
                    $i = $end === false ? $len : $end + 1;
                    $atLineStart = true;
                    $buffer .= "\n";
                    continue;
                }
                if ($ch === '/' && ($sql[$i + 1] ?? '') === '*') {
                    $end = strpos($sql, '*/', $i + 2);
                    $i = $end === false ? $len : $end + 2;
                    continue;
                }
                if ($ch === "'" || $ch === '"' || $ch === '`') {
                    $quote = $ch;
                    $buffer .= $ch;
                    $i++;
                    $atLineStart = false;
                    continue;
                }
                if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
                    $stmt = trim($buffer);
                    if ($stmt !== '') {
                        $statements[] = $stmt;
                    }
                    $buffer = '';
                    $i += strlen($delimiter);
                    $atLineStart = false;
                    continue;
                }
            } else {
                if ($ch === '\\' && $quote !== '`') {
                    $buffer .= $ch . ($sql[$i + 1] ?? '');
                    $i += 2;
                    continue;
                }
                if ($ch === $quote) {
                    if (($sql[$i + 1] ?? '') === $quote) { // quote doublée ''
                        $buffer .= $ch . $ch;
                        $i += 2;
                        continue;
                    }
                    $quote = null;
                }
            }

            $buffer .= $ch;
            $atLineStart = ($ch === "\n") || ($atLineStart && ($ch === ' ' || $ch === "\t" || $ch === "\r"));
            $i++;
        }
        $stmt = trim($buffer);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }
        return $statements;
    }
}
