<?php
/**
 * Sauvegarde de la base. À planifier avec le Planificateur de tâches Windows, ex. chaque nuit :
 *   C:\wamp64\bin\php\php8.x\php.exe C:\wamp64\www\marwa-connect\cli\backup.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
require dirname(__DIR__) . '/bootstrap/app.php';

use App\Services\BackupService;

$trigger = in_array('--scheduled', $argv, true) ? 'scheduled' : 'manual';
$result = BackupService::create($trigger);
printf("Sauvegarde créée : %s (%s, %s)\n", basename($result['file']), bytes_human($result['size']), $result['method']);
