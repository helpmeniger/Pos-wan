<?php
// Paramètres par défaut du connecteur RouterOS API (utilisés à partir de la phase 3).
return [
    'default_api_port' => 8728,
    'default_api_ssl_port' => 8729,
    'connect_timeout' => (int) env('MIKROTIK_TIMEOUT', 5),
    'read_timeout' => (int) env('MIKROTIK_READ_TIMEOUT', 10),
    'retry_attempts' => 3,
    'sync_interval_minutes' => 5,
    // Vérification du certificat en API-SSL (désactivée par défaut : certificats auto-signés RouterOS).
    'ssl_verify_peer' => (bool) env('MIKROTIK_SSL_VERIFY', false),
];
