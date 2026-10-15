<?php
declare(strict_types=1);

/**
 * Installation / mise à jour automatique en ligne de commande (utilisée par DEMARRER-MARWA.bat).
 *   php bin/install.php                 détecte MySQL/MariaDB, crée la base, applique les migrations,
 *                                       ajoute les comptes de démonstration (hors production), crée .env
 *   php bin/install.php --sans-demo     sans comptes de démonstration
 *   php bin/install.php --url=http://localhost/marwa
 */
require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Config;
use App\Support\Installer;

$opts = getopt('', ['sans-demo', 'url:']);
$envFile = getenv('MARWA_ENV_FILE') ?: MARWA_ROOT . '/.env';
$demo = !isset($opts['sans-demo']) && Config::get('app.env') !== 'production' && (!is_file($envFile) || Config::get('app.demo_mode'));

if (Installer::upToDate()) {
    echo "MARWA est déjà installé et à jour.\n";
    exit(0);
}
[$db, $errors] = Installer::findServer();
if ($db === null) {
    fwrite(STDERR, "Aucun serveur MySQL/MariaDB accessible :\n  - " . implode("\n  - ", $errors) . "\n"
        . "Démarrez WampServer (icône verte) ou renseignez DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD dans .env.\n");
    exit(1);
}
printf("Serveur de base de données : %s@%s:%d, base « %s »\n", $db['user'], $db['host'], $db['port'], $db['name']);
try {
    foreach (Installer::install($db, $demo, $opts['url'] ?? null) as $line) {
        echo $line, PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Échec de l\'installation : ' . Installer::cleanError($e->getMessage()) . "\n");
    exit(1);
}
echo "Installation terminée.\n";
