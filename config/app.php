<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name' => Env::get('APP_NAME', 'JOB NIGER'),
    'env' => Env::get('APP_ENV', 'production'),          // local | testing | production
    'debug' => (bool) Env::get('APP_DEBUG', false),        // JAMAIS true en production
    'url' => rtrim((string) Env::get('APP_URL', 'http://job-niger.local'), '/'),
    'base_path' => (string) Env::get('APP_BASE_PATH', ''), // ex : /job-niger/public si pas de VirtualHost
    'timezone' => 'Africa/Niamey',
    'locale' => 'fr',
    'currency' => 'XOF',
    'key' => (string) Env::get('APP_KEY', ''),             // 32 octets base64 : php bin/console key:generate
    'log_level' => (string) Env::get('LOG_LEVEL', 'info'),
    'demo_mode' => (bool) Env::get('APP_DEMO', false),
];
