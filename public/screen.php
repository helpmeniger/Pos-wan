<?php
declare(strict_types=1);
// Aperçu (capture basse résolution) de la page d'une session, transmis par le moteur. Écrasé à chaque mise à jour.
require __DIR__ . '/_init.php';

use Verifia\Auth;
use Verifia\Config;

Auth::require('viewer');
session_write_close();
$file = Config::storagePath('run/screens/session_' . (int) ($_GET['id'] ?? 0) . '.jpg');
if (!is_file($file)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Cache-Control: no-store');
readfile($file);
