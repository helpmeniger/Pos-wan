<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Container;
use App\Core\Database;
use App\Core\Env;
use PHPUnit\Framework\TestCase as BaseTestCase;

/** Base des tests qui ont besoin de l'application et de la base de test. */
abstract class TestCase extends BaseTestCase
{
    private static bool $migrated = false;
    protected static Container $app;

    public static function setUpBeforeClass(): void
    {
        self::$app = require dirname(__DIR__) . '/bootstrap/app.php';
        $database = (string) Env::get('DB_DATABASE');
        if (!str_contains($database, 'test')) {
            self::fail("Refus d'exécuter les tests sur la base « {$database} » : utilisez une base dont le nom contient « test ».");
        }
        if (!self::$migrated) {
            ob_start();
            $code = (new \App\Console\Kernel(self::$app))->run(['console', 'migrate', '--fresh']);
            $out = (string) ob_get_clean();
            if ($code !== 0) {
                self::fail("Migration de la base de test impossible :\n" . $out);
            }
            self::$migrated = true;
        }
    }

    protected function setUp(): void
    {
        $_SESSION = [];
        // Conteneur neuf par test (caches de services remis à zéro), même base de test
        self::$app = require dirname(__DIR__) . '/bootstrap/app.php';
    }

    protected function db(): Database
    {
        return self::$app->get(Database::class);
    }

    /** @template T of object @param class-string<T> $class @return T */
    protected function service(string $class): object
    {
        return self::$app->get($class);
    }

    /** Numéro nigérien unique pour chaque test. */
    protected static function uniquePhone(): string
    {
        static $n = 0;
        $n++;
        return '+2279' . str_pad((string) ((getmypid() * 37 + $n * 7919 + random_int(0, 999)) % 10000000), 7, '0', STR_PAD_LEFT);
    }
}
