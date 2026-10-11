<?php
/**
 * Configuration générale de Niger Parcelles.
 *
 * Ce fichier ne contient aucun secret. Pour surcharger une valeur localement
 * (sans la versionner), créez config/app.local.php qui retourne un tableau
 * partiel : ses clés remplacent celles d'ici.
 */
declare(strict_types=1);

$config = [
    'app_name'    => 'Niger Parcelles',
    'version'     => '1.0.0',

    // 'development' ou 'production'. En production les erreurs ne sont jamais affichées.
    'environment' => 'development',
    // Afficher le détail des erreurs PHP (JAMAIS les erreurs SQL à l'utilisateur).
    'debug'       => false,

    'timezone'    => 'Africa/Niamey',
    'locale'      => 'fr_FR',

    // URL de base. Laisser null pour la détection automatique
    // (ex. http://localhost/niger_parcelles/).
    'base_url'    => null,

    // Répertoires privés (protégés par .htaccess). Peuvent être déplacés hors
    // de C:\wamp64\www pour plus de sécurité : indiquer alors un chemin absolu.
    'storage_path'   => dirname(__DIR__) . '/storage',

    // Session
    'session_name'     => 'NPSESSID',
    'session_lifetime' => 7200, // secondes d'inactivité avant déconnexion

    // Anti force brute
    'login_max_attempts_user' => 5,   // échecs max par identifiant
    'login_max_attempts_ip'   => 50,  // échecs max par adresse IP (bureau partageant une IP)
    'login_lockout_minutes'   => 15,

    // Documents : extensions et types MIME autorisés
    'upload_allowed' => [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
    ],

    // Import géographique : taille max (Mo)
    'import_max_mb' => 20,

    // Fonds de carte (affichés côté navigateur, nécessitent Internet).
    // Respectez les conditions d'utilisation de chaque fournisseur.
    'tile_layers' => [
        'osm' => [
            'name' => 'OpenStreetMap (routier)',
            'url'  => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'attribution' => '&copy; Contributeurs OpenStreetMap',
            'maxZoom' => 19,
        ],
        'satellite' => [
            'name' => 'Satellite (Esri World Imagery)',
            'url'  => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            'attribution' => 'Imagerie &copy; Esri, Maxar, Earthstar Geographics et contributeurs',
            'maxZoom' => 19,
        ],
        'labels' => [
            'name' => 'Libellés et limites (Esri)',
            'url'  => 'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
            'attribution' => 'Libellés &copy; Esri',
            'maxZoom' => 19,
            'overlay' => true,
        ],
    ],

    // Recherche de localités (géocodage OSM Nominatim, 1 requête/s max).
    'geocoder_url' => 'https://nominatim.openstreetmap.org/search',
];

$local = __DIR__ . '/app.local.php';
if (is_file($local)) {
    $config = array_replace($config, (array) require $local);
}

return $config;
