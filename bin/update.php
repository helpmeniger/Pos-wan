<?php
/**
 * Mise à jour de POS Marwa depuis un fichier ZIP, en ligne de commande (aucune limite de durée).
 * Utile sur o2switch (cPanel → Terminal) ou un VPS :
 *   php bin/update.php /home/moncompte/POS-Marwa-3.4.0.zip
 * Même contrôle et mêmes sauvegardes que la page « Mise à jour du logiciel ».
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\UpdateService;

$zipPath = $argv[1] ?? '';
if ($zipPath === '' || !is_file($zipPath)) {
    fwrite(STDERR, "Usage : php bin/update.php chemin/vers/POS-Marwa-x.y.z.zip\n");
    exit(1);
}
$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    fwrite(STDERR, "Archive ZIP illisible.\n");
    exit(1);
}
// Dossier racine de l'archive : celui qui contient app/bootstrap.php
$prefix = null;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $n = str_replace('\\', '/', (string) $zip->getNameIndex($i));
    if (preg_match('#^(.*?)app/bootstrap\.php$#', $n, $m)) {
        $prefix = $m[1];
        break;
    }
}
if ($prefix === null) {
    fwrite(STDERR, "Cette archive ne contient pas POS Marwa (app/bootstrap.php introuvable).\n");
    exit(1);
}
$token = UpdateService::start();
$dir = UpdateService::stagingDir($token);
$n = 0;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
    if (!str_starts_with($name, $prefix) || str_ends_with($name, '/')) {
        continue;
    }
    $rel = UpdateService::normalizePath(substr($name, strlen($prefix)));
    if ($rel === null) {
        continue;
    }
    if (!is_dir(dirname($dir . '/' . $rel))) {
        mkdir(dirname($dir . '/' . $rel), 0750, true);
    }
    file_put_contents($dir . '/' . $rel, (string) $zip->getFromIndex($i));
    $n++;
}
$zip->close();
echo "$n fichiers extraits.\n";
$info = UpdateService::inspect($token, false);
foreach ($info['warnings'] as $w) {
    echo "Attention : $w\n";
}
if ($info['errors']) {
    fwrite(STDERR, implode("\n", $info['errors']) . "\n");
    exit(1);
}
echo "Version {$info['current']} → {$info['version']}\n";
do {
    $r = UpdateService::step($token, in_array('--accepter', $argv, true), 30.0);
    echo '  ' . $r['label'] . ($r['total'] ? ' : ' . $r['done_count'] . ' / ' . $r['total'] : '') . "\n";
} while (!$r['done']);
$x = $r['result'];
echo "Mise à jour installée : {$x['changed']} fichier(s) remplacé(s). Sauvegardes : {$x['db_backup']}, {$x['code_backup']}.\n";
// Base mise à jour avec la NOUVELLE version (processus séparé : nouveau code chargé)
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/bin/migrate.php'), $code);
exit($code);
