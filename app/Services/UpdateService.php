<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Addons;
use App\Core\Audit;
use App\Core\BusinessException;

/**
 * Mise à jour de POS Marwa depuis le navigateur : l'administrateur sélectionne le dossier
 * complet de la nouvelle version ; les fichiers sont envoyés par lots dans un dossier de
 * préparation (storage/tmp/update_<jeton>), contrôlés, puis copiés sur l'installation après
 * une double sauvegarde (base de données + code actuel). La base est ensuite mise à jour
 * automatiquement au chargement suivant (InstallService::autoMigrate).
 *
 * Jamais touchés : .env (accès à la base), storage/ (sauvegardes, journaux, sessions),
 * public/uploads/ (photos). Les modules ajoutés par l'utilisateur dans addons/ sont conservés.
 */
final class UpdateService
{
    /** Dossiers de premier niveau acceptés dans une nouvelle version. */
    private const ROOT_DIRS = ['app', 'bin', 'config', 'database', 'docs', 'lang', 'public', 'routes', 'addons', 'tests', 'windows'];
    /** Fichiers de premier niveau acceptés. */
    private const ROOT_FILES = '/^(\.htaccess|\.env\.example|\.gitignore|\.gitattributes|release\.json|demarrer-pos-marwa\.bat|[A-Za-z0-9_-]+\.(md|txt))$/';
    /** Jamais remplacés (données et réglages de l'installation). */
    private const EXCLUDED = ['storage/', 'public/uploads/', 'public/assets/addons/', '.git/'];
    private const MAX_FILE = 20 * 1024 * 1024;

    public static function stagingDir(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new BusinessException('Jeton de mise à jour invalide.');
        }
        return STORAGE_PATH . '/tmp/update_' . $token;
    }

    /** Chemin relatif contrôlé (aucune traversée de répertoire), ou null si le fichier est ignoré. */
    public static function normalizePath(string $rel): ?string
    {
        $rel = str_replace('\\', '/', trim($rel));
        $rel = ltrim($rel, '/');
        if ($rel === '' || str_contains($rel, "\0") || preg_match('#(^|/)\.\.?(/|$)#', $rel) || preg_match('/^[a-zA-Z]:/', $rel) || preg_match('/[\x00-\x1f]/', $rel)) {
            return null;
        }
        foreach (self::EXCLUDED as $x) {
            if (str_starts_with($rel, $x)) {
                return null;
            }
        }
        if ($rel === '.env') {
            return null;
        }
        $first = explode('/', $rel, 2);
        if (count($first) === 1) {
            return preg_match(self::ROOT_FILES, $rel) ? $rel : null;
        }
        return in_array($first[0], self::ROOT_DIRS, true) ? $rel : null;
    }

    /** Démarre une mise à jour : nettoie les anciennes préparations, crée un dossier vide. */
    public static function start(): string
    {
        foreach (glob(STORAGE_PATH . '/tmp/update_*', GLOB_ONLYDIR) ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                self::rrmdir($old);
            }
        }
        $token = bin2hex(random_bytes(16));
        mkdir(self::stagingDir($token), 0750, true);
        return $token;
    }

    /**
     * Reçoit un lot de fichiers. $paths[i] = chemin relatif de $files['tmp_name'][i].
     * @return array{saved:int, skipped:int}
     */
    public static function receive(string $token, array $paths, array $files, bool $uploaded = true): array
    {
        $dir = self::stagingDir($token);
        if (!is_dir($dir)) {
            throw new BusinessException('Préparation introuvable : recommencez la sélection du dossier.');
        }
        $saved = $skipped = 0;
        foreach ((array) ($files['tmp_name'] ?? []) as $i => $tmp) {
            $rel = self::normalizePath((string) ($paths[$i] ?? ''));
            if ($rel === null || (int) ($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK || (int) ($files['size'][$i] ?? 0) > self::MAX_FILE) {
                $skipped++;
                continue;
            }
            if ($uploaded && !is_uploaded_file($tmp)) {
                $skipped++;
                continue;
            }
            $target = $dir . '/' . $rel;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0750, true);
            }
            $ok = $uploaded ? move_uploaded_file($tmp, $target) : copy($tmp, $target);
            $ok ? $saved++ : $skipped++;
        }
        touch($dir);
        return ['saved' => $saved, 'skipped' => $skipped];
    }

    /** Version déclarée dans config/app.php (lecture du texte : le fichier n'est pas exécuté). */
    public static function versionOf(string $configFile): ?string
    {
        $src = is_file($configFile) ? (string) file_get_contents($configFile) : '';
        return preg_match("/'version'\\s*=>\\s*'(\\d+\\.\\d+\\.\\d+)'/", $src, $m) ? $m[1] : null;
    }

    /** Contrôle de la version préparée. @return array{version, current, files, errors, warnings, verified} */
    public static function inspect(string $token): array
    {
        $dir = self::stagingDir($token);
        $errors = $warnings = [];
        foreach (['app/bootstrap.php', 'public/index.php', 'config/app.php', 'database/schema.sql', 'routes/web.php'] as $must) {
            if (!is_file($dir . '/' . $must)) {
                $errors[] = 'Fichier essentiel absent : ' . $must . ' (sélectionnez le dossier complet de POS Marwa, celui qui contient app, public, config…).';
            }
        }
        $current = (string) config('app.version');
        $version = self::versionOf($dir . '/config/app.php');
        if ($version === null) {
            $errors[] = 'Version de la nouvelle copie illisible (config/app.php).';
        } elseif (version_compare($version, $current, '<')) {
            $errors[] = 'Version ' . $version . ' plus ancienne que la version installée (' . $current . ') : retour en arrière refusé (la base de données a déjà évolué).';
        } elseif ($version === $current) {
            $warnings[] = 'Même version que celle installée (' . $current . ') : les fichiers seront simplement remplacés (réparation).';
        }
        $files = self::listFiles($dir);
        $verified = false;
        $rel = $dir . '/release.json';
        if (is_file($rel)) {
            $manifest = json_decode((string) file_get_contents($rel), true);
            if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
                $errors[] = 'release.json illisible.';
            } else {
                $missing = $bad = [];
                foreach ($manifest['files'] as $path => $hash) {
                    $p = self::normalizePath((string) $path);
                    if ($p === null) {
                        continue;
                    }
                    if (!is_file($dir . '/' . $p)) {
                        $missing[] = $p;
                    } elseif (!hash_equals((string) $hash, hash_file('sha256', $dir . '/' . $p))) {
                        $bad[] = $p;
                    }
                }
                if (($manifest['version'] ?? null) !== $version) {
                    $errors[] = 'release.json ne correspond pas à la version ' . $version . '.';
                }
                if ($missing) {
                    $errors[] = count($missing) . ' fichier(s) manquant(s) (copie incomplète ou envoi interrompu), ex. : ' . implode(', ', array_slice($missing, 0, 5));
                }
                if ($bad) {
                    $errors[] = count($bad) . ' fichier(s) modifié(s) ou endommagé(s), ex. : ' . implode(', ', array_slice($bad, 0, 5));
                }
                $verified = !$missing && !$bad;
            }
        } else {
            $warnings[] = 'Pas de fichier release.json : l\'intégrité des fichiers ne peut pas être vérifiée. Utilisez de préférence une version officielle.';
        }
        return ['version' => $version, 'current' => $current, 'files' => count($files), 'errors' => $errors, 'warnings' => $warnings, 'verified' => $verified];
    }

    /**
     * Applique la mise à jour : sauvegarde de la base et du code actuel, copie des fichiers,
     * publication des fichiers des modules installés. @return array résumé
     */
    public static function apply(string $token, bool $acceptUnverified = false): array
    {
        $info = self::inspect($token);
        if ($info['errors']) {
            throw new BusinessException(implode(' ', $info['errors']));
        }
        if (!$info['verified'] && !$acceptUnverified) {
            throw new BusinessException('Intégrité non vérifiée : cochez la confirmation pour continuer.');
        }
        @set_time_limit(900);
        $dir = self::stagingDir($token);
        $files = self::listFiles($dir);
        $lock = STORAGE_PATH . '/maintenance.lock';
        file_put_contents($lock, (string) time());
        try {
            $db = BackupService::create('avant_maj_' . str_replace('.', '_', $info['version']));
            $code = self::backupCode($info['current']);
            $copied = 0;
            foreach ($files as $rel) {
                $target = BASE_PATH . '/' . $rel;
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0755, true);
                }
                // Copie vers un fichier temporaire puis renommage : jamais de fichier à moitié écrit
                $tmp = $target . '.maj-tmp';
                if (!copy($dir . '/' . $rel, $tmp) || !@rename($tmp, $target)) {
                    @unlink($tmp);
                    if (!copy($dir . '/' . $rel, $target)) {
                        throw new \RuntimeException('Écriture impossible : ' . $rel . ' (droits du dossier ?). Restaurez le code depuis ' . $code . '.');
                    }
                }
                $copied++;
            }
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            foreach (array_keys(Addons::installed()) as $mod) {
                if (is_dir(Addons::dir() . '/' . $mod . '/public')) {
                    Addons::publishAssets($mod);
                }
            }
            Audit::log('system.update', null, null, ['from' => $info['current'], 'to' => $info['version'], 'files' => $copied, 'code_backup' => $code, 'db_backup' => $db['sql']]);
            return ['from' => $info['current'], 'to' => $info['version'], 'files' => $copied, 'code_backup' => $code, 'db_backup' => $db['sql']];
        } finally {
            @unlink($lock);
            self::rrmdir($dir);
        }
    }

    /** Archive ZIP du code actuel (sans .env, storage, uploads) pour pouvoir revenir en arrière. */
    public static function backupCode(string $version): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new BusinessException('Extension PHP « zip » requise pour sauvegarder le code avant la mise à jour (WampServer : PHP → Extensions → zip).');
        }
        $name = 'code_' . date('Ymd_His') . '_v' . preg_replace('/[^0-9.]/', '', $version) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open(BackupService::dir() . '/' . $name, \ZipArchive::CREATE) !== true) {
            throw new BusinessException('Impossible de créer la sauvegarde du code.');
        }
        foreach (self::listFiles(BASE_PATH) as $rel) {
            $zip->addFile(BASE_PATH . '/' . $rel, $rel);
        }
        $zip->close();
        return $name;
    }

    /** Sauvegardes de code disponibles (retour à la version précédente). */
    public static function codeBackups(): array
    {
        $out = [];
        foreach (glob(BackupService::dir() . '/code_*.zip') ?: [] as $f) {
            preg_match('/_v([0-9.]+)\.zip$/', $f, $m);
            $out[] = ['name' => basename($f), 'version' => $m[1] ?? '?', 'date' => date('Y-m-d H:i', filemtime($f)), 'size' => filesize($f)];
        }
        usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /** Remet le code d'une sauvegarde (la base n'est pas modifiée). */
    public static function restoreCode(string $name): int
    {
        if (!preg_match('/^code_\d{8}_\d{6}_v[0-9.]+\.zip$/', $name) || !is_file(BackupService::dir() . '/' . $name)) {
            throw new BusinessException('Sauvegarde de code introuvable.');
        }
        $zip = new \ZipArchive();
        if ($zip->open(BackupService::dir() . '/' . $name) !== true) {
            throw new BusinessException('Archive illisible.');
        }
        self::backupCode((string) config('app.version')); // l'état actuel reste récupérable
        $n = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $rel = self::normalizePath((string) $zip->getNameIndex($i));
            if ($rel === null || str_ends_with($rel, '/')) {
                continue;
            }
            $target = BASE_PATH . '/' . $rel;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            if (file_put_contents($target, (string) $zip->getFromIndex($i)) !== false) {
                $n++;
            }
        }
        $zip->close();
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        Audit::log('system.update.rollback', null, null, ['backup' => $name, 'files' => $n]);
        return $n;
    }

    /** Liste des fichiers (chemins relatifs acceptés) d'un dossier. */
    public static function listFiles(string $root): array
    {
        $out = [];
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            function (\SplFileInfo $f) use ($root) {
                $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($root) + 1);
                return !in_array($rel, ['.git', 'storage', 'public/uploads', 'public/assets/addons', 'node_modules', 'vendor'], true);
            }
        ));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $rel = self::normalizePath(substr(str_replace('\\', '/', $f->getPathname()), strlen($root) + 1));
                if ($rel !== null && !str_ends_with($rel, '.maj-tmp')) {
                    $out[] = $rel;
                }
            }
        }
        sort($out);
        return $out;
    }

    /** Manifeste d'intégrité d'une version (utilisé par bin/release.php). */
    public static function buildManifest(string $root): array
    {
        $files = [];
        foreach (self::listFiles($root) as $rel) {
            if ($rel !== 'release.json') {
                $files[$rel] = hash_file('sha256', $root . '/' . $rel);
            }
        }
        return ['product' => 'POS Marwa', 'version' => self::versionOf($root . '/config/app.php'), 'date' => date('c'), 'files' => $files];
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
