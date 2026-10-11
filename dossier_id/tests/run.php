<?php
declare(strict_types=1);

/**
 * Exécution des tests : php tests/run.php [filtre]
 *   - tests unitaires et d'intégration (tests/unit/*.php) sur la base « *_test » ;
 *   - tests HTTP de bout en bout (tests/http/*.php) via le serveur intégré de PHP.
 * Utilise le serveur MySQL/MariaDB défini dans config/config.php (base dédiée _test).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';
foreach (glob(__DIR__ . '/unit/*.php') as $f) {
    require $f;
}
foreach (glob(__DIR__ . '/http/*.php') as $f) {
    require $f;
}

$pass = 0;
$failed = [];
$start = microtime(true);
foreach (T::$tests as [$name, $fn]) {
    if ($filter !== '' && stripos($name, $filter) === false) {
        continue;
    }
    $_SESSION = [];
    $_GET = $_POST = $_FILES = [];
    App\Core\Auth::reset();
    try {
        $fn();
        $pass++;
        echo "  \033[32m✔\033[0m $name\n";
    } catch (Throwable $e) {
        $failed[] = $name;
        echo "  \033[31m✘ $name\033[0m\n      " . $e::class . ' : ' . $e->getMessage() . "\n";
        if (!$e instanceof AssertionFailed) {
            echo '      ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
    }
}
printf("\n%d test(s) réussi(s), %d échec(s), %d assertion(s) — %.1f s\n", $pass, count($failed), T::$assertions, microtime(true) - $start);
exit($failed ? 1 : 0);
