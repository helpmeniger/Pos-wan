<?php
declare(strict_types=1);

/**
 * Mini-cadre de tests autonome (aucune dépendance, fonctionne hors ligne).
 * Chaque test est une fonction anonyme enregistrée via test().
 */
final class T
{
    public static array $tests = [];
    public static int $assertions = 0;
}

final class AssertionFailed extends RuntimeException
{
}

function test(string $name, callable $fn): void
{
    T::$tests[] = [$name, $fn];
}

function assert_true(mixed $cond, string $msg = 'condition fausse'): void
{
    T::$assertions++;
    if ($cond !== true) {
        throw new AssertionFailed($msg);
    }
}

function assert_false(mixed $cond, string $msg = 'condition vraie'): void
{
    assert_true($cond === false, $msg);
}

function assert_same(mixed $expected, mixed $actual, string $msg = ''): void
{
    T::$assertions++;
    if ($expected !== $actual) {
        throw new AssertionFailed(trim($msg . ' attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true)));
    }
}

function assert_contains(string $needle, string $haystack, string $msg = ''): void
{
    T::$assertions++;
    if (!str_contains($haystack, $needle)) {
        throw new AssertionFailed(trim($msg . " « $needle » absent"));
    }
}

function assert_not_contains(string $needle, string $haystack, string $msg = ''): void
{
    T::$assertions++;
    if (str_contains($haystack, $needle)) {
        throw new AssertionFailed(trim($msg . " « $needle » présent"));
    }
}

/** @param class-string<Throwable> $class */
function assert_throws(string $class, callable $fn, ?string $messagePart = null): Throwable
{
    T::$assertions++;
    try {
        $fn();
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            throw new AssertionFailed("exception $class attendue, " . $e::class . ' levée : ' . $e->getMessage());
        }
        $text = $e->getMessage() . ' ' . (property_exists($e, 'errors') ? implode(' ', $e->errors) : '');
        if ($messagePart !== null && !str_contains($text, $messagePart)) {
            throw new AssertionFailed("message attendu contenant « $messagePart », obtenu : $text");
        }
        return $e;
    }
    throw new AssertionFailed("exception $class attendue, aucune levée");
}
