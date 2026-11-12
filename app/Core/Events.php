<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Événements internes minimalistes, pour que les modules (addons) réagissent au cœur
 * sans le modifier. Les modules actifs déclarent leurs écouteurs dans addons/<code>/boot.php.
 * Un écouteur en erreur est journalisé et n'interrompt JAMAIS l'opération du cœur (vente, impression…).
 */
final class Events
{
    /** @var array<string, callable[]> */
    private static array $listeners = [];
    private static bool $booted = false;

    public static function on(string $event, callable $fn): void
    {
        self::$listeners[$event][] = $fn;
    }

    /** Réinitialisation (tests, changement d'état des modules). */
    public static function reset(): void
    {
        self::$listeners = [];
        self::$booted = false;
    }

    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        try {
            foreach (array_keys(Addons::active()) as $code) {
                $f = Addons::dir() . '/' . $code . '/boot.php';
                if (is_file($f)) {
                    (static function (string $__file): void { require $__file; })($f);
                }
            }
        } catch (\Throwable $e) {
            Logger::error('events.boot', ['error' => $e->getMessage()]);
        }
    }

    /** @return mixed[] réponses non nulles des écouteurs */
    private static function each(string $event, array $payload): array
    {
        self::boot();
        $out = [];
        foreach (self::$listeners[$event] ?? [] as $fn) {
            try {
                $r = $fn($payload);
                if ($r !== null) {
                    $out[] = $r;
                }
            } catch (\Throwable $e) {
                Logger::error('event.' . $event, ['error' => $e->getMessage()]);
            }
        }
        return $out;
    }

    public static function fire(string $event, array $payload = []): void
    {
        self::each($event, $payload);
    }

    /** Fusionne les tableaux renvoyés par les écouteurs. */
    public static function collect(string $event, array $payload = []): array
    {
        $merged = [];
        foreach (self::each($event, $payload) as $r) {
            if (is_array($r)) {
                $merged = array_merge($merged, $r);
            }
        }
        return $merged;
    }

    /** Premier refus (message) renvoyé par un écouteur, sinon null. */
    public static function ask(string $event, array $payload = []): ?string
    {
        foreach (self::each($event, $payload) as $r) {
            if (is_string($r) && $r !== '') {
                return $r;
            }
        }
        return null;
    }
}
