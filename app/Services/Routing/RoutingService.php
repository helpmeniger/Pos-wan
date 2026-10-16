<?php
declare(strict_types=1);

namespace App\Services\Routing;

use App\Core\Config;
use App\Core\Logger;

/** Façade : fournisseur configuré, repli automatique sur l'estimation, cache fichier court. */
final class RoutingService
{
    private static ?RoutingProvider $override = null;

    public static function fake(?RoutingProvider $provider): void
    {
        self::$override = $provider;
    }

    public static function route(float $fromLat, float $fromLng, float $toLat, float $toLng, string $profile = 'car'): RouteResult
    {
        $cfg = Config::get('services.routing');
        $estimate = new EstimateRouter((float) $cfg['road_factor'], (array) $cfg['avg_speed_kmh']);
        if (self::$override) {
            return self::$override->route($fromLat, $fromLng, $toLat, $toLng, $profile);
        }
        if (($cfg['driver'] ?? 'estimate') !== 'osrm') {
            return $estimate->route($fromLat, $fromLng, $toLat, $toLng, $profile);
        }
        $key = md5(sprintf('%.4f,%.4f,%.4f,%.4f,%s', $fromLat, $fromLng, $toLat, $toLng, $profile));
        $cacheFile = MARWA_ROOT . '/storage/cache/route_' . $key . '.json';
        if (is_file($cacheFile) && filemtime($cacheFile) > time() - 600) {
            $d = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($d)) {
                return new RouteResult($d['distance_m'], $d['duration_s'], $d['geometry'], $d['source'], $d['approximate']);
            }
        }
        // Coupe-circuit : après un échec, on n'interroge plus OSRM pendant 60 s.
        $breaker = MARWA_ROOT . '/storage/cache/osrm_down';
        if (is_file($breaker) && filemtime($breaker) > time() - 60) {
            return $estimate->route($fromLat, $fromLng, $toLat, $toLng, $profile);
        }
        try {
            $result = (new OsrmRouter((string) $cfg['osrm_url'], (int) $cfg['timeout']))->route($fromLat, $fromLng, $toLat, $toLng, $profile);
            @file_put_contents($cacheFile, json_encode($result->toArray()));
            return $result;
        } catch (\Throwable $e) {
            @touch($breaker);
            Logger::warning('Routage OSRM indisponible, estimation utilisée', ['error' => $e->getMessage()]);
            return $estimate->route($fromLat, $fromLng, $toLat, $toLng, $profile);
        }
    }

    /**
     * Itinéraire de navigation avec étapes (sans cache : la position change sans cesse).
     * Repli automatique sur l'estimation (ligne directe, sans étapes) si OSRM est indisponible.
     */
    public static function navigate(float $fromLat, float $fromLng, float $toLat, float $toLng, string $profile = 'car'): RouteResult
    {
        $cfg = Config::get('services.routing');
        $estimate = new EstimateRouter((float) $cfg['road_factor'], (array) $cfg['avg_speed_kmh']);
        if (self::$override) {
            return self::$override->route($fromLat, $fromLng, $toLat, $toLng, $profile);
        }
        $breaker = MARWA_ROOT . '/storage/cache/osrm_down';
        if (($cfg['driver'] ?? 'estimate') !== 'osrm' || (is_file($breaker) && filemtime($breaker) > time() - 60)) {
            return $estimate->route($fromLat, $fromLng, $toLat, $toLng, $profile);
        }
        try {
            return (new OsrmRouter((string) $cfg['osrm_url'], (int) $cfg['timeout']))->route($fromLat, $fromLng, $toLat, $toLng, $profile, true);
        } catch (\Throwable $e) {
            @touch($breaker);
            Logger::warning('Navigation OSRM indisponible, ligne directe utilisée', ['error' => $e->getMessage()]);
            return $estimate->route($fromLat, $fromLng, $toLat, $toLng, $profile);
        }
    }

    public static function profileFor(?string $vehicleCategory): string
    {
        return match ($vehicleCategory) {
            'moto', 'moto_taxi' => 'moto',
            'bicycle' => 'bicycle',
            default => 'car',
        };
    }
}
