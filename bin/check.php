<?php
declare(strict_types=1);
// php bin/check.php — affiche l'état des prérequis ; code de sortie 1 en cas d'erreur.
require __DIR__ . '/check_lib.php';
[$ok, $report] = verifia_check_requirements(dirname(__DIR__));
echo implode(PHP_EOL, $report), PHP_EOL;
exit($ok ? 0 : 1);
