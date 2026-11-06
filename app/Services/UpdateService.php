<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Addons;
use App\Core\Audit;
use App\Core\BusinessException;

/**
 * Mise à jour de POS Marwa depuis le navigateur : l'administrateur sélectionne le dossier
 * complet de la nouvelle version ; les fichiers sont envoyés par lots dans un dossier de
 * préparation (storage/tmp/update_<jeton>), contrôlés, puis seuls les fichiers modifiés sont
 * copiés sur l'installation, après une double sauvegarde (base de données + code remplacé), par
 * étapes courtes (compatible avec les hébergements qui coupent les requêtes longues). La base
 * est ensuite mise à jour automatiquement au chargement suivant (migrations + SchemaSync).
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

    /**
     * Contrôle de la version préparée.
     * $hash = false : contrôle rapide (version, fichiers essentiels) ; l'empreinte de chaque fichier
     * est alors vérifiée pendant l'installation, par étapes courtes, avant toute modification.
     * @return array{version, current, files, errors, warnings, verified, manifest}
     */
    public static function inspect(string $token, bool $hash = true): array
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
        $hasManifest = false;
        $rel = $dir . '/release.json';
        if (is_file($rel)) {
            $manifest = json_decode((string) file_get_contents($rel), true);
            if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
                $errors[] = 'release.json illisible.';
            } else {
                $hasManifest = true;
                if (($manifest['version'] ?? null) !== $version) {
                    $errors[] = 'release.json ne correspond pas à la version ' . $version . '.';
                }
                if ($hash) {
                    [$missing, $bad] = self::verifyFiles($dir, $manifest['files']);
                    if ($missing) {
                        $errors[] = count($missing) . ' fichier(s) manquant(s) (copie incomplète ou envoi interrompu), ex. : ' . implode(', ', array_slice($missing, 0, 5));
                    }
                    if ($bad) {
                        $errors[] = count($bad) . ' fichier(s) modifié(s) ou endommagé(s), ex. : ' . implode(', ', array_slice($bad, 0, 5));
                    }
                    $verified = !$missing && !$bad;
                } else {
                    $missing = array_filter(array_keys($manifest['files']), fn($p) => ($q = self::normalizePath((string) $p)) !== null && !is_file($dir . '/' . $q));
                    if ($missing) {
                        $errors[] = count($missing) . ' fichier(s) manquant(s) (copie incomplète ou envoi interrompu), ex. : ' . implode(', ', array_slice(array_values($missing), 0, 5));
                    }
                }
            }
        } else {
            $warnings[] = 'Pas de fichier release.json : l\'intégrité des fichiers ne peut pas être vérifiée. Utilisez de préférence une version officielle.';
        }
        return ['version' => $version, 'current' => $current, 'files' => count($files), 'errors' => $errors, 'warnings' => $warnings, 'verified' => $verified, 'manifest' => $hasManifest];
    }

    /** @return array{0: string[], 1: string[]} fichiers manquants, fichiers altérés */
    private static function verifyFiles(string $dir, array $hashes, ?float $deadline = null, int &$offset = 0): array
    {
        $missing = $bad = [];
        $paths = array_keys($hashes);
        for ($n = count($paths), $done = 0; $offset < $n; $offset++, $done++) {
            if ($deadline !== null && $done > 0 && microtime(true) > $deadline) {
                break; // au moins un fichier par étape : l'installation avance toujours
            }
            $p = self::normalizePath((string) $paths[$offset]);
            if ($p === null) {
                continue;
            }
            if (!is_file($dir . '/' . $p)) {
                $missing[] = $p;
            } elseif (!hash_equals((string) $hashes[$paths[$offset]], hash_file('sha256', $dir . '/' . $p))) {
                $bad[] = $p;
            }
        }
        return [$missing, $bad];
    }

    // ------------------------------------------------------------------ installation par étapes
    //
    // Un hébergement mutualisé (o2switch…) coupe une requête trop longue : « 504 ». L'installation
    // est donc découpée en étapes de quelques secondes, rappelées par la page jusqu'à la fin :
    // vérification → fichiers modifiés → sauvegarde de la base → sauvegarde du code concerné →
    // copie → finalisation. L'état est enregistré après chaque lot : une étape coupée reprend où
    // elle s'était arrêtée, sans jamais refaire ni casser ce qui est fait.

    public const PHASES = [
        'verify' => 'Vérification des fichiers reçus',
        'diff' => 'Recherche des fichiers modifiés',
        'backup_db' => 'Sauvegarde de la base de données',
        'backup_code' => 'Sauvegarde du code remplacé',
        'copy' => 'Installation des nouveaux fichiers',
        'finish' => 'Finalisation',
    ];

    private static function stateFile(string $token): string
    {
        return self::stagingDir($token) . '/.maj-state.json';
    }

    /** Une étape (au plus $budget secondes). @return array{done: bool, phase: string, label: string, done_count: int, total: int, result?: array} */
    public static function step(string $token, bool $acceptUnverified = false, float $budget = 8.0): array
    {
        $dir = self::stagingDir($token);
        if (!is_dir($dir)) {
            throw new BusinessException('Préparation introuvable : envoyez de nouveau les fichiers.');
        }
        $fh = fopen($dir . '/.maj-lock', 'c');
        if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
            return ['done' => false, 'phase' => 'wait', 'label' => 'Étape précédente encore en cours…', 'done_count' => 0, 'total' => 0];
        }
        @set_time_limit(max(60, (int) $budget * 6));
        @ignore_user_abort(true);
        $deadline = microtime(true) + $budget;
        try {
            $sf = self::stateFile($token);
            $st = is_file($sf) ? (json_decode((string) file_get_contents($sf), true) ?: []) : [];
            if (!$st) {
                $info = self::inspect($token, false);
                if ($info['errors']) {
                    throw new BusinessException(implode(' ', $info['errors']));
                }
                if (!$info['manifest'] && !$acceptUnverified) {
                    throw new BusinessException('Intégrité non vérifiée : cochez la confirmation pour continuer.');
                }
                $st = ['phase' => $info['manifest'] ? 'verify' : 'diff', 'offset' => 0, 'files' => self::listFiles($dir), 'changed' => [],
                    'version' => $info['version'], 'current' => $info['current'], 'db' => null, 'code' => null, 'copied' => 0, 'started' => date('c')];
            }
            // Par référence : chaque sauvegarde enregistre l'avancement réel (une étape coupée reprend là)
            $save = function () use (&$st, $sf) {
                file_put_contents($sf, json_encode($st, JSON_UNESCAPED_SLASHES), LOCK_EX);
            };
            $total = count($st['files']);
            $save();
            do {
                switch ($st['phase']) {
                    case 'verify':
                        $hashes = (array) (json_decode((string) file_get_contents($dir . '/release.json'), true)['files'] ?? []);
                        $off = (int) $st['offset'];
                        [$missing, $bad] = self::verifyFiles($dir, $hashes, $deadline, $off);
                        if ($missing || $bad) {
                            @unlink($sf);
                            throw new BusinessException(($missing ? count($missing) . ' fichier(s) manquant(s), ex. : ' . implode(', ', array_slice($missing, 0, 3)) . '. ' : '')
                                . ($bad ? count($bad) . ' fichier(s) modifié(s) ou endommagé(s), ex. : ' . implode(', ', array_slice($bad, 0, 3)) . '. ' : '') . 'Rien n\'a été modifié : envoyez de nouveau le dossier.');
                        }
                        $st['offset'] = $off;
                        if ($off >= count($hashes)) {
                            $st['phase'] = 'diff';
                            $st['offset'] = 0;
                        }
                        break;
                    case 'diff':
                        // Seuls les fichiers nouveaux ou différents seront sauvegardés puis copiés
                        for ($k = 0; $st['offset'] < $total && ($k === 0 || microtime(true) < $deadline); $st['offset']++, $k++) {
                            $rel = $st['files'][$st['offset']];
                            $cur = BASE_PATH . '/' . $rel;
                            if (!is_file($cur) || filesize($cur) !== filesize($dir . '/' . $rel) || !hash_equals(hash_file('sha256', $cur), hash_file('sha256', $dir . '/' . $rel))) {
                                $st['changed'][] = $rel;
                            }
                        }
                        if ($st['offset'] >= $total) {
                            $st['phase'] = 'backup_db';
                            $st['offset'] = 0;
                        }
                        break;
                    case 'backup_db':
                        $st['db'] = BackupService::create('avant_maj_' . str_replace('.', '_', (string) $st['version']), false)['sql'];
                        $st['phase'] = 'backup_code';
                        $st['code'] = 'code_' . date('Ymd_His') . '_v' . preg_replace('/[^0-9.]/', '', (string) $st['current']) . '.zip';
                        break;
                    case 'backup_code':
                        if (!class_exists(\ZipArchive::class)) {
                            throw new BusinessException('Extension PHP « zip » requise pour sauvegarder le code avant la mise à jour.');
                        }
                        $zip = new \ZipArchive();
                        if ($zip->open(BackupService::dir() . '/' . $st['code'], \ZipArchive::CREATE) !== true) {
                            throw new BusinessException('Impossible de créer la sauvegarde du code.');
                        }
                        $n = count($st['changed']);
                        $added = 0;
                        for ($k = 0; $st['offset'] < $n && $added < 300 && ($k === 0 || microtime(true) < $deadline); $st['offset']++, $k++) {
                            $rel = $st['changed'][$st['offset']];
                            if (is_file(BASE_PATH . '/' . $rel)) {
                                $zip->addFile(BASE_PATH . '/' . $rel, $rel);
                                $added++;
                            }
                        }
                        if ($zip->numFiles === 0) {
                            $zip->addFromString('LISEZMOI.txt', 'Aucun fichier existant remplacé par cette mise à jour.');
                        }
                        $zip->close();
                        if ($st['offset'] >= $n) {
                            $st['phase'] = 'copy';
                            $st['offset'] = 0;
                        }
                        break;
                    case 'copy':
                        file_put_contents(STORAGE_PATH . '/maintenance.lock', (string) time());
                        $n = count($st['changed']);
                        for ($k = 0; $st['offset'] < $n && ($k === 0 || microtime(true) < $deadline); $st['offset']++, $k++) {
                            self::copyOne($dir, $st['changed'][$st['offset']], (string) $st['code']);
                            $st['copied']++;
                            if ($st['offset'] % 25 === 0) {
                                $save();
                            }
                        }
                        if ($st['offset'] >= $n) {
                            $st['phase'] = 'finish';
                        }
                        break;
                    case 'finish':
                        if (function_exists('opcache_reset')) {
                            @opcache_reset();
                        }
                        foreach (array_keys(Addons::installed()) as $mod) {
                            if (is_dir(Addons::dir() . '/' . $mod . '/public')) {
                                Addons::publishAssets($mod);
                            }
                        }
                        // La base sera synchronisée par la nouvelle version au chargement suivant
                        @unlink(STORAGE_PATH . '/cache/schema_sync');
                        $result = ['from' => $st['current'], 'to' => $st['version'], 'files' => $total, 'changed' => count($st['changed']), 'code_backup' => $st['code'], 'db_backup' => $st['db']];
                        Audit::log('system.update', null, null, $result);
                        @unlink(STORAGE_PATH . '/maintenance.lock');
                        flock($fh, LOCK_UN);
                        fclose($fh);
                        $fh = null;
                        self::rrmdir($dir);
                        return ['done' => true, 'phase' => 'finish', 'label' => self::PHASES['finish'], 'done_count' => $total, 'total' => $total, 'result' => $result];
                }
                $save();
            } while (microtime(true) < $deadline);
            $count = match ($st['phase']) { 'verify', 'diff' => (int) $st['offset'], 'backup_code', 'copy' => (int) $st['offset'], default => 0 };
            $of = in_array($st['phase'], ['backup_code', 'copy'], true) ? count($st['changed']) : $total;
            return ['done' => false, 'phase' => $st['phase'], 'label' => self::PHASES[$st['phase']], 'done_count' => $count, 'total' => $of, 'changed' => count($st['changed'])];
        } catch (\Throwable $e) {
            // Jamais de site bloqué en « maintenance » après une erreur : la reprise reste possible
            @unlink(STORAGE_PATH . '/maintenance.lock');
            throw $e;
        } finally {
            if ($fh) {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
    }

    private static function copyOne(string $dir, string $rel, string $codeBackup): void
    {
        $target = BASE_PATH . '/' . $rel;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        // Copie vers un fichier temporaire puis renommage : jamais de fichier à moitié écrit
        $tmp = $target . '.maj-tmp';
        if (!@copy($dir . '/' . $rel, $tmp) || !@rename($tmp, $target)) {
            @unlink($tmp);
            if (!@copy($dir . '/' . $rel, $target)) {
                throw new BusinessException('Écriture impossible : ' . $rel . ' (le serveur n\'a pas le droit de modifier les fichiers du logiciel : vérifiez les droits du dossier). Le site reste utilisable ; le code déjà remplacé est sauvegardé dans ' . $codeBackup . ' — cliquez sur « Reprendre l\'installation » une fois les droits corrigés.');
            }
        }
    }

    /** Installation complète en une fois (ligne de commande, tests) : les étapes s'enchaînent. */
    public static function apply(string $token, bool $acceptUnverified = false): array
    {
        do {
            $r = self::step($token, $acceptUnverified, 30.0);
        } while (!$r['done']);
        return $r['result'];
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
