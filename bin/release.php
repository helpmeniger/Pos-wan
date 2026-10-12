<?php
/**
 * Prépare une version à distribuer : release.json (empreintes SHA-256 de chaque fichier,
 * vérifiées par « Mise à jour du logiciel ») et LICENCE.md (contrat).
 * Usage : php bin/release.php [dossier]   (par défaut : le dossier du logiciel)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

$root = rtrim($argv[1] ?? BASE_PATH, '/\\');
file_put_contents($root . '/LICENCE.md', App\Services\Contract::markdown());
$m = App\Services\UpdateService::buildManifest($root);
file_put_contents($root . '/release.json', json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo 'release.json : version ' . $m['version'] . ', ' . count($m['files']) . " fichiers.\n";
