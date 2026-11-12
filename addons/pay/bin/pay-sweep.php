<?php
/**
 * Vérification planifiée des paiements et remboursements en attente (toutes les 1 à 5 minutes) :
 *   php addons/pay/bin/pay-sweep.php
 * Interroge les prestataires (serveur à serveur), fait expirer les demandes trop anciennes.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 3) . '/app/bootstrap.php';
$lock = fopen(STORAGE_PATH . '/pay-sweep.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
$r = Addons\Pay\PayEngine::sweep(100);
echo date('c') . " : {$r['transactions']} transaction(s) et {$r['refunds']} remboursement(s) vérifiés\n";
