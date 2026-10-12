<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Geo;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\MerchantHours;

final class CatalogController extends Controller
{
    public function merchants(Request $r): Response
    {
        $v = $this->validate($r, ['q' => 'nullable|string|max:80', 'kind' => 'nullable|in:restaurant,shop', 'cuisine' => 'nullable|string|max:80',
            'lat' => 'nullable|lat', 'lng' => 'nullable|lng', 'open_only' => 'nullable|bool', 'max_price' => 'nullable|int|min:0']);
        $where = ["m.status = 'verified'"];
        $p = [];
        if (!empty($v['q'])) {
            $where[] = '(m.name LIKE :q OR m.cuisine LIKE :q2 OR EXISTS (SELECT 1 FROM products pr WHERE pr.merchant_id = m.id AND pr.deleted_at IS NULL AND pr.name LIKE :q3))';
            $p['q'] = $p['q2'] = $p['q3'] = '%' . $v['q'] . '%';
        }
        if (!empty($v['kind'])) {
            $where[] = 'm.kind = :k';
            $p['k'] = $v['kind'];
        }
        if (!empty($v['cuisine'])) {
            $where[] = 'm.cuisine = :c';
            $p['c'] = $v['cuisine'];
        }
        $rows = Database::select('SELECT m.id, m.name, m.kind, m.cuisine, m.description, m.address_text, m.lat, m.lng, m.logo_file_id, m.prep_time_min, m.min_order,
                m.delivery_radius_km, m.rating_avg, m.rating_count, m.is_open, m.status,
                (SELECT MIN(COALESCE(promo_price, price)) FROM products pr WHERE pr.merchant_id = m.id AND pr.deleted_at IS NULL AND pr.is_available = 1) min_price,
                (SELECT COUNT(*) FROM products pr WHERE pr.merchant_id = m.id AND pr.deleted_at IS NULL AND pr.promo_price IS NOT NULL) promo_count
              FROM merchants m WHERE ' . implode(' AND ', $where) . ' ORDER BY m.rating_avg DESC, m.name LIMIT 100', $p);
        $out = [];
        foreach ($rows as $m) {
            $item = [
                'id' => (int) $m['id'], 'name' => $m['name'], 'kind' => $m['kind'], 'cuisine' => $m['cuisine'], 'description' => $m['description'],
                'address' => $m['address_text'], 'lat' => (float) $m['lat'], 'lng' => (float) $m['lng'],
                'logo_url' => $m['logo_file_id'] ? url('fichiers/' . $m['logo_file_id']) : null,
                'prep_time_min' => (int) $m['prep_time_min'], 'min_order' => (int) $m['min_order'],
                'rating' => (float) $m['rating_avg'], 'rating_count' => (int) $m['rating_count'],
                'open' => MerchantHours::isOpenNow($m), 'min_price' => $m['min_price'] !== null ? (int) $m['min_price'] : null, 'has_promo' => (int) $m['promo_count'] > 0,
            ];
            if (isset($v['lat'], $v['lng'])) {
                $item['distance_m'] = (int) Geo::distance($v['lat'], $v['lng'], (float) $m['lat'], (float) $m['lng']);
                $item['delivers_here'] = $item['distance_m'] <= (float) $m['delivery_radius_km'] * 1000;
            }
            if (!empty($v['open_only']) && !$item['open']) {
                continue;
            }
            if (!empty($v['max_price']) && $item['min_price'] !== null && $item['min_price'] > $v['max_price']) {
                continue;
            }
            $out[] = $item;
        }
        if (isset($v['lat'], $v['lng'])) {
            usort($out, static fn ($a, $b) => [$b['open'], -$a['distance_m']] <=> [$a['open'], -$b['distance_m']]);
        }
        return $this->json($out);
    }

    public function show(Request $r): Response
    {
        $m = Database::first("SELECT * FROM merchants WHERE id = :id AND status = 'verified'", ['id' => $r->intParam('id')]);
        if (!$m) {
            throw HttpException::notFound('Commerce introuvable.');
        }
        $categories = Database::select('SELECT id, name FROM product_categories WHERE merchant_id = :m ORDER BY sort_order, name', ['m' => $m['id']]);
        $products = Database::select('SELECT id, category_id, name, description, price, promo_price, photo_file_id, is_available, stock_qty FROM products
                                       WHERE merchant_id = :m AND deleted_at IS NULL ORDER BY sort_order, name', ['m' => $m['id']]);
        $options = [];
        if ($products) {
            [$in, $p] = Database::in(array_column($products, 'id'), 'p');
            foreach (Database::select("SELECT id, product_id, kind, group_name, name, price_delta FROM product_variants WHERE is_available = 1 AND product_id IN ($in) ORDER BY group_name, id", $p) as $o) {
                $options[$o['product_id']][] = ['id' => (int) $o['id'], 'kind' => $o['kind'], 'group' => $o['group_name'], 'name' => $o['name'], 'price_delta' => (int) $o['price_delta']];
            }
        }
        $hours = Database::select('SELECT weekday, open_time, close_time FROM merchant_hours WHERE merchant_id = :m ORDER BY weekday, open_time', ['m' => $m['id']]);
        return $this->json([
            'id' => (int) $m['id'], 'name' => $m['name'], 'kind' => $m['kind'], 'cuisine' => $m['cuisine'], 'description' => $m['description'],
            'address' => $m['address_text'], 'lat' => (float) $m['lat'], 'lng' => (float) $m['lng'],
            'logo_url' => $m['logo_file_id'] ? url('fichiers/' . $m['logo_file_id']) : null,
            'open' => MerchantHours::isOpenNow($m), 'prep_time_min' => (int) $m['prep_time_min'], 'min_order' => (int) $m['min_order'],
            'delivery_radius_km' => (float) $m['delivery_radius_km'], 'rating' => (float) $m['rating_avg'], 'rating_count' => (int) $m['rating_count'],
            'hours' => $hours,
            'categories' => $categories,
            'products' => array_map(static fn ($p) => [
                'id' => (int) $p['id'], 'category_id' => $p['category_id'] ? (int) $p['category_id'] : null, 'name' => $p['name'], 'description' => $p['description'],
                'price' => (int) $p['price'], 'promo_price' => $p['promo_price'] !== null ? (int) $p['promo_price'] : null,
                'photo_url' => $p['photo_file_id'] ? url('fichiers/' . $p['photo_file_id']) : null,
                'available' => (bool) $p['is_available'] && ((int) $m['track_stock'] === 0 || $p['stock_qty'] === null || (int) $p['stock_qty'] > 0),
                'options' => $options[$p['id']] ?? [],
            ], $products),
            'reviews' => Database::select("SELECT score, comment, DATE_FORMAT(created_at, '%d/%m/%Y') date FROM ratings WHERE target_type = 'merchant' AND target_id = :m AND status = 'published' AND comment IS NOT NULL ORDER BY id DESC LIMIT 10", ['m' => $m['id']]),
        ]);
    }

    public function promotions(Request $r): Response
    {
        return $this->json(Database::select("SELECT p.code, p.description, p.discount_type, p.value, p.max_discount, p.min_order, p.service_type, p.ends_at, m.name merchant
                                              FROM promotions p LEFT JOIN merchants m ON m.id = p.merchant_id
                                             WHERE p.is_active = 1 AND p.is_public = 1 AND NOW() BETWEEN p.starts_at AND p.ends_at
                                               AND (p.max_uses IS NULL OR p.used_count < p.max_uses) ORDER BY p.ends_at LIMIT 20"));
    }
}
