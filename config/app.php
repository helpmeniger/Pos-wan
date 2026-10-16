<?php
use App\Core\Env;

return [
    'name' => 'MARWA',
    'env' => Env::get('APP_ENV', 'local'),            // local | production | testing
    'debug' => (bool) Env::get('APP_DEBUG', false),
    'url' => rtrim((string) Env::get('APP_URL', 'http://marwa.local'), '/'),
    'timezone' => 'Africa/Niamey',
    'locale' => 'fr_NE',
    'currency' => 'XOF',
    'country_code' => '227',
    'demo_mode' => (bool) Env::get('APP_DEMO', false), // affiche le bandeau « démonstration »
    'trusted_proxies' => array_filter(explode(',', (string) Env::get('TRUSTED_PROXIES', ''))),
];
