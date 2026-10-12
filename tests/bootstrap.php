<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/helpers.php';

date_default_timezone_set('UTC');

// Configuration de test : base dédiée et stockage temporaire.
$storage = sys_get_temp_dir() . '/verifia_test_storage_' . getmypid();
@mkdir($storage, 0775, true);
\Verifia\Config::set([
    'app' => ['name' => 'VERIFIA', 'base_url' => 'http://127.0.0.1', 'timezone' => 'UTC', 'debug' => true],
    'db' => [
        'dsn' => getenv('VERIFIA_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=verifia_test;charset=utf8mb4',
        'user' => getenv('VERIFIA_TEST_DB_USER') ?: 'root',
        'password' => getenv('VERIFIA_TEST_DB_PASS') ?: '',
    ],
    'monitor' => ['token' => 'test-token-0123456789', 'allowed_ips' => ['127.0.0.1', '::1']],
    'storage' => ['path' => $storage],
    'security' => ['allowed_origins' => ['http://127.0.0.1'], 'session_name' => 'VTEST', 'login_max_attempts' => 3, 'login_lock_minutes' => 15],
]);
register_shutdown_function(function () use ($storage) {
    if (is_dir($storage)) {
        exec('rm -rf ' . escapeshellarg($storage));
    }
});
