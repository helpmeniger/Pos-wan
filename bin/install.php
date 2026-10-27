<?php
/**
 * Installation en ligne de commande (alternative à l'assistant web /install).
 * Usage : php bin/install.php --company="Ma Boutique" --type=alimentation --user=admin --name="Administrateur" [--demo]
 * Le mot de passe administrateur est demandé de façon interactive (ou via POSWAN_ADMIN_PASSWORD).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\InstallService;

$o = getopt('', ['company:', 'type:', 'user:', 'name:', 'store:', 'demo']);
$pwd = getenv('POSWAN_ADMIN_PASSWORD') ?: '';
if ($pwd === '') {
    echo 'Mot de passe administrateur : ';
    $pwd = trim((string) fgets(STDIN));
}
if (!is_file(BASE_PATH . '/.env')) {
    fwrite(STDERR, "Fichier .env absent : copiez .env.example en .env et renseignez la base de données.\n");
    exit(1);
}
try {
    $result = InstallService::install([
        'company' => $o['company'] ?? 'Mon commerce',
        'business_type' => $o['type'] ?? 'alimentation',
        'admin_username' => $o['user'] ?? 'admin',
        'admin_name' => $o['name'] ?? 'Administrateur',
        'admin_password' => $pwd,
        'store_name' => $o['store'] ?? '',
        'demo' => isset($o['demo']),
    ]);
} catch (\PDOException $e) {
    fwrite(STDERR, InstallService::explainDbError($e, (string) config('database.database')) . "\n");
    exit(1);
} catch (\App\Core\BusinessException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo $result === 'existing'
    ? "Base POS Marwa existante conservée (sauvegarde dans storage/backups), structure mise à jour.\n"
    : "POS Marwa installé avec succès.\n";
