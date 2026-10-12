<?php
declare(strict_types=1);
/**
 * Conservation limitée des données :
 *   php bin/purge.php              supprime les publications NON signalées plus anciennes que retention_days
 * Les publications liées à une alerte ne sont jamais supprimées par ce script.
 */
require dirname(__DIR__) . '/src/bootstrap.php';

use Verifia\DataPurge;

$n = DataPurge::expired();
echo "$n publication(s) expirée(s) supprimée(s).\n";
