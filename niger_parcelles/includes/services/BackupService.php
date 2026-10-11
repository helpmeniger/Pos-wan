<?php
/**
 * Sauvegarde et restauration : export SQL complet généré en PHP (aucun
 * mysqldump requis) + archive ZIP des documents.
 */
declare(strict_types=1);

final class BackupService
{
    public const HEADER = '-- Niger Parcelles backup';

    public static function dir(): string
    {
        $d = storage_path('backups');
        if (!is_dir($d)) {
            mkdir($d, 0750, true);
        }
        return $d;
    }

    /** Crée un fichier .sql complet. Retourne son nom. */
    public static function createSql(): string
    {
        $name = 'sauvegarde_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.sql';
        $fh = fopen(self::dir() . '/' . $name, 'wb');
        fwrite($fh, self::HEADER . ' ' . date('c') . "\n-- Restauration : Paramètres > Sauvegardes, ou phpMyAdmin > Importer.\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        $pdo = db();
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            if (!preg_match('/^[a-z_]+$/', (string) $t)) {
                continue;
            }
            $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
            fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n$create;\n\n");
            $stmt = $pdo->query("SELECT * FROM `$t`");
            $batch = [];
            $cols = null;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cols = $cols ?? '(`' . implode('`,`', array_keys($row)) . '`)';
                $batch[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), $row)) . ')';
                if (count($batch) >= 200) {
                    fwrite($fh, "INSERT INTO `$t` $cols VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                fwrite($fh, "INSERT INTO `$t` $cols VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            fwrite($fh, "\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($fh);
        audit_log('sauvegarde_sql', null, null, ['fichier' => $name]);
        return $name;
    }

    /** Archive ZIP des documents privés. Retourne son nom ou null si zip indisponible. */
    public static function createDocumentsZip(): ?string
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }
        $name = 'documents_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        $zip->open(self::dir() . '/' . $name, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $base = realpath(storage_path('documents'));
        if ($base) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getFilename() !== '.gitkeep') {
                    $zip->addFile($f->getPathname(), 'documents/' . str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1)));
                }
            }
        }
        $zip->addFromString('LISEZMOI.txt', "Documents Niger Parcelles - copiez le dossier documents/ dans storage/ pour restaurer.\n");
        $zip->close();
        audit_log('sauvegarde_documents', null, null, ['fichier' => $name]);
        return $name;
    }

    public static function listBackups(): array
    {
        $out = [];
        foreach (scandir(self::dir()) ?: [] as $n) {
            $f = self::dir() . '/' . $n;
            if (is_file($f) && preg_match('/\.(sql|zip)$/', $n)) {
                $out[] = ['name' => $n, 'size' => filesize($f), 'date' => filemtime($f)];
            }
        }
        usort($out, static fn ($a, $b) => $b['date'] <=> $a['date']);
        return $out;
    }

    public static function path(string $name): string
    {
        if (!preg_match('/^(sauvegarde|documents)_\d{8}_\d{6}_[a-f0-9]{6}\.(sql|zip)$/', $name)) {
            throw new UserFacingException('Nom de sauvegarde invalide.');
        }
        $p = self::dir() . '/' . $name;
        if (!is_file($p)) {
            throw new UserFacingException('Sauvegarde introuvable.', 404);
        }
        return $p;
    }

    /** Restaure un fichier SQL produit par createSql(). Une sauvegarde de sécurité est faite avant. */
    public static function restore(string $file): array
    {
        $head = (string) file_get_contents($file, false, null, 0, 200);
        if (strpos($head, self::HEADER) !== 0) {
            throw new UserFacingException('Ce fichier n\'est pas une sauvegarde Niger Parcelles (en-tête absent). Utilisez phpMyAdmin pour les autres fichiers.');
        }
        $safety = self::createSql();
        $pdo = db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $n = SqlRunner::runFile($pdo, $file);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        audit_log('restauration', null, null, ['instructions' => $n, 'sauvegarde_securite' => $safety]);
        return ['statements' => $n, 'safety' => $safety];
    }

    /** Supprime les données de démonstration (is_demo = 1) et leurs dépendances. */
    public static function purgeDemo(): array
    {
        return db_transaction(static function () {
            $ids = array_map('intval', array_column(db_all('SELECT id FROM parcels WHERE is_demo = 1'), 'id'));
            $owners = array_map('intval', array_column(db_all('SELECT id FROM owners WHERE is_demo = 1'), 'id'));
            $files = [];
            if ($ids) {
                $in = implode(',', $ids);
                $files = array_column(db_all("SELECT storage_filename FROM documents WHERE parcel_id IN ($in)"), 'storage_filename');
                db()->exec("DELETE FROM parcel_overlaps WHERE parcel_a_id IN ($in) OR parcel_b_id IN ($in)");
                db()->exec("DELETE FROM parcel_history WHERE parcel_id IN ($in)");
                db()->exec("UPDATE documents SET replaces_id = NULL WHERE parcel_id IN ($in)");
                db()->exec("DELETE FROM documents WHERE parcel_id IN ($in)");
                db()->exec("DELETE FROM parcel_owners WHERE parcel_id IN ($in)");
                db()->exec("DELETE FROM mutations WHERE parcel_id IN ($in)");
                db()->exec("DELETE FROM parcel_geometries WHERE parcel_id IN ($in)");
                db()->exec("DELETE FROM parcels WHERE id IN ($in)");
            }
            if ($owners) {
                $in = implode(',', $owners);
                $files = array_merge($files, array_column(db_all("SELECT storage_filename FROM documents WHERE owner_id IN ($in)"), 'storage_filename'));
                db()->exec("DELETE FROM documents WHERE owner_id IN ($in)");
                db()->exec("DELETE FROM parcel_owners WHERE owner_id IN ($in)");
                db()->exec("DELETE FROM owners WHERE id IN ($in)");
            }
            foreach ($files as $f) {
                @unlink(storage_path('documents/' . $f));
            }
            db()->exec("UPDATE parcels p SET has_overlap_warning = EXISTS(SELECT 1 FROM parcel_overlaps o
                        WHERE o.status = 'a_verifier' AND (o.parcel_a_id = p.id OR o.parcel_b_id = p.id))");
            audit_log('purge_demo', null, null, ['parcelles' => count($ids), 'proprietaires' => count($owners)]);
            return ['parcels' => count($ids), 'owners' => count($owners)];
        });
    }
}
