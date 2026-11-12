<?php
/**
 * Traitement de la file d'attente fiscale (à planifier toutes les minutes ou toutes les 5 minutes) :
 *   php addons/fiscal/bin/fiscal-queue.php
 * Windows : Planificateur de tâches ; Linux : cron « * * * * * php /chemin/addons/fiscal/bin/fiscal-queue.php ».
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 3) . '/app/bootstrap.php';
$lock = fopen(STORAGE_PATH . '/fiscal-queue.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // une autre instance tourne déjà
}
$r = Addons\Fiscal\FiscalEngine::processQueue(100);
echo date('c') . " : {$r['processed']} traitée(s), {$r['certified']} certifiée(s)\n";
