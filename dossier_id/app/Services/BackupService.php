<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Database;

/**
 * Sauvegardes complètes chiffrées (base de données + photographies + documents).
 *
 * Format : archive ZIP chiffrée en flux (XChaCha20-Poly1305, clé « backup »).
 * Les fichiers stockés y figurent sous leur forme déjà chiffrée (clé « storage ») :
 * la restauration exige donc les DEUX clés, qui doivent être conservées
 * séparément des sauvegardes (voir docs/SAUVEGARDE_RESTAURATION.md).
 */
final class BackupService
{
    public const EXT = '.didbak';

    /** Ordre compatible avec les clés étrangères. */
    public const TABLES = [
        'schema_migrations', 'roles', 'permissions', 'role_permissions', 'users', 'settings', 'login_attempts',
        'persons', 'photos', 'visits', 'procedures', 'procedure_status_history', 'documents', 'duplicate_reviews', 'audit_log',
    ];

    public static function dir(): string
    {
        $d = FileStorage::root() . '/backups';
        if (!is_dir($d)) {
            mkdir($d, 0750, true);
        }
        return $d;
    }

    private static function tmp(string $suffix): string
    {
        $d = FileStorage::root() . '/tmp';
        if (!is_dir($d)) {
            mkdir($d, 0750, true);
        }
        return $d . '/' . bin2hex(random_bytes(8)) . $suffix;
    }

    public static function schemaVersion(): string
    {
        return (string) Database::value('SELECT MAX(version) FROM schema_migrations');
    }

    public static function keyFingerprint(): string
    {
        return substr(hash('sha256', Crypto::key('storage')), 0, 16);
    }

    /** @return string nom du fichier de sauvegarde créé */
    public static function create(?string $label = null): string
    {
        $zipPath = self::tmp('.zip');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Impossible de créer l'archive temporaire.");
        }
        $tmpFiles = [];
        try {
            $manifest = [
                'app' => 'DOSSIER ID',
                'format' => 1,
                'schema_version' => self::schemaVersion(),
                'created_at' => date('c'),
                'storage_key_fingerprint' => self::keyFingerprint(),
                'tables' => [],
                'files' => [],
            ];
            foreach (self::TABLES as $table) {
                $tmp = self::tmp('.jsonl');
                $tmpFiles[] = $tmp;
                $fh = fopen($tmp, 'wb');
                $count = 0;
                $stmt = Database::pdo()->query("SELECT * FROM `$table`");
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
                    $count++;
                }
                fclose($fh);
                $zip->addFile($tmp, "db/$table.jsonl");
                $manifest['tables'][$table] = ['rows' => $count, 'sha256' => hash_file('sha256', $tmp)];
            }
            foreach (FileStorage::CATEGORIES as $cat) {
                $base = FileStorage::root() . "/$cat";
                if (!is_dir($base)) {
                    continue;
                }
                foreach (glob($base . '/*/*.bin') ?: [] as $file) {
                    $rel = basename(dirname($file)) . '/' . basename($file);
                    if (!preg_match('#^[a-f0-9]{4}/[a-f0-9]{32}\.bin$#', $rel)) {
                        continue;
                    }
                    $zip->addFile($file, "files/$cat/$rel");
                    $manifest['files']["$cat/$rel"] = hash_file('sha256', $file);
                }
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $zip->close();

            $suffix = $label ? '_' . preg_replace('/[^a-z0-9]+/', '-', strtolower($label)) : '';
            $name = 'dossier_id_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2)) . $suffix . self::EXT;
            Crypto::encryptFile($zipPath, self::dir() . '/' . $name, 'backup');
            return $name;
        } finally {
            foreach ($tmpFiles as $t) {
                @unlink($t);
            }
            @unlink($zipPath);
        }
    }

    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*' . self::EXT) ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => filesize($f), 'mtime' => filemtime($f)];
        }
        usort($out, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    public static function pathFor(string $name): string
    {
        if (!preg_match('/^dossier_id_[0-9]{8}_[0-9]{6}_[a-f0-9]{4}(_[a-z0-9-]+)?\.didbak$/', $name)) {
            throw new ValidationException('Nom de sauvegarde invalide.');
        }
        $p = self::dir() . '/' . $name;
        if (!is_file($p)) {
            throw new ValidationException('Sauvegarde introuvable.');
        }
        return $p;
    }

    /**
     * Vérifie une sauvegarde sans rien modifier.
     * @return array manifeste
     */
    public static function inspect(string $encryptedPath): array
    {
        $zipPath = self::tmp('.zip');
        try {
            Crypto::decryptFile($encryptedPath, $zipPath, 'backup');
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new ValidationException('Archive de sauvegarde illisible.');
            }
            $manifest = self::checkArchive($zip);
            $zip->close();
            return $manifest;
        } finally {
            @unlink($zipPath);
        }
    }

    private static function checkArchive(\ZipArchive $zip): array
    {
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (!is_array($manifest) || ($manifest['app'] ?? '') !== 'DOSSIER ID') {
            throw new ValidationException('Manifeste de sauvegarde absent ou invalide.');
        }
        if (($manifest['schema_version'] ?? '') !== self::schemaVersion()) {
            throw new ValidationException(sprintf(
                'Version de schéma incompatible (sauvegarde : %s, application : %s).',
                $manifest['schema_version'] ?? '?', self::schemaVersion()
            ));
        }
        if (($manifest['storage_key_fingerprint'] ?? '') !== self::keyFingerprint()) {
            throw new ValidationException('La clé de stockage actuelle ne correspond pas à celle de la sauvegarde. Restaurez d\'abord le fichier storage.key d\'origine.');
        }
        foreach (self::TABLES as $t) {
            $data = $zip->getFromName("db/$t.jsonl");
            if ($data === false || hash('sha256', $data) !== ($manifest['tables'][$t]['sha256'] ?? '')) {
                throw new ValidationException("Intégrité compromise : table $t.");
            }
        }
        foreach ($manifest['files'] as $rel => $sha) {
            if (!preg_match('#^(photos|documents)/[a-f0-9]{4}/[a-f0-9]{32}\.bin$#', (string) $rel)) {
                throw new ValidationException('Chemin de fichier non autorisé dans la sauvegarde.');
            }
            $data = $zip->getFromName("files/$rel");
            if ($data === false || hash('sha256', $data) !== $sha) {
                throw new ValidationException("Intégrité compromise : fichier $rel.");
            }
        }
        return $manifest;
    }

    /**
     * Restaure intégralement une sauvegarde. Une sauvegarde de sécurité de l'état
     * actuel est créée au préalable.
     * @return array{manifest: array, safety: ?string}
     */
    public static function restore(string $encryptedPath, bool $safetyBackup = true): array
    {
        $zipPath = self::tmp('.zip');
        try {
            Crypto::decryptFile($encryptedPath, $zipPath, 'backup');
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new ValidationException('Archive de sauvegarde illisible.');
            }
            $manifest = self::checkArchive($zip);
            $safety = $safetyBackup ? self::create('avant-restauration') : null;

            $pdo = Database::pdo();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $pdo->beginTransaction();
            try {
                foreach (array_reverse(self::TABLES) as $t) {
                    $pdo->exec("DELETE FROM `$t`");
                }
                foreach (self::TABLES as $t) {
                    $lines = explode("\n", (string) $zip->getFromName("db/$t.jsonl"));
                    foreach ($lines as $line) {
                        if ($line === '') {
                            continue;
                        }
                        Database::insert($t, json_decode($line, true, 512, JSON_THROW_ON_ERROR));
                    }
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }

            // Fichiers : écriture des fichiers de la sauvegarde puis suppression des fichiers absents.
            $keep = [];
            foreach ($manifest['files'] as $rel => $sha) {
                $dest = FileStorage::root() . '/' . $rel;
                if (!is_dir(dirname($dest))) {
                    mkdir(dirname($dest), 0750, true);
                }
                file_put_contents($dest, (string) $zip->getFromName("files/$rel"), LOCK_EX);
                $keep[$rel] = true;
            }
            foreach (FileStorage::CATEGORIES as $cat) {
                foreach (glob(FileStorage::root() . "/$cat/*/*.bin") ?: [] as $file) {
                    $rel = $cat . '/' . basename(dirname($file)) . '/' . basename($file);
                    if (!isset($keep[$rel])) {
                        @unlink($file);
                    }
                }
            }
            $zip->close();
            return ['manifest' => $manifest, 'safety' => $safety];
        } finally {
            @unlink($zipPath);
        }
    }

    public static function logRestore(string $name, array $result, ?int $userId, ?string $username): void
    {
        Audit::log('backup.restore', null, null, [
            'sauvegarde' => $name,
            'date_sauvegarde' => $result['manifest']['created_at'] ?? '',
            'sauvegarde_securite' => $result['safety'],
        ], $userId, $username);
    }
}
