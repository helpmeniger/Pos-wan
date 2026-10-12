<?php
use App\Core\Env;

return [
    'cookie_secure' => (bool) Env::get('SESSION_SECURE_COOKIE', false), // true obligatoire en HTTPS / production
    'session_name' => 'MARWASESSID',
    'session_idle_minutes' => (int) Env::get('SESSION_IDLE_MINUTES', 120),
    'session_absolute_hours' => (int) Env::get('SESSION_ABSOLUTE_HOURS', 12),
    'login_max_attempts' => 5,
    'login_lock_minutes' => 15,
    'upload_max_bytes' => (int) Env::get('UPLOAD_MAX_BYTES', 4 * 1024 * 1024),
    'upload_image_max_px' => 1600,
    'allowed_upload_mimes' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ],
];
