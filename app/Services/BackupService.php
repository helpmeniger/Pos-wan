<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Sauvegarde / restauration en PHP pur (fonctionne sans mysqldump).
 * - Archives dans storage/backups (hors du dossier public, accès web interdit).
 * - Base : fichier .sql.gz (une instruction par ligne) + empreinte SHA-256.
 * - Fichiers : archive .zip du dossier public/uploads (le .env n'est volontairement
 *   pas inclus : il contient les accès à la base, à conserver à part).
 * - Rotation : conservation des N dernières sauvegardes (paramètre backup_keep).
 */
final class BackupService
{
    public static function dir(): string
    {
        $d = STORAGE_PATH . '/backups';
        if (!is_dir($d)) {
            mkdir($d, 0750, true);
        }
        return $d;
    }

    public static function create(string $label = 'manuel'): array
    {
        $label = preg_replace('/[^a-z0-9_-]/i', '', $label) ?: 'manuel';
        $base = 'poswan_' . date('Ymd_His') . '_' . $label;
        $sqlFile = self::dir() . '/' . $base . '.sql.gz';
        $gz = gzopen($sqlFile, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('Impossible de créer le fichier de sauvegarde.');
        }
        $pdo = DB::connection();
        gzwrite($gz, '-- POS Marwa sauvegarde ' . date('c') . ' version ' . config('app.version') . "\n");
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n");
        $tables = DB::all("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
        $rowsTotal = 0;
        foreach ($tables as $t) {
            $table = $t['t'];
            $create = DB::one("SHOW CREATE TABLE `$table`");
            $ddl = str_replace(["\r", "\n"], ' ', $create['Create Table']);
            gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n$ddl;\n");
            $cols = array_column(DB::all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA NOT LIKE '%GENERATED%' ORDER BY ORDINAL_POSITION", [$table]), 'COLUMN_NAME');
            $colList = '`' . implode('`,`', $cols) . '`';
            $st = $pdo->query("SELECT $colList FROM `$table`");
            $batch = [];
            while ($row = $st->fetch(\PDO::FETCH_NUM)) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row);
                $batch[] = '(' . implode(',', $vals) . ')';
                $rowsTotal++;
                if (count($batch) >= 200) {
                    gzwrite($gz, "INSERT INTO `$table` ($colList) VALUES " . implode(',', $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                gzwrite($gz, "INSERT INTO `$table` ($colList) VALUES " . implode(',', $batch) . ";\n");
            }
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n-- FIN\n");
        gzclose($gz);
        file_put_contents($sqlFile . '.sha256', hash_file('sha256', $sqlFile));

        $zipFile = null;
        if (class_exists(\ZipArchive::class)) {
            $zipFile = self::dir() . '/' . $base . '_fichiers.zip';
            $zip = new \ZipArchive();
            if ($zip->open($zipFile, \ZipArchive::CREATE) === true) {
                $root = PUBLIC_PATH . '/uploads';
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile()) {
                        $zip->addFile($f->getPathname(), 'uploads/' . ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/'));
                    }
                }
                $zip->close();
                file_put_contents($zipFile . '.sha256', hash_file('sha256', $zipFile));
            }
        }
        self::rotate();
        Audit::log('backup.create', null, null, ['file' => basename($sqlFile), 'rows' => $rowsTotal]);
        return ['sql' => basename($sqlFile), 'zip' => $zipFile ? basename($zipFile) : null, 'rows' => $rowsTotal, 'tables' => count($tables)];
    }

    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/poswan_*.sql.gz') ?: [] as $f) {
            $name = basename($f);
            $out[] = [
                'name' => $name,
                'size' => filesize($f),
                'date' => date('Y-m-d H:i:s', filemtime($f)),
                'valid' => self::verify($name),
                'zip' => is_file(str_replace('.sql.gz', '_fichiers.zip', $f)) ? basename(str_replace('.sql.gz', '_fichiers.zip', $f)) : null,
            ];
        }
        usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /** Nom de fichier strictement contrôlé (pas de traversée de répertoire). */
    public static function path(string $name): string
    {
        if (!preg_match('/^poswan_\d{8}_\d{6}_[a-z0-9_-]+(\.sql\.gz|_fichiers\.zip)$/i', $name)) {
            throw new BusinessException('Nom de sauvegarde invalide.');
        }
        $p = self::dir() . '/' . $name;
        if (!is_file($p)) {
            throw new BusinessException('Sauvegarde introuvable.');
        }
        return $p;
    }

    public static function verify(string $name): bool
    {
        try {
            $p = self::path($name);
        } catch (BusinessException) {
            return false;
        }
        $sum = @file_get_contents($p . '.sha256');
        if (!$sum || !hash_equals(trim($sum), hash_file('sha256', $p))) {
            return false;
        }
        if (str_ends_with($name, '.sql.gz')) {
            // Contrôle d'intégrité : l'archive doit se décompresser jusqu'au marqueur de fin
            $gz = gzopen($p, 'rb');
            $last = '';
            while (!gzeof($gz)) {
                $line = gzgets($gz, 10 * 1024 * 1024);
                if ($line !== false && trim($line) !== '') {
                    $last = trim($line);
                }
            }
            gzclose($gz);
            return $last === '-- FIN';
        }
        return true;
    }

    /** Restauration : vérifie l'archive, fait une sauvegarde de sécurité, puis rejoue le script. */
    public static function restore(string $name): array
    {
        if (!str_ends_with($name, '.sql.gz') || !self::verify($name)) {
            throw new BusinessException('Archive corrompue ou invalide : restauration refusée.');
        }
        $safety = self::create('avant_restauration');
        $pdo = DB::connection();
        $gz = gzopen(self::path($name), 'rb');
        $n = 0;
        try {
            while (!gzeof($gz)) {
                $line = gzgets($gz, 64 * 1024 * 1024);
                if ($line === false) {
                    break;
                }
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }
                $pdo->exec($line);
                $n++;
            }
        } finally {
            gzclose($gz);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        Audit::log('backup.restore', null, null, ['file' => $name, 'statements' => $n, 'safety' => $safety['sql']]);
        return ['statements' => $n, 'safety' => $safety['sql']];
    }

    public static function rotate(): void
    {
        $keep = max(3, (int) setting('backup_keep', '15'));
        $files = glob(self::dir() . '/poswan_*.sql.gz') ?: [];
        rsort($files);
        foreach (array_slice($files, $keep) as $f) {
            @unlink($f);
            @unlink($f . '.sha256');
            $zip = str_replace('.sql.gz', '_fichiers.zip', $f);
            @unlink($zip);
            @unlink($zip . '.sha256');
        }
    }
}
