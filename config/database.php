<?php
use App\Core\Env;

return [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('DB_PORT', 3306),
    'name' => Env::get('DB_DATABASE', 'marwa'),
    'user' => Env::get('DB_USERNAME', 'root'),
    'password' => (string) Env::get('DB_PASSWORD', ''),
    // Base utilisée par la suite de tests (DÉTRUITE puis recréée à chaque exécution)
    'test_name' => Env::get('DB_TEST_DATABASE', 'marwa_test'),
];
