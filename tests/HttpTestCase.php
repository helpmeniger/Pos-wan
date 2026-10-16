<?php

declare(strict_types=1);

namespace Tests;

use Tests\Support\HttpClient;

/** Démarre le serveur PHP intégré (APP_ENV=testing → base de test) pour la classe de tests. */
abstract class HttpTestCase extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    protected static string $base = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $port = random_int(20000, 40000);
        $root = dirname(__DIR__);
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => "http://127.0.0.1:{$port}",
            // Le serveur de test joue le rôle d'un proxy inverse de confiance : X-Forwarded-For simule des clients distincts
            'TRUSTED_PROXIES' => '127.0.0.1']);
        self::$server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root . '/public', $root . '/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
            $pipes,
            $root,
            $env
        );
        self::$base = "http://127.0.0.1:{$port}";
        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen('127.0.0.1', $port);
            if ($fp) {
                fclose($fp);
                return;
            }
            usleep(100000);
        }
        self::fail('Le serveur de test ne démarre pas');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::$server = null;
    }

    protected function client(): HttpClient
    {
        return new HttpClient(self::$base);
    }

    /** Inscrit un compte via le formulaire et renvoie le client connecté. @return array{0: HttpClient, 1: string} */
    protected function registeredClient(string $type = 'WORKER', array $extra = []): array
    {
        $c = $this->client();
        $phone = self::uniquePhone();
        $res = $c->submit('/inscription', '/inscription', array_merge([
            'account_type' => $type, 'first_name' => 'Fati', 'last_name' => 'Ousmane', 'phone' => $phone,
            'city_id' => '1', 'birth_date' => '2001-06-01', 'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1',
            'accept_terms' => '1', 'accept_privacy' => '1',
        ], $extra), ['X-Forwarded-For' => '10.1.' . random_int(0, 255) . '.' . random_int(0, 255)]);
        $this->assertSame(303, $res['status'], 'Inscription : ' . substr(strip_tags($c->lastBody), 0, 300));
        return [$c, $phone];
    }
}
