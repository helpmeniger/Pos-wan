<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Geo;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\CommissionService;
use App\Services\Notification\SmsSender;
use App\Services\PaymentService;

/** Paramétrage : tarifs, commissions, zones, villes, repères, promotions, paramètres, contenus, audit. */
final class ConfigController extends Controller
{
    // ------------------------------------------------------------------ Tarifs

    private const PRICING_RULES = [
        'name' => 'required|string|max:100', 'service_type' => 'required|in:taxi,food,parcel,document,errand,shop,urgent,pro',
        'vehicle_category' => 'nullable|in:taxi,sedan,family,van,moto_taxi,moto,bicycle,car', 'city_id' => 'nullable|int', 'zone_id' => 'nullable|int',
        'base_fare' => 'required|int|min:0', 'per_km' => 'required|int|min:0', 'per_min' => 'required|int|min:0', 'min_fare' => 'required|int|min:0',
        'booking_fee' => 'required|int|min:0', 'service_fee' => 'required|int|min:0', 'night_multiplier_pct' => 'required|int|between:50,300',
        'night_start' => 'nullable|time', 'night_end' => 'nullable|time', 'min_offer_pct' => 'required|int|between:10,100', 'max_offer_pct' => 'required|int|between:100,1000',
        'priority' => 'nullable|int', 'is_active' => 'required|bool', 'valid_from' => 'nullable|datetime', 'valid_to' => 'nullable|datetime',
    ];

    public function pricing(Request $r): Response
    {
        return $this->json(Database::select('SELECT p.*, c.name city, z.name zone FROM pricing_rules p LEFT JOIN cities c ON c.id = p.city_id LEFT JOIN service_zones z ON z.id = p.zone_id ORDER BY p.service_type, p.vehicle_category, p.priority DESC'));
    }

    public function savePricing(Request $r): Response
    {
        $v = $this->validate($r, self::PRICING_RULES);
        $v['is_active'] = $v['is_active'] ? 1 : 0;
        $v['priority'] ??= 0;
        return $this->saveRow('pricing_rules', $r->param('id'), $v, 'pricing');
    }

    // ------------------------------------------------------------------ Commissions

    private const COMMISSION_RULES = [
        'name' => 'required|string|max:100', 'applies_to' => 'required|in:provider,merchant', 'service_type' => 'nullable|in:taxi,food,parcel,document,errand,shop,urgent,pro',
        'provider_type' => 'nullable|in:driver,courier', 'vehicle_category' => 'nullable|string|max:20', 'merchant_id' => 'nullable|int', 'provider_id' => 'nullable|int',
        'business_id' => 'nullable|int', 'city_id' => 'nullable|int', 'zone_id' => 'nullable|int', 'model' => 'required|in:percent,fixed,mixed,subscription',
        'percent_bp' => 'required|int|between:0,10000', 'fixed_amount' => 'required|int|min:0', 'min_commission' => 'nullable|int|min:0', 'max_commission' => 'nullable|int|min:0',
        'min_order_amount' => 'nullable|int|min:0', 'max_order_amount' => 'nullable|int|min:0', 'priority' => 'nullable|int', 'is_active' => 'required|bool',
        'valid_from' => 'nullable|datetime', 'valid_to' => 'nullable|datetime',
    ];

    public function commissions(Request $r): Response
    {
        return $this->json(Database::select('SELECT c.*, m.name merchant, u.full_name provider, b.name business, ci.name city FROM commission_rules c
            LEFT JOIN merchants m ON m.id = c.merchant_id LEFT JOIN users u ON u.id = c.provider_id LEFT JOIN businesses b ON b.id = c.business_id LEFT JOIN cities ci ON ci.id = c.city_id
            ORDER BY c.applies_to, c.priority DESC, c.id'));
    }

    public function saveCommission(Request $r): Response
    {
        $v = $this->validate($r, self::COMMISSION_RULES);
        $v['is_active'] = $v['is_active'] ? 1 : 0;
        $v['priority'] ??= 0;
        return $this->saveRow('commission_rules', $r->param('id'), $v, 'commission');
    }

    /** Simulation : quelle règle s'applique et pour quel montant. */
    public function previewCommission(Request $r): Response
    {
        $v = $this->validate($r, ['applies_to' => 'required|in:provider,merchant', 'amount' => 'required|int|min:0', 'service_type' => 'nullable|string|max:20',
            'provider_type' => 'nullable|in:driver,courier', 'merchant_id' => 'nullable|int', 'provider_id' => 'nullable|int', 'city_id' => 'nullable|int']);
        $rule = CommissionService::findRule($v['applies_to'], $v);
        $c = CommissionService::compute($rule, $v['amount']);
        return $this->json($c + ['rule' => $rule ? ['id' => (int) $rule['id'], 'name' => $rule['name']] : null, 'partner_net' => $v['amount'] - $c['commission'], 'vat_part' => CommissionService::vatPart($c['commission'])]);
    }

    // ------------------------------------------------------------------ Géographie

    public function cities(Request $r): Response
    {
        return $this->json(Database::select('SELECT * FROM cities ORDER BY is_active DESC, name'));
    }

    public function saveCity(Request $r): Response
    {
        $v = $this->validate($r, ['name' => 'required|string|max:80', 'region' => 'required|string|max:80', 'lat' => 'required|lat', 'lng' => 'required|lng', 'is_active' => 'required|bool', 'country_code' => 'nullable|regex:/^[A-Z]{2}$/']);
        $v['is_active'] = $v['is_active'] ? 1 : 0;
        $v['country_code'] ??= 'NE';
        return $this->saveRow('cities', $r->param('id'), $v, 'city');
    }

    public function neighborhoods(Request $r): Response
    {
        return $this->json(Database::select('SELECT n.*, c.name city FROM neighborhoods n JOIN cities c ON c.id = n.city_id ORDER BY c.name, n.name'));
    }

    public function saveNeighborhood(Request $r): Response
    {
        $v = $this->validate($r, ['city_id' => 'required|int', 'name' => 'required|string|max:80', 'lat' => 'nullable|lat', 'lng' => 'nullable|lng', 'verified' => 'nullable|bool']);
        $v['verified'] = !empty($v['verified']) ? 1 : 0;
        return $this->saveRow('neighborhoods', $r->param('id'), $v, 'neighborhood');
    }

    public function zones(Request $r): Response
    {
        $rows = Database::select('SELECT z.*, c.name city FROM service_zones z JOIN cities c ON c.id = z.city_id ORDER BY c.name, z.name');
        foreach ($rows as &$z) {
            $z['polygon'] = json_decode($z['polygon'], true);
        }
        return $this->json($rows);
    }

    public function saveZone(Request $r): Response
    {
        $v = $this->validate($r, ['city_id' => 'required|int', 'name' => 'required|string|max:80', 'polygon' => 'required|array', 'allow_rides' => 'required|bool', 'allow_delivery' => 'required|bool', 'is_active' => 'required|bool']);
        $ring = Geo::parsePolygon($v['polygon']);
        $data = ['city_id' => $v['city_id'], 'name' => $v['name'], 'polygon' => json_encode(['type' => 'Polygon', 'coordinates' => [$ring]]),
            'allow_rides' => $v['allow_rides'] ? 1 : 0, 'allow_delivery' => $v['allow_delivery'] ? 1 : 0, 'is_active' => $v['is_active'] ? 1 : 0] + Geo::bbox($ring);
        return $this->saveRow('service_zones', $r->param('id'), $data, 'zone');
    }

    public function deleteZone(Request $r): Response
    {
        $id = $r->intParam('id');
        Database::update('service_zones', ['is_active' => 0], 'id = :id', ['id' => $id]);
        Audit::log('zone.disabled', 'zone', $id);
        return $this->ok('Zone désactivée.');
    }

    public function landmarks(Request $r): Response
    {
        return $this->json(Database::select('SELECT l.*, c.name city FROM landmarks l JOIN cities c ON c.id = l.city_id ORDER BY l.verified, l.name LIMIT 2000'));
    }

    public function saveLandmark(Request $r): Response
    {
        $v = $this->validate($r, ['city_id' => 'required|int', 'name' => 'required|string|max:150', 'category' => 'required|string|max:40', 'lat' => 'required|lat', 'lng' => 'required|lng',
            'verified' => 'nullable|bool', 'is_meeting_point' => 'nullable|bool', 'is_active' => 'nullable|bool']);
        foreach (['verified', 'is_meeting_point'] as $k) {
            $v[$k] = !empty($v[$k]) ? 1 : 0;
        }
        $v['is_active'] = !array_key_exists('is_active', $v) || $v['is_active'] ? 1 : 0;
        if (!$r->param('id')) {
            $v['source'] = 'admin:' . bin2hex(random_bytes(6));
        }
        return $this->saveRow('landmarks', $r->param('id'), $v, 'landmark');
    }

    // ------------------------------------------------------------------ Promotions

    public function promotions(Request $r): Response
    {
        return $this->json(array_map(static fn ($p) => $p + ['image_url' => $p['image_file_id'] ? url('fichiers/' . $p['image_file_id']) : null],
            Database::select('SELECT p.*, m.name merchant FROM promotions p LEFT JOIN merchants m ON m.id = p.merchant_id ORDER BY p.id DESC')));
    }

    /** Photo (bannière) d'une promotion, affichée aux clients. */
    public function promotionImage(Request $r): Response
    {
        $p = Database::first('SELECT * FROM promotions WHERE id = :id', ['id' => $r->intParam('id')]);
        if (!$p) {
            throw HttpException::notFound('Promotion introuvable.');
        }
        $f = \App\Services\UploadService::store($r->file('file'), (int) $this->user()['id'], 'logo', true);
        Database::update('promotions', ['image_file_id' => $f['id']], 'id = :id', ['id' => $p['id']]);
        Audit::log('promotion.image', 'promotion', (int) $p['id']);
        return $this->json(['image_url' => url('fichiers/' . $f['id'])]);
    }

    public function savePromotion(Request $r): Response
    {
        $v = $this->validate($r, ['code' => 'required|regex:/^[A-Za-z0-9_-]{3,30}$/', 'description' => 'required|string|max:255', 'discount_type' => 'required|in:percent,fixed',
            'value' => 'required|int|min:1', 'max_discount' => 'nullable|int|min:0', 'min_order' => 'nullable|int|min:0', 'service_type' => 'nullable|in:taxi,food,parcel,document,errand,shop,urgent,pro',
            'merchant_id' => 'nullable|int', 'funded_by' => 'required|in:marwa,merchant', 'starts_at' => 'required|datetime', 'ends_at' => 'required|datetime',
            'max_uses' => 'nullable|int|min:1', 'per_user_limit' => 'required|int|min:1', 'is_active' => 'required|bool', 'is_public' => 'nullable|bool']);
        if ($v['discount_type'] === 'percent' && $v['value'] > 10000) {
            throw \App\Core\ValidationException::field('value', 'Pourcentage exprimé en points de base (1000 = 10 %), maximum 10000.');
        }
        if ($v['funded_by'] === 'merchant' && empty($v['merchant_id'])) {
            throw \App\Core\ValidationException::field('merchant_id', 'Une promotion financée par un commerce doit lui être rattachée.');
        }
        $v['code'] = strtoupper($v['code']);
        $v['is_active'] = $v['is_active'] ? 1 : 0;
        $v['is_public'] = !array_key_exists('is_public', $v) || $v['is_public'] ? 1 : 0;
        $v['min_order'] ??= 0;
        return $this->saveRow('promotions', $r->param('id'), $v, 'promotion');
    }

    // ------------------------------------------------------------------ Paramètres & fournisseurs

    public function settings(Request $r): Response
    {
        return $this->json(['settings' => Settings::all(), 'content' => Database::select('SELECT slug, title, body, updated_at FROM content_pages ORDER BY slug')]);
    }

    public function saveSettings(Request $r): Response
    {
        $values = (array) $r->input('settings', []);
        $known = array_keys(Settings::all());
        $before = Settings::all();
        foreach ($values as $k => $val) {
            if (!in_array($k, $known, true)) {
                throw \App\Core\ValidationException::field((string) $k, 'Paramètre inconnu.');
            }
            if (is_int($before[$k]) || (is_numeric($before[$k]) && !is_bool($before[$k]))) {
                if (!is_numeric($val) || (float) $val < 0) {
                    throw \App\Core\ValidationException::field((string) $k, 'Valeur numérique positive attendue.');
                }
                $val = $val + 0;
            } elseif (is_bool($before[$k])) {
                $val = filter_var($val, FILTER_VALIDATE_BOOLEAN);
            } elseif (is_array($before[$k]) && !is_array($val)) {
                throw \App\Core\ValidationException::field((string) $k, 'Liste attendue.');
            }
            if ($k === 'payments.methods') {
                $val = array_values(array_intersect((array) $val, ['cash', 'demo_mobile_money', 'business_account', 'mobile_money', 'card']));
                if (!in_array('cash', $val, true)) {
                    $val[] = 'cash';
                }
            }
            Settings::set($k, $val, Auth::id());
            Audit::log('settings.updated', 'setting', null, [$k => $before[$k] ?? null], [$k => $val]);
        }
        return $this->ok('Paramètres enregistrés.');
    }

    public function saveContent(Request $r): Response
    {
        $v = $this->validate($r, ['slug' => 'required|regex:/^[a-z0-9-]{2,60}$/', 'title' => 'required|string|max:150', 'body' => 'required|string|max:50000']);
        Database::execute('INSERT INTO content_pages (slug, title, body, updated_by) VALUES (:s, :t, :b, :u) ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), updated_by = VALUES(updated_by)',
            ['s' => $v['slug'], 't' => $v['title'], 'b' => $v['body'], 'u' => Auth::id()]);
        Audit::log('content.updated', 'content', null, null, ['slug' => $v['slug']]);
        return $this->ok('Contenu enregistré.');
    }

    /** État réel des fournisseurs externes (aucune intégration n'est présentée comme active si elle ne l'est pas). */
    public function system(Request $r): Response
    {
        return $this->json([
            'php' => PHP_VERSION,
            'database' => Database::value('SELECT VERSION()'),
            'env' => Config::get('app.env'),
            'https_cookies' => (bool) Config::get('security.cookie_secure'),
            'providers' => [
                'maps' => ['status' => 'actif', 'detail' => 'Leaflet + tuiles ' . Config::get('services.maps.tile_url')],
                'routing' => ['status' => Config::get('services.routing.driver') === 'osrm' ? 'osrm' : 'estimation', 'detail' => Config::get('services.routing.driver') === 'osrm'
                    ? 'OSRM : ' . Config::get('services.routing.osrm_url') . (is_file(MARWA_ROOT . '/storage/cache/osrm_down') && filemtime(MARWA_ROOT . '/storage/cache/osrm_down') > time() - 300 ? ' — injoignable récemment, estimation utilisée' : '')
                    : 'Estimation à vol d\'oiseau × facteur routier (approximative)'],
                'geocoding' => ['status' => Config::get('services.geocoding.driver'), 'detail' => 'Recherche locale (repères MARWA) + ' . Config::get('services.geocoding.driver')],
                'sms' => ['status' => SmsSender::isReal() ? 'réel' : 'journal (aucun envoi)', 'detail' => 'Pilote : ' . Config::get('services.sms.driver')],
                'email' => ['status' => Config::get('services.mail.driver'), 'detail' => Config::get('services.mail.driver') === 'log' ? 'Écrit dans storage/logs/outbox.log' : 'Expéditeur ' . Config::get('services.mail.from')],
                'whatsapp' => ['status' => 'non configuré', 'detail' => 'Nécessite l\'API WhatsApp Business officielle (payante).'],
                'payments' => ['status' => PaymentService::demoEnabled() ? 'démonstration' : 'espèces / entreprise uniquement', 'detail' => 'Aucune passerelle réelle intégrée. Espèces et compte entreprise opérationnels.'],
            ],
            'ledger' => \App\Services\LedgerService::trialBalance(),
            'outbox_failed' => (int) Database::value("SELECT COUNT(*) FROM outbound_messages WHERE status = 'failed'"),
            'security' => \App\Support\SecurityCheck::run(),
            'push' => \App\Services\PushService::stats(),
        ]);
    }

    public function audit(Request $r): Response
    {
        [$limit, $offset] = $this->page($r, 100, 500);
        $w = ['1=1'];
        $p = [];
        if ($a = $r->input('action')) {
            $w[] = 'a.action LIKE :a';
            $p['a'] = $a . '%';
        }
        return $this->json(Database::select('SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE ' . implode(' AND ', $w) . " ORDER BY a.id DESC LIMIT $limit OFFSET $offset", $p));
    }

    private function saveRow(string $table, ?string $id, array $data, string $entity): Response
    {
        if ($id !== null) {
            if (!ctype_digit($id)) {
                throw HttpException::notFound();
            }
            $old = Database::first("SELECT * FROM `$table` WHERE id = :id", ['id' => (int) $id]);
            if (!$old) {
                throw HttpException::notFound();
            }
            Database::update($table, $data, 'id = :id', ['id' => (int) $id]);
            Audit::log($entity . '.updated', $entity, (int) $id, $old, $data);
            return $this->json(['id' => (int) $id]);
        }
        $newId = Database::insert($table, $data);
        Audit::log($entity . '.created', $entity, $newId, null, $data);
        return $this->json(['id' => $newId], 201);
    }
}
