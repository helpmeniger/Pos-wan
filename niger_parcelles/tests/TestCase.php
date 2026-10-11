<?php
/**
 * Mini-framework de tests sans dépendance (pas de Composer requis).
 * Usage : test('description', function () { assert_true(...); });
 */
declare(strict_types=1);

final class TestFailure extends Exception
{
}

$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$GLOBALS['__suite'] ?? '', $name, $fn];
}

function suite(string $name): void
{
    $GLOBALS['__suite'] = $name;
}

function fail(string $msg): void
{
    throw new TestFailure($msg);
}

function assert_true($cond, string $msg = 'Condition fausse'): void
{
    if ($cond !== true) {
        fail($msg);
    }
}

function assert_false($cond, string $msg = 'Condition vraie'): void
{
    if ($cond !== false) {
        fail($msg);
    }
}

function assert_same($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        fail(($msg ? "$msg : " : '') . 'attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true));
    }
}

function assert_near(float $expected, float $actual, float $tol, string $msg = ''): void
{
    if (abs($expected - $actual) > $tol) {
        fail(($msg ? "$msg : " : '') . "attendu $expected ± $tol, obtenu $actual");
    }
}

function assert_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (strpos($haystack, $needle) === false) {
        fail(($msg ? "$msg : " : '') . "« $needle » introuvable");
    }
}

function assert_not_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (strpos($haystack, $needle) !== false) {
        fail(($msg ? "$msg : " : '') . "« $needle » ne devrait pas être présent");
    }
}

/** Vérifie qu'une exception (de la classe donnée) est levée ; retourne son message. */
function assert_throws(callable $fn, string $class = Throwable::class, ?string $contains = null): string
{
    try {
        $fn();
    } catch (TestFailure $e) {
        throw $e;
    } catch (Throwable $e) {
        if (!($e instanceof $class)) {
            fail('Exception ' . get_class($e) . ' au lieu de ' . $class . ' : ' . $e->getMessage());
        }
        if ($contains !== null && stripos($e->getMessage(), $contains) === false) {
            fail("Message inattendu : « {$e->getMessage()} » (attendu : $contains)");
        }
        return $e->getMessage();
    }
    fail("Aucune exception $class levée");
    return '';
}

/** Exécute les tests enregistrés et retourne le nombre d'échecs. */
function run_tests(): int
{
    $passed = $failed = 0;
    $current = null;
    $start = microtime(true);
    foreach ($GLOBALS['__tests'] as [$suite, $name, $fn]) {
        if ($suite !== $current) {
            echo "\n\033[1m$suite\033[0m\n";
            $current = $suite;
        }
        try {
            $fn();
            $passed++;
            echo "  \033[32m✔\033[0m $name\n";
        } catch (Throwable $e) {
            $failed++;
            echo "  \033[31m✘ $name\033[0m\n      " . get_class($e) . ': ' . $e->getMessage() . "\n";
            if (!($e instanceof TestFailure)) {
                echo '      ' . $e->getFile() . ':' . $e->getLine() . "\n";
            }
        }
    }
    printf("\n%d test(s) réussi(s), %d échec(s) en %.2f s\n", $passed, $failed, microtime(true) - $start);
    $GLOBALS['__tests'] = [];
    return $failed;
}
