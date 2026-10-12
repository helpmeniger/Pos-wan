<?php
declare(strict_types=1);
// Sert les images stockées (hors de la racine web) uniquement aux utilisateurs connectés.
require __DIR__ . '/_init.php';

use Verifia\Auth;
use Verifia\Config;

Auth::require('viewer');
$rel = (string) ($_GET['f'] ?? '');
if (!preg_match('#^(images|thumbs)/\d{4}/\d{2}/[A-Za-z0-9_]+\.(png|jpg|webp|gif)$#', $rel)) {
    http_response_code(404);
    exit;
}
$path = Config::storagePath($rel);
$real = realpath($path);
$base = realpath(Config::storagePath());
if (!$real || !$base || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real)) {
    http_response_code(404);
    exit;
}
$mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'][pathinfo($real, PATHINFO_EXTENSION)];
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Cache-Control: private, max-age=86400');
readfile($real);
