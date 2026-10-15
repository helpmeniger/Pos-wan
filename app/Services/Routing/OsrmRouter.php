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

    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng, string $profile = 'car', bool $withSteps = false): RouteResult
    {
        $osrmProfile = $profile === 'bicycle' ? 'bike' : 'driving';
        $url = sprintf('%s/route/v1/%s/%F,%F;%F,%F?overview=%s&geometries=geojson&alternatives=false%s',
            rtrim($this->baseUrl, '/'), $osrmProfile, $fromLng, $fromLat, $toLng, $toLat,
            $withSteps ? 'full' : 'simplified', $withSteps ? '&steps=true' : '');
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
        $steps = [];
        if ($withSteps) {
            $factor = (float) $r['duration'] > 0 ? $duration / (float) $r['duration'] : 1.0;
            foreach ($r['legs'] ?? [] as $leg) {
                foreach ($leg['steps'] ?? [] as $st) {
                    $m = $st['maneuver'] ?? [];
                    $steps[] = [
                        'type' => (string) ($m['type'] ?? 'continue'),
                        'modifier' => isset($m['modifier']) ? (string) $m['modifier'] : null,
                        'exit' => isset($m['exit']) ? (int) $m['exit'] : null,
                        'name' => (string) ($st['name'] ?? ''),
                        'distance_m' => (int) round((float) ($st['distance'] ?? 0)),
                        'duration_s' => (int) round((float) ($st['duration'] ?? 0) * $factor),
                        'location' => isset($m['location']) ? [round((float) $m['location'][1], 6), round((float) $m['location'][0], 6)] : null,
                    ];
                }
            }
        }
        return new RouteResult((int) round((float) $r['distance']), $duration, $geometry, 'osrm', false, $steps);
    }
}
