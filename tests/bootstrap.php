<?php

declare(strict_types=1);

/*
 * Les tests d'intégration utilisent une base DÉDIÉE (DB_TEST_DATABASE, « job_niger_test » par défaut),
 * entièrement recréée au début de la suite. Elle ne doit jamais être la base réelle.
 */
putenv('APP_ENV=testing');
$_SERVER['APP_ENV'] = 'testing';

require dirname(__DIR__) . '/vendor/autoload.php';
