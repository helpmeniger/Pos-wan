<?php
declare(strict_types=1);

/**
 * Lanceur de la suite de tests MARWA.
 *   php tests/run.php                 (toute la suite)
 *   php tests/run.php --filter=Ledger (classes ou méthodes contenant « Ledger »)
 *
 * ATTENTION : la base DB_TEST_DATABASE (marwa_test par défaut) est SUPPRIMÉE puis recréée.
 */
require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Config;
use App\Support\SqlRunner;

$opts = getopt('', ['filter:']);
$testDb = (string) Config::get('database.test_name', 'marwa_test');
if ($testDb === (string) Config::get('database.name')) {
    fwrite(STDERR, "Refus : la base de test doit être différente de la base principale.\n");
    exit(1);
}
Config::set('app.env', 'testing');
Config::set('app.debug', true);
Config::set('database.name', $testDb);
Config::set('services.routing.driver', 'estimate');   // pas d'appel réseau pendant les tests
Config::set('services.geocoding.driver', 'none');
Config::set('services.mail.driver', 'log');
Config::set('services.sms.driver', 'log');
Config::set('services.payments.demo_enabled', true);
Config::set('services.payments.demo_secret', 'test-secret-0123456789abcdef');

$start = microtime(true);
echo "Préparation de la base de test « $testDb »…\n";
SqlRunner::migrate($testDb, true);

$files = array_merge(glob(__DIR__ . '/Unit/*Test.php') ?: [], glob(__DIR__ . '/Feature/*Test.php') ?: []);
$passed = $failed = $assertions = 0;
$failures = [];
foreach ($files as $file) {
    require_once $file;
    $class = 'Tests\\' . basename(dirname($file)) . '\\' . basename($file, '.php');
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        $name = basename($file, '.php') . '::' . $method;
        if (isset($opts['filter']) && !str_contains($name, $opts['filter'])) {
            continue;
        }
        $t = new $class();
        App\Core\Settings::flush();
        App\Services\LedgerService::flushCache();
        App\Core\Session::reset();
        try {
            $t->setUp();
            $t->$method();
            $passed++;
            echo "  \033[32m✔\033[0m $name\n";
        } catch (Throwable $e) {
            $failed++;
            $failures[] = "$name\n      " . $e::class . ': ' . $e->getMessage() . "\n      " . basename($e->getFile()) . ':' . $e->getLine();
            echo "  \033[31m✘\033[0m $name\n";
        }
        $assertions += $t->assertions;
    }
}
echo "\n";
foreach ($failures as $f) {
    echo "\033[31mÉCHEC\033[0m $f\n\n";
}
printf("%d test(s) réussi(s), %d échec(s), %d assertion(s) — %.1f s\n", $passed, $failed, $assertions, microtime(true) - $start);
exit($failed > 0 ? 1 : 0);
