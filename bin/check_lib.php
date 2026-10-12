<?php
declare(strict_types=1);

/** Vérification des prérequis PHP (utilisée par install.php, check.php et start.bat). */
function verifia_check_requirements(string $root, bool $withDb = true): array
{
    $ok = true;
    $r = [];
    $line = function (bool $pass, string $label, string $hint = '') use (&$ok, &$r) {
        $r[] = ($pass ? '[OK]     ' : '[ERREUR] ') . $label . ($pass || $hint === '' ? '' : "  → $hint");
        $ok = $ok && $pass;
    };
    $line(PHP_VERSION_ID >= 80200, 'PHP >= 8.2 (actuel : ' . PHP_VERSION . ')', 'Dans WampServer : clic gauche > PHP > Version > 8.2+ ; et utilisez le même php.exe en ligne de commande.');
    foreach (['pdo_mysql' => 'PDO MySQL', 'gd' => 'GD (empreintes d\'images)', 'mbstring' => 'mbstring', 'json' => 'JSON', 'fileinfo' => 'fileinfo'] as $ext => $label) {
        $line(extension_loaded($ext), "Extension $label", "Activez extension=$ext dans le php.ini utilisé par la ligne de commande (wamp64\\bin\\php\\php8.x\\php.ini).");
    }
    $r[] = (extension_loaded('exif') ? '[OK]     ' : '[INFO]   ') . 'Extension exif ' . (extension_loaded('exif') ? '' : '(facultative : métadonnées des images importées)');
    $r[] = (extension_loaded('intl') ? '[OK]     ' : '[INFO]   ') . 'Extension intl ' . (extension_loaded('intl') ? '' : '(facultative : meilleure normalisation des accents)');
    $line(is_file($root . '/vendor/autoload.php'), 'Dépendances Composer installées', 'Exécutez « composer install » dans le dossier verifia (Dompdf est nécessaire aux rapports PDF).');
    $storage = $root . '/storage';
    $line(is_dir($storage) && is_writable($storage), 'Dossier storage/ accessible en écriture', "Vérifiez les droits de $storage.");
    if ($withDb) {
        $cfgFile = getenv('VERIFIA_CONFIG') ?: $root . '/config/config.php';
        if (!is_file($cfgFile)) {
            $line(false, 'config/config.php présent', 'Lancez scripts\\windows\\install.bat.');
        } else {
            $cfg = require $cfgFile;
            $line(($cfg['monitor']['token'] ?? 'CHANGE-ME') !== 'CHANGE-ME', 'Jeton du moteur de surveillance configuré', 'Relancez install.bat ou définissez monitor.token.');
            try {
                $pdo = new PDO($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
                $line(true, 'Connexion MySQL/MariaDB');
                $n = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('alerts','monitoring_sessions','analysis_jobs')")->fetchColumn();
                $line($n === 3, 'Schéma de base de données installé', 'Lancez scripts\\windows\\install.bat.');
            } catch (PDOException $e) {
                $line(false, 'Connexion MySQL/MariaDB', 'WampServer est-il démarré (icône verte) ? ' . $e->getMessage());
            }
        }
    }
    return [$ok, $r];
}
