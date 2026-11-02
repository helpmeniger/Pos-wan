<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Migrations versionnées. Les migrations déjà exécutées ne sont jamais rejouées :
 * les mises à jour n'effacent donc jamais les données existantes.
 */
final class Migrator
{
    private string $dir;
    private string $seedDir;
    /** @var callable|null */
    private $output;

    public function __construct(private PDO $pdo, ?callable $output = null)
    {
        $this->dir = BASE_PATH . '/database/migrations';
        $this->seedDir = BASE_PATH . '/database/seeds';
        $this->output = $output;
    }

    private function say(string $msg): void
    {
        if ($this->output) {
            ($this->output)($msg);
        }
    }

    private function ensureTable(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS `migrations` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `migration` VARCHAR(190) NOT NULL UNIQUE,
            `batch` INT UNSIGNED NOT NULL,
            `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function pending(): array
    {
        $this->ensureTable();
        $done = $this->pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob($this->dir . '/*.php') ?: [];
        sort($files, SORT_STRING);
        return array_values(array_filter($files, static fn ($f) => !in_array(basename($f, '.php'), $done, true)));
    }

    /** @return string[] migrations exécutées */
    public function migrate(): array
    {
        $pending = $this->pending();
        if (!$pending) {
            $this->say('Aucune migration en attente.');
            return [];
        }
        $batch = (int) $this->pdo->query('SELECT COALESCE(MAX(batch),0)+1 FROM migrations')->fetchColumn();
        $ran = [];
        foreach ($pending as $file) {
            $name = basename($file, '.php');
            $migration = require $file;
            $statements = is_array($migration) ? ($migration['up'] ?? []) : [];
            // Le DDL MySQL valide implicitement les transactions : chaque instruction est
            // idempotente (IF NOT EXISTS) pour permettre une reprise sans perte.
            foreach ($statements as $sql) {
                if (is_callable($sql)) {
                    $sql($this->pdo);
                } else {
                    $this->pdo->exec($sql);
                }
            }
            $stmt = $this->pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)');
            $stmt->execute([$name, $batch]);
            $this->say("Migrée : {$name}");
            $ran[] = $name;
        }
        return $ran;
    }

    /** Exécute les seeders (idempotents). */
    public function seed(): void
    {
        $files = glob($this->seedDir . '/*.php') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $seeder = require $file;
            if (is_callable($seeder)) {
                $seeder($this->pdo);
                $this->say('Données de référence : ' . basename($file, '.php'));
            }
        }
    }
}
