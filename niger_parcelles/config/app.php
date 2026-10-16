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
    //  maxNativeZoom : zoom maximal réellement servi par le fournisseur ; au-delà,
    //  la carte agrandit les tuiles (zoom jusqu'à 'map_max_zoom').
    //  fallback      : fonds de secours essayés automatiquement si le fond
    //  principal ne répond pas (blocage, coupure, quota).
    'map_max_zoom' => 25,   // ≈ 4,5 mm par pixel à Niamey : vue de quelques mètres de large
    'tile_layers' => [
        'osm' => [
            'name' => 'OpenStreetMap (routier)',
            'url'  => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">Contributeurs OpenStreetMap</a>',
            'maxNativeZoom' => 19,
            'fallback' => ['osm_fr', 'carto', 'esri_street'],
        ],
        'satellite' => [
            'name' => 'Satellite (Esri World Imagery)',
            'url'  => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            'attribution' => 'Imagerie &copy; Esri, Maxar, Earthstar Geographics et contributeurs',
            'maxNativeZoom' => 19,
        ],
        'labels' => [
            'name' => 'Libellés et limites (Esri)',
            'url'  => 'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
            'attribution' => 'Libellés &copy; Esri',
            'maxNativeZoom' => 19,
            'overlay' => true,
        ],
    ],
    // Fonds de secours (même cartographie OpenStreetMap, autres serveurs)
    'tile_fallbacks' => [
        'osm_fr' => [
            'name' => 'OpenStreetMap France',
            'url'  => 'https://{s}.tile.openstreetmap.fr/osmfr/{z}/{x}/{y}.png',
            'subdomains' => 'abc',
            'attribution' => '&copy; Contributeurs OpenStreetMap, rendu OSM France',
            'maxNativeZoom' => 19,
        ],
        'carto' => [
            'name' => 'OpenStreetMap (CARTO Voyager)',
            'url'  => 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
            'subdomains' => 'abcd',
            'attribution' => '&copy; Contributeurs OpenStreetMap &copy; CARTO',
            'maxNativeZoom' => 20,
        ],
        'esri_street' => [
            'name' => 'Esri World Street Map',
            'url'  => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}',
            'attribution' => 'Carte &copy; Esri et contributeurs',
            'maxNativeZoom' => 19,
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
