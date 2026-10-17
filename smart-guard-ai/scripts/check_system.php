<?php
declare(strict_types=1);

/** Diagnostic de l'installation côté PHP. */
if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Services\AiClient;

$errors = 0;
$line = static function (bool $ok, string $label, string $detail = '') use (&$errors): void {
    $errors += $ok ? 0 : 1;
    echo '[' . ($ok ? 'OK' : '!!') . '] ' . $label . ($detail !== '' ? ' : ' . $detail : '') . PHP_EOL;
};
$line(version_compare(PHP_VERSION, '8.1', '>='), 'PHP', PHP_VERSION);
foreach (['pdo_mysql', 'gd', 'curl', 'fileinfo', 'mbstring', 'zip', 'zlib', 'openssl'] as $ext) {
    $line(extension_loaded($ext), "Extension {$ext}", extension_loaded($ext) ? '' : 'à activer dans WampServer (PHP > Extensions)');
}
echo '[' . (extension_loaded('sodium') ? 'OK' : '--') . '] Extension sodium' . (extension_loaded('sodium') ? '' : ' : facultative (OpenSSL AES-256-GCM utilisé à la place)') . PHP_EOL;
$line(defined('PASSWORD_ARGON2ID'), 'Argon2id disponible', defined('PASSWORD_ARGON2ID') ? '' : 'bcrypt sera utilisé');
$line(is_file(BASE_PATH . '/.env'), 'Fichier .env');
$keyOk = (bool) preg_match('/^[0-9a-f]{64}$/i', (string) Env::get('APP_KEY'));
$line($keyOk, 'APP_KEY', $keyOk ? '' : 'à générer : php scripts/generate_keys.php');
$line(strlen((string) Env::get('AI_SERVICE_KEY')) >= 24, 'AI_SERVICE_KEY');
foreach (['logs', 'product_images', 'snapshots', 'videos', 'reports', 'backups', 'cache'] as $d) {
    $line(is_writable(STORAGE_PATH . '/' . $d), "storage/{$d} accessible en écriture");
}
try {
    $v = Database::value('SELECT VERSION()');
    $line(true, 'Connexion MySQL/MariaDB', (string) $v);
    $mode = (string) Database::value('SELECT @@SESSION.sql_mode');
    echo '[--] Mode SQL : ' . ($mode ?: '(vide)') . PHP_EOL;
    $line((int) Database::value('SELECT COUNT(*) FROM roles') >= 5, 'Données de référence');
    $hasAdmin = (int) Database::value("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.code = 'admin'") > 0;
    $line($hasAdmin, 'Compte administrateur', $hasAdmin ? '' : 'à créer : php scripts/create_admin.php');
} catch (Throwable $e) {
    $line(false, 'Connexion MySQL/MariaDB', $e->getMessage());
}
$h = AiClient::health();
$line($h['ok'], 'Service IA', $h['ok'] ? $h['data']['detector'] . ' / ' . $h['data']['embedder'] . ' — reconnaissance ' . ($h['data']['recognition']['active'] ? 'active (v' . $h['data']['recognition']['version'] . ')' : 'non activée') : (string) $h['error']);
$free = @disk_free_space(STORAGE_PATH);
$line($free === false || $free > 5e9, 'Espace disque', $free ? human_bytes($free) . ' libres' : '?');
echo PHP_EOL . ($errors ? "{$errors} problème(s) détecté(s)." : 'Aucun problème détecté.') . PHP_EOL;
exit($errors ? 1 : 0);
