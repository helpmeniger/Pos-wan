<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\DispatchService;
use App\Services\FinanceService;
use App\Services\LedgerService;
use App\Services\NegotiationService;
use App\Services\NotificationService;
use App\Services\OrderPolicy;
use App\Services\OrderService;
use App\Services\OrderStateMachine;
use App\Services\PaymentService;
use App\Services\PricingService;
use App\Services\TrackingService;
use App\Services\UploadService;

/** Espace chauffeur / livreur. */
final class ProviderController extends Controller
{
    private function profile(): array
    {
        $p = Database::first('SELECT * FROM provider_profiles WHERE user_id = :u', ['u' => $this->user()['id']]);
        if (!$p) {
            throw HttpException::forbidden();
        }
        return $p;
    }

    public function show(Request $r): Response
    {
        $p = $this->profile();
        $u = $this->user();
        return $this->json([
            'profile' => [
                'provider_type' => $p['provider_type'], 'verification_status' => $p['verification_status'], 'verification_note' => $p['verification_note'],
                'id_number' => $p['id_number'], 'license_number' => $p['license_number'], 'license_expires_at' => $p['license_expires_at'],
                'is_online' => (bool) $p['is_online'], 'current_order_id' => $p['current_order_id'] ? (int) $p['current_order_id'] : null,
                'rating' => (float) $p['rating_avg'], 'rating_count' => (int) $p['rating_count'],
                'acceptance' => ['received' => (int) $p['offers_received'], 'refused' => (int) $p['offers_refused'], 'expired' => (int) $p['offers_expired']],
            ],
            'vehicle' => Database::first('SELECT id, category, make, model, color, plate, capacity, photo_file_id FROM vehicles WHERE provider_id = :u AND is_active = 1 ORDER BY id LIMIT 1', ['u' => $u['id']]),
            'documents' => Database::select('SELECT id, doc_type, expires_at, status, review_note, created_at FROM provider_documents WHERE provider_id = :u ORDER BY id DESC', ['u' => $u['id']]),
            'vehicle_categories' => $p['provider_type'] === 'driver' ? PricingService::RIDE_VEHICLES : PricingService::COURIER_VEHICLES,
            'reviews' => Database::select("SELECT score, comment, DATE_FORMAT(created_at, '%d/%m/%Y') date FROM ratings WHERE target_type = 'provider' AND target_id = :u AND status = 'published' ORDER BY id DESC LIMIT 10", ['u' => $u['id']]),
        ]);
    }

    public function update(Request $r): Response
    {
        $p = $this->profile();
        $v = $this->validate($r, ['id_number' => 'nullable|string|max:60', 'license_number' => 'nullable|string|max:60', 'license_expires_at' => 'nullable|date']);
        $data = $v;
        // Une modification des pièces d'un compte validé repasse en vérification.
        if ($p['verification_status'] === 'verified' && (($v['license_number'] ?? null) !== $p['license_number'] || ($v['id_number'] ?? null) !== $p['id_number'])) {
            $data['verification_status'] = 'pending';
            $data['is_online'] = 0;
        }
        Database::update('provider_profiles', $data, 'user_id = :u', ['u' => $p['user_id']]);
        return $this->ok(isset($data['verification_status']) ? 'Informations enregistrées. Votre compte repasse en vérification.' : 'Informations enregistrées.');
    }

    public function saveVehicle(Request $r): Response
    {
        $p = $this->profile();
        $cats = $p['provider_type'] === 'driver' ? PricingService::RIDE_VEHICLES : PricingService::COURIER_VEHICLES;
        $v = $this->validate($r, ['category' => 'required|in:' . implode(',', $cats), 'make' => 'nullable|string|max:60', 'model' => 'nullable|string|max:60',
            'color' => 'nullable|string|max:40', 'plate' => 'nullable|string|max:30', 'capacity' => 'nullable|int|between:1,30']);
        if ($v['category'] !== 'bicycle' && empty($v['plate'])) {
            throw \App\Core\ValidationException::field('plate', 'L\'immatriculation est obligatoire pour ce véhicule.');
        }
        $v['plate'] = !empty($v['plate']) ? strtoupper(preg_replace('/\s+/', ' ', $v['plate'])) : null;
        if ($v['plate'] && Database::value('SELECT 1 FROM vehicles WHERE plate = :p AND provider_id <> :u', ['p' => $v['plate'], 'u' => $p['user_id']])) {
            throw \App\Core\ValidationException::field('plate', 'Cette immatriculation est déjà enregistrée.');
        }
        $v['capacity'] ??= in_array($v['category'], ['moto', 'moto_taxi', 'bicycle'], true) ? 1 : 4;
        $existing = Database::value('SELECT id FROM vehicles WHERE provider_id = :u AND is_active = 1 ORDER BY id LIMIT 1', ['u' => $p['user_id']]);
        if ($existing) {
            Database::update('vehicles', $v, 'id = :id', ['id' => $existing]);
        } else {
            Database::insert('vehicles', $v + ['provider_id' => $p['user_id']]);
        }
        if ($p['verification_status'] === 'verified' && $existing) {
            Database::execute("UPDATE provider_profiles SET verification_status = 'pending', is_online = 0, verification_note = 'Véhicule modifié : nouvelle vérification' WHERE user_id = :u", ['u' => $p['user_id']]);
        }
        return $this->ok('Véhicule enregistré.');
    }

    public function vehiclePhoto(Request $r): Response
    {
        $p = $this->profile();
        $f = UploadService::store($r->file('file'), (int) $p['user_id'], 'vehicle', true);
        Database::execute('UPDATE vehicles SET photo_file_id = :f WHERE provider_id = :u AND is_active = 1', ['f' => $f['id'], 'u' => $p['user_id']]);
        return $this->json(['file_id' => (int) $f['id']]);
    }

    public function uploadDocument(Request $r): Response
    {
        $p = $this->profile();
        $v = $this->validate($r, ['doc_type' => 'required|in:id_card,driving_license,vehicle_registration,insurance,technical_inspection,photo,other', 'expires_at' => 'nullable|date']);
        RateLimiter::enforce('doc_upload:' . $p['user_id'], 20, 3600);
        $f = UploadService::store($r->file('file'), (int) $p['user_id'], 'document');
        $id = Database::insert('provider_documents', ['provider_id' => $p['user_id'], 'doc_type' => $v['doc_type'], 'file_id' => $f['id'], 'expires_at' => $v['expires_at'] ?? null]);
        if ($v['doc_type'] === 'driving_license' && !empty($v['expires_at'])) {
            Database::update('provider_profiles', ['license_expires_at' => $v['expires_at']], 'user_id = :u', ['u' => $p['user_id']]);
        }
        NotificationService::staff('partners.validate', 'document_uploaded', 'Document à vérifier', $this->user()['full_name'] . ' a ajouté un document.', ['provider_id' => (int) $p['user_id']]);
        return $this->json(['id' => $id], 201);
    }

    public function setOnline(Request $r): Response
    {
        $p = $this->profile();
        $v = $this->validate($r, ['online' => 'required|bool', 'lat' => 'nullable|lat', 'lng' => 'nullable|lng']);
        if ($v['online']) {
            if (!Database::value('SELECT 1 FROM vehicles WHERE provider_id = :u AND is_active = 1', ['u' => $p['user_id']])) {
                throw HttpException::conflict('Enregistrez votre véhicule avant de passer en ligne.');
            }
            if ($p['license_expires_at'] && $p['license_expires_at'] < date('Y-m-d') && $p['provider_type'] === 'driver') {
                throw HttpException::conflict('Votre permis de conduire a expiré. Mettez à jour vos documents.');
            }
        } elseif ($p['current_order_id']) {
            throw HttpException::conflict('Terminez votre commande en cours avant de passer hors ligne.');
        }
        Database::update('provider_profiles', ['is_online' => $v['online'] ? 1 : 0, 'went_online_at' => $v['online'] ? date('Y-m-d H:i:s') : null], 'user_id = :u', ['u' => $p['user_id']]);
        if ($v['online'] && isset($v['lat'], $v['lng'])) {
            TrackingService::updateLocation((int) $p['user_id'], $v['lat'], $v['lng'], null, null, null);
        }
        if (!$v['online']) {
            // Hors ligne : la position n'est plus conservée.
            Database::execute('DELETE FROM provider_locations WHERE provider_id = :u', ['u' => $p['user_id']]);
            Database::execute("UPDATE dispatch_offers SET status = 'expired' WHERE provider_id = :u AND status = 'pending'", ['u' => $p['user_id']]);
        }
        return $this->json(['online' => $v['online']]);
    }

    public function location(Request $r): Response
    {
        $v = $this->validate($r, ['lat' => 'required|lat', 'lng' => 'required|lng', 'heading' => 'nullable|numeric|between:0,360', 'speed' => 'nullable|numeric|between:0,300', 'accuracy' => 'nullable|numeric|between:0,100000']);
        $res = TrackingService::updateLocation((int) $this->user()['id'], $v['lat'], $v['lng'], isset($v['heading']) ? (int) round($v['heading']) : null,
            isset($v['speed']) ? round((float) $v['speed'] * 3.6, 1) : null, isset($v['accuracy']) ? (int) round($v['accuracy']) : null);
        return $this->json($res);
    }

    public function requests(Request $r): Response
    {
        $u = $this->user();
        DispatchService::tick();
        $rows = Database::select("SELECT o.*, d.status offer_status, d.expires_at offer_expires_at, d.distance_m offer_distance FROM dispatch_offers d JOIN orders o ON o.id = d.order_id
                                   WHERE d.provider_id = :u AND d.status IN ('pending','countered') AND d.expires_at > NOW() AND o.provider_id IS NULL ORDER BY d.id DESC", ['u' => $u['id']]);
        $out = [];
        foreach ($rows as $o) {
            $out[] = OrderPolicy::present($o, 'candidate') + ['offer_status' => $o['offer_status'], 'offer_expires_at' => $o['offer_expires_at'], 'offer_distance_m' => (int) $o['offer_distance']];
        }
        return $this->json($out);
    }

    public function accept(Request $r): Response
    {
        $order = DispatchService::acceptByProvider($r->intParam('id'), (int) $this->user()['id']);
        return $this->json(OrderPolicy::present($order, 'provider'));
    }

    public function refuse(Request $r): Response
    {
        DispatchService::refuse($r->intParam('id'), (int) $this->user()['id'], $r->input('reason') ? (string) $r->input('reason') : null);
        return $this->ok('Demande refusée.');
    }

    public function counter(Request $r): Response
    {
        $v = $this->validate($r, ['amount' => 'required|int|min:1', 'ttl_seconds' => 'nullable|int|between:30,300']);
        $order = DispatchService::fresh($r->intParam('id'));
        RateLimiter::enforce('counter:' . $this->user()['id'], 6, 60, 'Trop de contre-propositions. Patientez une minute.');
        $neg = NegotiationService::providerCounter($order, (int) $this->user()['id'], $v['amount'], $v['ttl_seconds'] ?? null);
        return $this->json(['id' => (int) $neg['id'], 'amount' => (int) $neg['amount'], 'expires_at' => $neg['expires_at']], 201);
    }

    public function current(Request $r): Response
    {
        $p = $this->profile();
        if (!$p['current_order_id']) {
            // Commande terminée en attente de déclaration d'espèces
            $o = Database::first("SELECT * FROM orders WHERE provider_id = :u AND status IN ('completed','delivered','returned') AND payment_method = 'cash' AND payment_status = 'unpaid' ORDER BY id DESC LIMIT 1", ['u' => $p['user_id']]);
            return $this->json($o ? OrderPolicy::present($o, 'provider') : null);
        }
        $o = DispatchService::fresh((int) $p['current_order_id']);
        return $this->json(OrderPolicy::present($o, 'provider'));
    }

    /** Itinéraire guidé depuis la position actuelle du prestataire (navigation dans l'application). */
    public function navigation(Request $r): Response
    {
        $order = OrderPolicy::load($r->intParam('id'), $this->user(), ['provider']);
        $v = $this->validate($r, ['lat' => 'required|lat', 'lng' => 'required|lng']);
        if (!in_array($order['status'], OrderStateMachine::TRACKABLE, true)) {
            throw new \App\Core\HttpException(409, 'Cette commande n\'est plus en cours.', 'not_active');
        }
        return $this->json(\App\Services\NavigationService::forProvider($order, (float) $v['lat'], (float) $v['lng']));
    }

    public function action(Request $r): Response
    {
        $u = $this->user();
        $order = OrderPolicy::load($r->intParam('id'), $u, ['provider']);
        $action = (string) $r->input('action', '');
        if ($action === 'release') {
            $o = OrderService::cancel($order, 'provider', (int) $u['id'], (string) $r->input('reason', ''));
            return $this->json(['released' => true, 'reference' => $o['reference']]);
        }
        if ($action === 'cancelled') {
            $o = OrderService::cancel($order, 'provider', (int) $u['id'], (string) $r->input('reason', ''));
            return $this->json(OrderPolicy::present(DispatchService::fresh((int) $o['id']), 'provider'));
        }
        $allowed = ['en_route_pickup', 'arrived_pickup', 'in_progress', 'completed', 'picked_up', 'delivered', 'delivery_failed', 'in_delivery', 'returning', 'returned'];
        if (!in_array($action, $allowed, true)) {
            throw HttpException::badRequest('Action inconnue.');
        }
        $o = OrderService::providerAction($order, (int) $u['id'], $action, $r->all());
        return $this->json(OrderPolicy::present(DispatchService::fresh((int) $o['id']), 'provider'));
    }

    public function declareCash(Request $r): Response
    {
        $u = $this->user();
        $order = OrderPolicy::load($r->intParam('id'), $u, ['provider']);
        $v = $this->validate($r, ['amount_collected' => 'required|int|min:0']);
        $o = PaymentService::declareCash($order, 'provider', (int) $u['id'], $v['amount_collected']);
        return $this->json(OrderPolicy::present($o, 'provider'));
    }

    public function history(Request $r): Response
    {
        [$limit, $offset] = $this->page($r, 20, 50);
        $rows = Database::select("SELECT o.* FROM orders o WHERE o.provider_id = :u ORDER BY o.id DESC LIMIT $limit OFFSET $offset", ['u' => $this->user()['id']]);
        return $this->json(array_map(static fn ($o) => OrderPolicy::present($o, 'provider', false) + [
            'commission' => (int) Database::value("SELECT commission FROM commissions WHERE order_id = :o AND party = 'provider'", ['o' => $o['id']]),
            'net' => Database::value("SELECT partner_net FROM commissions WHERE order_id = :o AND party = 'provider'", ['o' => $o['id']]),
        ], $rows));
    }

    public function earnings(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        $acc = LedgerService::partnerAccount('provider', $uid);
        $today = Database::first("SELECT COUNT(*) n, COALESCE(SUM(c.base_amount),0) gross, COALESCE(SUM(c.partner_net),0) net FROM commissions c JOIN orders o ON o.id = c.order_id
                                   WHERE c.party = 'provider' AND o.provider_id = :u AND o.settled_at >= CURDATE()", ['u' => $uid]);
        $week = Database::first("SELECT COUNT(*) n, COALESCE(SUM(c.base_amount),0) gross, COALESCE(SUM(c.partner_net),0) net FROM commissions c JOIN orders o ON o.id = c.order_id
                                  WHERE c.party = 'provider' AND o.provider_id = :u AND o.settled_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)", ['u' => $uid]);
        return $this->json([
            'summary' => FinanceService::partnerSummary('provider', $uid),
            'today' => array_map('intval', $today),
            'week' => array_map('intval', $week),
            'statement' => LedgerService::statement($acc, 100),
            'payouts' => Database::select('SELECT id, direction, amount, method, destination, status, reference, requested_at, paid_at FROM payouts WHERE partner_user_id = :u ORDER BY id DESC LIMIT 50', ['u' => $uid]),
        ]);
    }

    public function requestPayout(Request $r): Response
    {
        $v = $this->validate($r, ['amount' => 'required|int|min:1', 'method' => 'required|in:cash,mobile_money,bank_transfer', 'destination' => 'nullable|string|max:120']);
        RateLimiter::enforce('payout_req:' . $this->user()['id'], 5, 86400, 'Nombre maximal de demandes de retrait atteint pour aujourd\'hui.');
        return $this->json(FinanceService::requestPayout($this->user(), $v['amount'], $v['method'], $v['destination'] ?? null), 201);
    }
}
