<?php
declare(strict_types=1);

/**
 * Contrôle de sécurité avant et pendant la mise en ligne.
 *   php bin/security-check.php            (code de sortie 1 si un point critique est à corriger)
 *   php bin/security-check.php --url=https://marwa.example.ne   (teste aussi l'exposition des fichiers)
 */
require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;

$opts = getopt('', ['url:', 'json']);
$checks = App\Support\SecurityCheck::run($opts['url'] ?? null);
if (isset($opts['json'])) {
    echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
} else {
    foreach ($checks as $c) {
        $icon = ['ok' => "\033[32m OK \033[0m", 'warn' => "\033[33mÀ VOIR\033[0m", 'fail' => "\033[31mCRITIQUE\033[0m"][$c['status']];
        printf("[%s] %s%s\n", $icon, $c['label'], $c['detail'] ? ' — ' . $c['detail'] : '');
    }
}
exit(count(array_filter($checks, fn ($c) => $c['status'] === 'fail')) ? 1 : 0);
