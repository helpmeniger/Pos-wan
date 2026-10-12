<?php
declare(strict_types=1);

namespace App\Services\Routing;

use RuntimeException;

/**
 * Itinéraires routiers via un serveur OSRM (API HTTP « route v1 »).
 * Le serveur public router.project-osrm.org est un service de démonstration
 * sans garantie de disponibilité : héberger son propre OSRM (extrait Niger de
 * Geofabrik) est recommandé en production.
 */
final class OsrmRouter implements RoutingProvider
{
    public function __construct(private string $baseUrl, private int $timeout = 4)
    {
    }

    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng, string $profile = 'car'): RouteResult
    {
        $osrmProfile = $profile === 'bicycle' ? 'bike' : 'driving';
        $url = sprintf('%s/route/v1/%s/%F,%F;%F,%F?overview=simplified&geometries=geojson&alternatives=false',
            rtrim($this->baseUrl, '/'), $osrmProfile, $fromLng, $fromLat, $toLng, $toLat);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_USERAGENT => 'MARWA/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status !== 200) {
            throw new RuntimeException('OSRM indisponible : ' . ($err ?: 'HTTP ' . $status));
        }
        $data = json_decode((string) $body, true);
        $r = $data['routes'][0] ?? null;
        if (($data['code'] ?? '') !== 'Ok' || !$r) {
            throw new RuntimeException('OSRM : aucun itinéraire trouvé.');
        }
        $geometry = array_map(static fn ($c) => [round((float) $c[1], 6), round((float) $c[0], 6)], $r['geometry']['coordinates'] ?? []);
        // Les durées OSRM supposent un trafic fluide : majoration prudente de 25 % pour Niamey.
        $duration = (int) round(((float) $r['duration']) * 1.25);
        if ($profile === 'moto') {
            $duration = (int) round($duration * 0.85);
        }
        return new RouteResult((int) round((float) $r['distance']), $duration, $geometry, 'osrm', false);
    }
}
