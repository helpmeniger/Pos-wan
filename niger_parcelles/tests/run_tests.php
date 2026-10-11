<?php
/**
 * Lance les tests unitaires et d'intégration.
 *
 *   php tests/run_tests.php
 *
 * Utilise une base de test dédiée (niger_parcelles_test par défaut) recréée à
 * chaque exécution. Connexion : config/database.php ou variables NP_DB_*.
 */
declare(strict_types=1);

define('NP_TESTS_LOADED', true);
require __DIR__ . '/bootstrap.php';

echo "Niger Parcelles - tests (base : " . (getenv('NP_DB_NAME') ?: '?') . ")\n";
reset_test_database();
require __DIR__ . '/unit_test.php';
require __DIR__ . '/integration_test.php';
exit(run_tests() > 0 ? 1 : 0);
