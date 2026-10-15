<?php
declare(strict_types=1);

/**
 * Sauvegarde de la base (mysqldump) et des fichiers téléversés dans storage/backups/.
 *   php bin/backup.php [--keep=14] [--mysqldump="C:\wamp64\bin\mysql\mysql8.x\bin\mysqldump.exe"]
 * Restauration : voir docs/SAUVEGARDE.md
 */
require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Config;

$o = getopt('', ['keep:', 'mysqldump:']);
$c = Config::get('database');
$dir = MARWA_ROOT . '/storage/backups';
$stamp = date('Ymd-His');
$bin = $o['mysqldump'] ?? 'mysqldump';
$sqlFile = "$dir/marwa-db-$stamp.sql";
$cnf = tempnam(sys_get_temp_dir(), 'mwb');
file_put_contents($cnf, "[client]\nuser=\"{$c['user']}\"\npassword=\"{$c['password']}\"\nhost=\"{$c['host']}\"\nport={$c['port']}\n");
$cmd = sprintf('%s --defaults-extra-file=%s --single-transaction --routines --triggers --default-character-set=utf8mb4 %s > %s',
    escapeshellarg($bin), escapeshellarg($cnf), escapeshellarg($c['name']), escapeshellarg($sqlFile));
exec($cmd, $out, $code);
@unlink($cnf);
if ($code !== 0 || !is_file($sqlFile) || filesize($sqlFile) === 0) {
    fwrite(STDERR, "Échec de mysqldump (code $code). Vérifiez le chemin avec --mysqldump.\n");
    exit(1);
}
$gz = gzopen("$sqlFile.gz", 'wb9');
gzwrite($gz, (string) file_get_contents($sqlFile));
gzclose($gz);
unlink($sqlFile);
echo "Base sauvegardée : $sqlFile.gz\n";

if (class_exists(ZipArchive::class)) {
    $zip = new ZipArchive();
    $zipFile = "$dir/marwa-uploads-$stamp.zip";
    $zip->open($zipFile, ZipArchive::CREATE);
    $base = MARWA_ROOT . '/storage/uploads';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getFilename() !== '.gitignore') {
            $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($base) + 1));
        }
    }
    // Clés indispensables : sans elles, les doubles authentifications et les abonnements
    // aux notifications push ne fonctionneraient plus après une restauration.
    foreach (['app.key', 'vapid.json'] as $k) {
        if (is_file(MARWA_ROOT . '/storage/' . $k)) {
            $zip->addFile(MARWA_ROOT . '/storage/' . $k, '_cles/' . $k);
        }
    }
    $zip->close();
    @chmod($zipFile, 0600);
    echo "Fichiers et clés sauvegardés : $zipFile\n";
}
$keep = (int) ($o['keep'] ?? 14);
$files = glob("$dir/marwa-*") ?: [];
rsort($files);
foreach (array_slice($files, $keep * 2) as $old) {
    unlink($old);
}
