<?php
declare(strict_types=1);
// Transmet au moteur Node la configuration dont il a besoin (lue depuis config/config.php), au format JSON.
require dirname(__DIR__) . '/src/bootstrap.php';

use Verifia\Config;

echo json_encode([
    'base_url'        => rtrim((string) Config::get('app.base_url'), '/'),
    'token'           => (string) Config::get('monitor.token'),
    'browser_path'    => (string) Config::get('monitor.browser_path', ''),
    'browser_channel' => (string) Config::get('monitor.browser_channel', ''),
    'headless'        => (bool) Config::get('monitor.headless', false),
    'storage_path'    => realpath(Config::storagePath()) ?: Config::storagePath(),
], JSON_UNESCAPED_SLASHES);
