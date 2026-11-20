<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\BusinessException;

/**
 * Copie des sauvegardes sur un support externe (clé USB, carte mémoire, disque externe, lecteur réseau) et
 * reprise d'une sauvegarde depuis un support (changement d'ordinateur, réinstallation).
 *
 *  - Supports : listés par POS Marwa.exe (édition Bureau : type de lecteur connu de Windows) ; ailleurs, lecteurs
 *    D: à Z: (Windows) ou /media (Linux). Seul un support de la liste peut être choisi.
 *  - Dossier sur le support : « POS Marwa - Sauvegardes\<identifiant d'installation> ».
 *  - Chaque fichier copié est contrôlé (empreinte SHA-256) ; une sauvegarde reprise doit être intacte.
 *
 * Données personnelles (loi n° 2017-28) : une sauvegarde contient les clients, les ventes et les comptes ; le
 * support doit être rangé en lieu sûr.
 */
final class SupportExterne
{
    public const DOSSIER = 'POS Marwa - Sauvegardes';

    /** Tests : supports imposés. */
    public static ?array $forceList = null;

    /** @return list<array{racine:string,type:string,nom:string,libre:int,total:int}> */
    public static function list(): array
    {
        if (self::$forceList !== null) {
            return self::$forceList;
        }
        $port = getenv('POS_BUREAU_PORT');
        $jeton = getenv('POS_BUREAU_JETON');
        if ($port && $jeton) {
            $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => 'X-Bureau-Jeton: ' . $jeton . "\r\n", 'ignore_errors' => true]]);
            $j = @file_get_contents('http://127.0.0.1:' . (int) $port . '/__bureau/supports', false, $ctx);
            $l = is_string($j) ? json_decode($j, true) : null;
            if (is_array($l)) {
                return array_values(array_filter(array_map(fn($s) => is_array($s) && isset($s['racine']) ? [
                    'racine' => (string) $s['racine'], 'type' => (string) ($s['type'] ?? ''), 'nom' => (string) ($s['nom'] ?? ''),
                    'libre' => (int) ($s['libre'] ?? 0), 'total' => (int) ($s['total'] ?? 0)] : null, $l)));
            }
            return [];
        }
        $out = [];
        if (PHP_OS_FAMILY === 'Windows') {
            $sys = strtoupper((string) getenv('SystemDrive')) ?: 'C:';
            foreach (range('D', 'Z') as $d) {
                $r = $d . ':\\';
                if ($d . ':' === $sys || !@is_dir($r)) {
                    continue;
                }
                $out[] = ['racine' => $r, 'type' => 'lecteur', 'nom' => $d . ':', 'libre' => (int) @disk_free_space($r), 'total' => (int) @disk_total_space($r)];
            }
            return $out;
        }
        foreach (array_merge(glob('/media/*/*', GLOB_ONLYDIR) ?: [], glob('/run/media/*/*', GLOB_ONLYDIR) ?: []) as $r) {
            $out[] = ['racine' => $r, 'type' => 'amovible', 'nom' => basename($r), 'libre' => (int) @disk_free_space($r), 'total' => (int) @disk_total_space($r)];
        }
        return $out;
    }

    /** Support choisi : doit figurer dans la liste (aucun chemin saisi librement). */
    private static function root(string $racine): string
    {
        foreach (self::list() as $s) {
            if ($s['racine'] === $racine) {
                return rtrim($racine, '/\\');
            }
        }
        throw new BusinessException('Support introuvable : branchez la clé USB ou le disque, puis actualisez la page.');
    }

    private static function target(string $root): string
    {
        $id = preg_replace('/[^A-Z0-9-]/', '', LicenceService::installId());
        return $root . DIRECTORY_SEPARATOR . self::DOSSIER . DIRECTORY_SEPARATOR . $id;
    }

    /**
     * Copie sur le support : la dernière sauvegarde, ou toutes. @return array{fichiers:int, octets:int, dossier:string}
     */
    public static function copy(string $racine, bool $all, bool $fresh): array
    {
        $root = self::root($racine);
        if ($fresh) {
            BackupService::create('support_externe');
        }
        $backups = BackupService::list();
        if (!$backups) {
            throw new BusinessException('Aucune sauvegarde à copier : créez-en une d\'abord.');
        }
        $dest = self::target($root);
        if (!is_dir($dest) && !@mkdir($dest, 0750, true)) {
            throw new BusinessException('Impossible d\'écrire sur ce support (protégé en écriture ou plein ?).');
        }
        $files = 0;
        $bytes = 0;
        foreach ($all ? $backups : [$backups[0]] as $b) {
            foreach (array_filter([$b['name'], $b['zip']]) as $name) {
                $src = BackupService::path($name);
                $need = filesize($src);
                if (@disk_free_space($dest) !== false && disk_free_space($dest) < $need + 1048576) {
                    throw new BusinessException('Espace insuffisant sur le support pour ' . $name . ' (' . round($need / 1048576, 1) . ' Mo).');
                }
                foreach ([$name, $name . '.sha256'] as $f) {
                    if (is_file(dirname($src) . '/' . $f) && !@copy(dirname($src) . '/' . $f, $dest . DIRECTORY_SEPARATOR . $f)) {
                        throw new BusinessException('Copie interrompue (' . $f . ') : le support a-t-il été retiré ?');
                    }
                }
                if (!hash_equals(hash_file('sha256', $src), (string) @hash_file('sha256', $dest . DIRECTORY_SEPARATOR . $name))) {
                    @unlink($dest . DIRECTORY_SEPARATOR . $name);
                    throw new BusinessException('La copie de ' . $name . ' est abîmée (support défectueux ?) : elle a été retirée du support.');
                }
                $files++;
                $bytes += $need;
            }
        }
        Audit::log('backup.copie_support', null, null, ['support' => $racine, 'fichiers' => $files, 'octets' => $bytes]);
        return ['fichiers' => $files, 'octets' => $bytes, 'dossier' => $dest];
    }

    /** Sauvegardes présentes sur le support (toutes installations). @return list<array{rel:string,name:string,size:int,date:string}> */
    public static function found(string $racine): array
    {
        $root = self::root($racine);
        $out = [];
        foreach (glob($root . DIRECTORY_SEPARATOR . self::DOSSIER . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'poswan_*.sql.gz') ?: [] as $f) {
            $out[] = ['rel' => basename(dirname($f)) . '/' . basename($f), 'name' => basename($f), 'size' => (int) filesize($f), 'date' => date('Y-m-d H:i', (int) filemtime($f))];
        }
        usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
        return array_slice($out, 0, 100);
    }

    /**
     * Reprise d'une sauvegarde du support dans storage/backups (elle n'est PAS restaurée : la restauration reste
     * l'opération confirmée de la page Sauvegardes, avec le mot de passe administrateur).
     */
    public static function import(string $racine, string $rel): string
    {
        $root = self::root($racine);
        if (!preg_match('#^([A-Z0-9-]{1,40})/(poswan_\d{8}_\d{6}_[a-z0-9_-]+\.sql\.gz)$#i', $rel, $m)) {
            throw new BusinessException('Sauvegarde invalide.');
        }
        $dir = $root . DIRECTORY_SEPARATOR . self::DOSSIER . DIRECTORY_SEPARATOR . $m[1];
        $name = $m[2];
        $dst = BackupService::dir() . '/' . $name;
        if (is_file($dst)) {
            throw new BusinessException('Cette sauvegarde est déjà dans POS Marwa : elle figure dans la liste.');
        }
        $zip = str_replace('.sql.gz', '_fichiers.zip', $name);
        $copied = [];
        $undo = function () use (&$copied): void { // seuls les fichiers copiés à l'instant sont retirés
            foreach ($copied as $f) {
                @unlink(BackupService::dir() . '/' . $f);
            }
        };
        foreach ([$name, $name . '.sha256', $zip, $zip . '.sha256'] as $f) {
            if (!is_file($dir . DIRECTORY_SEPARATOR . $f) || is_file(BackupService::dir() . '/' . $f)) {
                continue;
            }
            if (!@copy($dir . DIRECTORY_SEPARATOR . $f, BackupService::dir() . '/' . $f)) {
                $undo();
                throw new BusinessException('Lecture du support interrompue (' . $f . ').');
            }
            $copied[] = $f;
        }
        if (!BackupService::verify($name)) {
            $undo();
            throw new BusinessException('Sauvegarde du support abîmée ou incomplète (empreinte différente) : elle n\'a pas été reprise.');
        }
        Audit::log('backup.reprise_support', null, null, ['support' => $racine, 'fichier' => $name]);
        return $name;
    }
}
