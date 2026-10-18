<?php
use App\Core\Env;

return [
    'name' => 'POS Marwa',
    'version' => '2.3.0',
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => (bool) Env::get('APP_DEBUG', false),
    // Laisser vide pour une détection automatique du sous-dossier (ex. /pos-marwa/public)
    'base_url' => rtrim((string) Env::get('APP_BASE_URL', ''), '/'),
    'timezone' => Env::get('APP_TIMEZONE', 'Africa/Niamey'),
    'currency' => 'FCFA',
    'currency_code' => 'XOF',
    'session' => [
        'name' => 'POSWANSESSID',
        'idle_timeout' => (int) Env::get('SESSION_IDLE_MINUTES', 30) * 60,
        'absolute_timeout' => (int) Env::get('SESSION_MAX_HOURS', 12) * 3600,
        'secure' => (bool) Env::get('SESSION_SECURE', false), // true si HTTPS
    ],
    'security' => [
        'max_attempts_user' => 5,      // échecs avant verrouillage du compte
        'lockout_minutes' => 15,
        'max_attempts_ip' => 20,       // échecs par IP sur 15 minutes
        'password_min_length' => 10,
    ],
    'uploads' => [
        'max_image_bytes' => 2 * 1024 * 1024,
        'max_import_bytes' => 5 * 1024 * 1024,
    ],
];
