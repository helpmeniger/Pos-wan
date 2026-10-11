<?php
/**
 * API Statistiques : GET ?date_from=&date_to=&area_id=  -> indicateurs et séries.
 */
declare(strict_types=1);

define('NP_API', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

require_login();
require_permission('parcel.view');

json_response(['ok' => true] + StatsService::compute($_GET));
