<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use PDO;
use RuntimeException;

/**
 * Sauvegarde de la base de données : mysqldump si configuré, sinon export PHP natif.
 * Les fichiers sont compressés (gzip) dans storage/backups.
 */
final class BackupService
{
    public static function dir(): string
    {
        return STORAGE_PATH . '/backups';
    }

    public static function create(string $trigger = 'manual', ?int $userId = null): array
    {
        $db = (string) config('database.database');
        $file = sprintf('%s/marwa_%s_%s.sql.gz', self::dir(), $db, date('Ymd_His'));
        $dump = (string) config('database.mysqldump');
        $method = 'php';

        if ($dump !== '' && is_file($dump)) {
            $method = 'mysqldump';
            self::viaMysqldump($dump, $file);
        } else {
            self::viaPhp($file);
        }
        $size = (int) filesize($file);
        $checksum = hash_file('sha256', $file);
        Database::insert('backups', [
            'filename' => basename($file),
            'size_bytes' => $size,
            'method' => $method,
            'trigger_type' => $trigger,
            'checksum' => $checksum,
            'created_by' => $userId,
        ]);
        AuditService::log('backup.create', 'backup', null, 'Sauvegarde ' . basename($file), null, ['size' => $size], $userId);
        self::prune((int) setting('backup.keep_last', 14));
        return ['file' => $file, 'size' => $size, 'method' => $method];
    }

    private static function viaMysqldump(string $bin, string $target): void
    {
        $cnf = tempnam(sys_get_temp_dir(), 'mcd');
        // Identifiants passés par fichier temporaire (non visibles dans la liste des processus).
        file_put_contents($cnf, sprintf("[client]\nuser=%s\npassword=\"%s\"\nhost=%s\nport=%d\n",
            config('database.username'), addcslashes((string) config('database.password'), '"\\'), config('database.host'), config('database.port')));
        $cmd = sprintf('%s --defaults-extra-file=%s --single-transaction --routines --default-character-set=utf8mb4 %s',
            escapeshellarg($bin), escapeshellarg($cnf), escapeshellarg((string) config('database.database')));
        $out = popen($cmd, 'r');
        $gz = gzopen($target, 'wb6');
        if (!$out || !$gz) {
            @unlink($cnf);
            throw new RuntimeException('Impossible de lancer mysqldump.');
        }
        while (!feof($out)) {
            gzwrite($gz, (string) fread($out, 1 << 16));
        }
        $code = pclose($out);
        gzclose($gz);
        @unlink($cnf);
        if ($code !== 0) {
            @unlink($target);
            Logger::warning('mysqldump a échoué, bascule sur l\'export PHP', ['code' => $code]);
            self::viaPhp($target);
        }
    }

    private static function viaPhp(string $target): void
    {
        $pdo = Database::connection();
        $gz = gzopen($target, 'wb6');
        if (!$gz) {
            throw new RuntimeException('Impossible de créer le fichier de sauvegarde.');
        }
        gzwrite($gz, "-- MARWA CONNECT backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n{$create};\n\n");
            $stmt = $pdo->query('SELECT * FROM `' . $table . '`', PDO::FETCH_NUM);
            $batch = [];
            while ($row = $stmt->fetch()) {
                $batch[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                if (count($batch) >= 200) {
                    gzwrite($gz, "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                gzwrite($gz, "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            gzwrite($gz, "\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
    }

    public static function prune(int $keep): void
    {
        if ($keep < 1) {
            return;
        }
        $files = glob(self::dir() . '/marwa_*.sql.gz') ?: [];
        rsort($files, SORT_STRING);
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
            Database::query('DELETE FROM backups WHERE filename = :f', ['f' => basename($old)]);
        }
    }
}
