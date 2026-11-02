<?php
return [
    'session_name' => 'MARWASESSID',
    'session_idle_timeout' => (int) env('SESSION_IDLE_MINUTES', 30),   // minutes d'inactivité
    'session_lifetime' => (int) env('SESSION_LIFETIME_MINUTES', 720),   // durée de vie absolue
    'cookie_secure' => (bool) env('SESSION_SECURE_COOKIE', false),
    'trust_proxy' => (bool) env('TRUST_PROXY', false),
    'password_min_length' => 10,
    // Limitation des tentatives de connexion
    'login_max_attempts' => 5,          // par identifiant
    'login_max_attempts_ip' => 20,      // par adresse IP
    'login_decay_minutes' => 15,
];
