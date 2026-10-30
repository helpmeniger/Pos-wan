<?php

declare(strict_types=1);

use App\Core\Env;

$root = dirname(__DIR__);

return [
    'session' => [
        'cookie' => 'JNSESSID',
        'path' => $root . '/storage/sessions',
        'idle_minutes' => (int) Env::get('SESSION_IDLE_MINUTES', 120),
        'absolute_hours' => (int) Env::get('SESSION_ABSOLUTE_HOURS', 12),
        'secure' => (bool) Env::get('SESSION_SECURE_COOKIE', false), // forcé à true derrière HTTPS
    ],
    // Limites de débit : [tentatives, fenêtre en secondes]
    'rate_limits' => [
        'login_ip' => [20, 900],
        'register_ip' => [10, 3600],
        'password_reset' => [5, 3600],
        'otp_send' => [5, 3600],
        'contact' => [5, 3600],
        'upload' => [20, 3600],
    ],
    'password_reset_minutes' => 30,
    'otp_minutes' => 10,
    'otp_max_attempts' => 5,
    'hsts' => (bool) Env::get('SECURITY_HSTS', false),
];
