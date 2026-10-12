<?php
use App\Core\Env;

/*
 * Fournisseurs externes centralisés et interchangeables.
 * Aucun fournisseur payant n'est supposé disponible : par défaut, MARWA fonctionne
 * en mode « log/démo » et l'indique clairement dans l'interface.
 */
return [
    'maps' => [
        // Tuiles raster. tile.openstreetmap.org : usage modéré uniquement (politique OSMF).
        // En production, utiliser un fournisseur de tuiles commercial ou un serveur dédié.
        'tile_url' => Env::get('MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'tile_host' => Env::get('MAP_TILE_HOST', 'https://tile.openstreetmap.org'),
        'attribution' => '© contributeurs OpenStreetMap',
        'default_lat' => 13.5127,
        'default_lng' => 2.1126,
        'default_zoom' => 13,
    ],
    'routing' => [
        // osrm | estimate. « estimate » = distance à vol d'oiseau × facteur routier (approximatif).
        'driver' => Env::get('ROUTING_DRIVER', 'osrm'),
        'osrm_url' => Env::get('OSRM_URL', 'https://router.project-osrm.org'), // serveur public de démonstration, sans garantie
        'timeout' => 4,
        'road_factor' => 1.35,
        'avg_speed_kmh' => ['car' => 24, 'moto' => 28, 'bicycle' => 14],
    ],
    'geocoding' => [
        'driver' => Env::get('GEOCODING_DRIVER', 'nominatim'), // nominatim | none
        'nominatim_url' => Env::get('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent' => Env::get('GEOCODING_USER_AGENT', 'MARWA/1.0 (contact: a-configurer@example.invalid)'),
        'country_codes' => 'ne',
    ],
    'sms' => [
        // log = écrit le SMS dans storage/logs/outbox.log (aucun envoi réel). Les SMS réels sont payants.
        'driver' => Env::get('SMS_DRIVER', 'log'),
        'sender' => Env::get('SMS_SENDER', 'MARWA'),
    ],
    'mail' => [
        // log | smtp | mail (fonction mail() de PHP)
        'driver' => Env::get('MAIL_DRIVER', 'log'),
        'from' => Env::get('MAIL_FROM', 'no-reply@marwa.local'),
        'from_name' => 'MARWA',
        'smtp_host' => Env::get('SMTP_HOST', ''),
        'smtp_port' => (int) Env::get('SMTP_PORT', 587),
        'smtp_user' => Env::get('SMTP_USER', ''),
        'smtp_password' => (string) Env::get('SMTP_PASSWORD', ''),
        'smtp_encryption' => Env::get('SMTP_ENCRYPTION', 'tls'), // tls | ssl | none
    ],
    'whatsapp' => [
        'driver' => Env::get('WHATSAPP_DRIVER', 'none'), // aucune intégration officielle configurée
    ],
    'payments' => [
        // Passerelle de démonstration : simule un paiement électronique avec webhook signé HMAC.
        // Désactivée automatiquement en production sauf PAYMENT_DEMO_ENABLED=true.
        'demo_enabled' => (bool) Env::get('PAYMENT_DEMO_ENABLED', Env::get('APP_ENV', 'local') !== 'production'),
        'demo_secret' => (string) Env::get('PAYMENT_DEMO_SECRET', ''),
        // Fournisseurs réels (Mobile Money, carte) : à intégrer via App\Services\Payment\PaymentGateway
        // après contractualisation. Aucune API n'est simulée comme réelle.
        'gateways' => [],
    ],
];
