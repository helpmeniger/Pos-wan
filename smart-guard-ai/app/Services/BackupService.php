<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Sauvegarde de la base en SQL pur PHP (aucune commande système exécutée depuis le web).
 * Le fichier est compressé (gzip) et stocké dans storage/backups (non public).
 * La restauration se fait uniquement en ligne de commande (scripts/restore.php).
 */
final class BackupService
{
    public static function backup(string $trigger = 'manuel', ?int $userId = null): array
    {
        $started = date('Y-m-d H:i:s');
        $file = STORAGE_PATH . '/backups/sgai-db-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.sql.gz';
        try {
            $gz = gzopen($file, 'wb6');
            if (!$gz) {
                throw new \RuntimeException('Impossible de créer le fichier de sauvegarde.');
            }
            gzwrite($gz, "-- SMART GUARD AI — sauvegarde du " . date('d/m/Y H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            $pdo = Database::pdo();
            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM);
            foreach ($tables as [$table]) {
                $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`')->fetch(\PDO::FETCH_NUM)[1];
                gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n{$create};\n\n");
                $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '', $table) . '`', \PDO::FETCH_NUM);
                $batch = [];
                while ($row = $stmt->fetch()) {
                    $batch[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                    if (count($batch) >= 200) {
                        gzwrite($gz, "INSERT INTO `{$table}` VALUES " . implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }
                if ($batch) {
                    gzwrite($gz, "INSERT INTO `{$table}` VALUES " . implode(",\n", $batch) . ";\n");
                }
                gzwrite($gz, "\n");
            }
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
            gzclose($gz);
            $size = filesize($file);
            $sha = hash_file('sha256', $file);
            Database::insert('backup_logs', ['backup_type' => 'base_de_donnees', 'file_path' => 'backups/' . basename($file), 'size_bytes' => $size, 'sha256' => $sha,
                'status' => 'succes', 'triggered_by' => $trigger, 'user_id' => $userId, 'started_at' => $started, 'finished_at' => date('Y-m-d H:i:s')]);
            self::rotate(30);
            return ['ok' => true, 'file' => basename($file), 'size' => $size, 'sha256' => $sha];
        } catch (\Throwable $e) {
            @unlink($file);
            Database::insert('backup_logs', ['backup_type' => 'base_de_donnees', 'status' => 'echec', 'message' => mb_substr($e->getMessage(), 0, 500),
                'triggered_by' => $trigger, 'user_id' => $userId, 'started_at' => $started, 'finished_at' => date('Y-m-d H:i:s')]);
            NotificationService::notify(null, 'sauvegarde', 'Échec de la sauvegarde', 'danger', $e->getMessage(), 'maintenance');
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Conserve les N sauvegardes les plus récentes. */
    public static function rotate(int $keep): void
    {
        $files = glob(STORAGE_PATH . '/backups/sgai-db-*.sql.gz') ?: [];
        rsort($files);
        foreach (array_slice($files, $keep) as $f) {
            @unlink($f);
        }
    }

    /** Restauration (CLI uniquement). */
    public static function restore(string $file): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('La restauration n\'est autorisée qu\'en ligne de commande.');
        }
        $gz = gzopen($file, 'rb');
        if (!$gz) {
            throw new \RuntimeException('Fichier illisible : ' . $file);
        }
        $pdo = Database::pdo();
        $buffer = '';
        $inString = false;
        while (!gzeof($gz)) {
            $line = gzgets($gz, 1 << 20);
            if ($line === false) {
                break;
            }
            if (!$inString && ($buffer === '' && (str_starts_with($line, '--') || trim($line) === ''))) {
                continue;
            }
            $buffer .= $line;
            // compter les apostrophes non échappées pour savoir si l'on est dans une chaîne
            $clean = preg_replace("/\\\\./", '', $line);
            if (substr_count($clean, "'") % 2 === 1) {
                $inString = !$inString;
            }
            if (!$inString && str_ends_with(rtrim($line), ';')) {
                $pdo->exec($buffer);
                $buffer = '';
            }
        }
        gzclose($gz);
        Database::insert('backup_logs', ['backup_type' => 'restauration', 'file_path' => basename($file), 'status' => 'succes', 'triggered_by' => 'cli',
            'started_at' => date('Y-m-d H:i:s'), 'finished_at' => date('Y-m-d H:i:s')]);
    }
}
