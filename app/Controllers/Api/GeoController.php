<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Database;
use App\Core\Geo;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\PricingService;

final class GeoController extends Controller
{
    public function config(Request $r): Response
    {
        $maps = Config::get('services.maps');
        return $this->json([
            'tile_url' => $maps['tile_url'],
            'attribution' => $maps['attribution'],
            'center' => [$maps['default_lat'], $maps['default_lng']],
            'zoom' => $maps['default_zoom'],
            'cities' => Database::select('SELECT id, name, region, lat, lng FROM cities WHERE is_active = 1 ORDER BY name'),
            'routing' => Config::get('services.routing.driver'),
            'payment_methods' => Settings::get('payments.methods', ['cash']),
            'demo_payments' => \App\Services\PaymentService::demoEnabled(),
            'prohibited_items' => Settings::get('prohibited_items', []),
            'emergency' => ['numbers' => Settings::get('emergency.numbers', []), 'message' => Settings::get('emergency.message', '')],
            'support' => ['phone' => Settings::get('support.phone', ''), 'email' => Settings::get('support.email', '')],
        ]);
    }

    /** Recherche : points de repère et quartiers MARWA d'abord, puis OpenStreetMap (Nominatim, mis en cache). */
    public function search(Request $r): Response
    {
        $q = trim((string) $r->input('q', ''));
        if (mb_strlen($q) < 2) {
            return $this->json([]);
        }
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $results = [];
        foreach (Database::select('SELECT l.name, l.category, l.lat, l.lng, l.verified, c.name city FROM landmarks l JOIN cities c ON c.id = l.city_id WHERE l.is_active = 1 AND l.name LIKE :q ORDER BY l.verified DESC, l.name LIMIT 8', ['q' => $like]) as $l) {
            $results[] = ['label' => $l['name'], 'detail' => $l['category'] . ' · ' . $l['city'] . ((int) $l['verified'] ? '' : ' · à vérifier'), 'lat' => (float) $l['lat'], 'lng' => (float) $l['lng'], 'source' => 'landmark'];
        }
        foreach (Database::select('SELECT n.name, n.lat, n.lng, c.name city FROM neighborhoods n JOIN cities c ON c.id = n.city_id WHERE n.lat IS NOT NULL AND n.name LIKE :q LIMIT 5', ['q' => $like]) as $n) {
            $results[] = ['label' => $n['name'], 'detail' => 'Quartier · ' . $n['city'], 'lat' => (float) $n['lat'], 'lng' => (float) $n['lng'], 'source' => 'neighborhood'];
        }
        if (count($results) < 5) {
            foreach ($this->nominatim('search', ['q' => $q, 'format' => 'jsonv2', 'limit' => 6, 'countrycodes' => Config::get('services.geocoding.country_codes'), 'accept-language' => 'fr']) as $n) {
                $results[] = ['label' => $n['name'] ?: strtok((string) $n['display_name'], ','), 'detail' => $n['display_name'], 'lat' => (float) $n['lat'], 'lng' => (float) $n['lon'], 'source' => 'osm'];
            }
        }
        return $this->json(array_slice($results, 0, 12));
    }

    public function reverse(Request $r): Response
    {
        $v = $this->validate($r, ['lat' => 'required|lat', 'lng' => 'required|lng']);
        $near = null;
        $best = 400.0;
        foreach (Database::select('SELECT name, lat, lng FROM landmarks WHERE is_active = 1 AND lat BETWEEN :a AND :b AND lng BETWEEN :c AND :d',
            ['a' => $v['lat'] - 0.01, 'b' => $v['lat'] + 0.01, 'c' => $v['lng'] - 0.01, 'd' => $v['lng'] + 0.01]) as $l) {
            $d = Geo::distance($v['lat'], $v['lng'], (float) $l['lat'], (float) $l['lng']);
            if ($d < $best) {
                $best = $d;
                $near = $l['name'];
            }
        }
        $label = null;
        $res = $this->nominatim('reverse', ['lat' => $v['lat'], 'lon' => $v['lng'], 'format' => 'jsonv2', 'zoom' => 17, 'accept-language' => 'fr']);
        if (!empty($res['display_name'])) {
            $a = $res['address'] ?? [];
            $parts = array_filter([$a['road'] ?? null, $a['suburb'] ?? ($a['neighbourhood'] ?? null), $a['city'] ?? ($a['town'] ?? null)]);
            $label = $parts ? implode(', ', $parts) : $res['display_name'];
        }
        $text = $label ?? sprintf('Position %.5f, %.5f', $v['lat'], $v['lng']);
        if ($near) {
            $text = 'Près de ' . $near . ($label ? ' — ' . $label : '');
        }
        return $this->json(['label' => $text, 'landmark' => $near, 'lat' => $v['lat'], 'lng' => $v['lng'], 'approximate' => $label === null]);
    }

    private function nominatim(string $endpoint, array $params): array
    {
        $cfg = Config::get('services.geocoding');
        if (($cfg['driver'] ?? 'none') !== 'nominatim') {
            return [];
        }
        $url = rtrim((string) $cfg['nominatim_url'], '/') . '/' . $endpoint . '?' . http_build_query($params);
        $hash = hash('sha256', $url);
        $cached = Database::first('SELECT result FROM geocode_cache WHERE query_hash = :h AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)', ['h' => $hash]);
        if ($cached) {
            return json_decode($cached['result'], true) ?: [];
        }
        $breaker = MARWA_ROOT . '/storage/cache/nominatim_down';
        if (is_file($breaker) && filemtime($breaker) > time() - 120) {
            return [];
        }
        // Politique d'usage Nominatim : 1 requête/s maximum, User-Agent identifiant.
        if (!\App\Core\RateLimiter::hit('nominatim:global', 1, 1)) {
            return [];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_USERAGENT => (string) $cfg['user_agent']]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200) {
            @touch($breaker);
            return [];
        }
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            return [];
        }
        Database::execute('REPLACE INTO geocode_cache (query_hash, query, result) VALUES (:h, :q, :r)', ['h' => $hash, 'q' => mb_substr($url, 0, 255), 'r' => json_encode($data)]);
        return $data;
    }

    public function zones(Request $r): Response
    {
        $rows = Database::select('SELECT id, city_id, name, polygon, allow_rides, allow_delivery FROM service_zones WHERE is_active = 1');
        foreach ($rows as &$z) {
            $z['polygon'] = json_decode($z['polygon'], true);
        }
        return $this->json($rows);
    }

    public function landmarks(Request $r): Response
    {
        return $this->json(Database::select('SELECT id, name, category, lat, lng, verified, is_meeting_point FROM landmarks WHERE is_active = 1 ORDER BY is_meeting_point DESC, name LIMIT 500'));
    }

    public function estimate(Request $r): Response
    {
        $v = $this->validate($r, [
            'service_type' => 'required|in:taxi,food,parcel,document,errand,shop,urgent,pro',
            'vehicle_category' => 'nullable|string|max:20',
            'pickup_lat' => 'required|lat', 'pickup_lng' => 'required|lng',
            'dropoff_lat' => 'required|lat', 'dropoff_lng' => 'required|lng',
        ]);
        $categories = $v['service_type'] === 'taxi' && empty($v['vehicle_category']) ? PricingService::RIDE_VEHICLES : [$v['vehicle_category'] ?? null];
        $out = [];
        foreach ($categories as $cat) {
            try {
                $out[] = PricingService::estimate($v['service_type'], $cat, $v['pickup_lat'], $v['pickup_lng'], $v['dropoff_lat'], $v['dropoff_lng']);
            } catch (\App\Core\HttpException $e) {
                if (count($categories) === 1) {
                    throw $e;
                }
            }
        }
        return $this->json($out);
    }
}
