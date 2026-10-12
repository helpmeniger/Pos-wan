<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Services\BusinessService;
use App\Services\DispatchService;
use App\Services\NegotiationService;
use App\Services\NotificationService;
use App\Services\OrderPolicy;
use App\Services\OrderService;
use App\Services\OrderStateMachine;
use App\Services\PaymentService;
use App\Services\RealtimeService;
use App\Services\TrackingService;

final class OrderController extends Controller
{
    public function store(Request $r): Response
    {
        $order = OrderService::create($this->user(), $r->all());
        $order = DispatchService::fresh((int) $order['id']);
        return $this->json(OrderPolicy::present($order, 'customer'), 201);
    }

    public function index(Request $r): Response
    {
        $u = $this->user();
        [$limit, $offset] = $this->page($r, 20, 50);
        $where = 'o.customer_id = :u';
        $p = ['u' => $u['id']];
        if ($r->input('scope') === 'business') {
            $biz = BusinessService::managedBy((int) $u['id']);
            if (!$biz) {
                throw HttpException::forbidden();
            }
            $where = 'o.business_id = :u';
            $p['u'] = $biz['id'];
        }
        if ($r->input('status') === 'active') {
            $where .= " AND o.status IN ('" . implode("','", OrderService::OPEN_STATUSES) . "')";
        }
        $rows = Database::select("SELECT o.* FROM orders o WHERE $where ORDER BY o.id DESC LIMIT $limit OFFSET $offset", $p);
        $role = $r->input('scope') === 'business' ? 'business' : 'customer';
        return $this->json(array_map(static fn ($o) => OrderPolicy::present($o, $role, false) + [
            'merchant_name' => $o['merchant_id'] ? Database::value('SELECT name FROM merchants WHERE id = :id', ['id' => $o['merchant_id']]) : null,
        ], $rows));
    }

    public function show(Request $r): Response
    {
        $order = OrderPolicy::load($r->intParam('id'), $this->user());
        return $this->json(OrderPolicy::present($order, $order['_viewer']));
    }

    public function cancel(Request $r): Response
    {
        $order = OrderPolicy::load($r->intParam('id'), $this->user(), ['customer', 'business']);
        $role = $order['_viewer'] === 'business' ? 'customer' : 'customer';
        $o = OrderService::cancel($order, $role, (int) $this->user()['id'], $r->input('reason') ? (string) $r->input('reason') : null);
        return $this->json(OrderPolicy::present(DispatchService::fresh((int) $o['id']), 'customer'));
    }

    public function tracking(Request $r): Response
    {
        $order = OrderPolicy::load($r->intParam('id'), $this->user(), ['customer', 'provider', 'merchant', 'admin', 'business']);
        // Opportuniste : fait avancer le moteur d'attribution si aucun worker n'est planifié.
        if (in_array($order['status'], ['searching', 'negotiating'], true) || $order['dispatch_status'] === 'searching') {
            DispatchService::tick();
            $order = DispatchService::fresh((int) $order['id']) + ['_viewer' => $order['_viewer']];
        }
        $viewer = $order['_viewer'] === 'business' ? 'customer' : $order['_viewer'];
        $data = TrackingService::forOrder($order, $viewer);
        // Le commerce ne voit que l'état du livreur affecté, pas sa position précise hors de son trajet.
        if ($viewer === 'merchant' && $data['position'] && !in_array($order['status'], ['accepted', 'preparing', 'ready'], true)) {
            $data['position'] = null;
            $data['trail'] = [];
        }
        $data['order'] = OrderPolicy::present($order, $order['_viewer'] === 'business' ? 'business' : $order['_viewer'], false);
        return $this->json($data);
    }

    public function updateOffer(Request $r): Response
    {
        $order = OrderPolicy::load($r->intParam('id'), $this->user(), ['customer']);
        $v = $this->validate($r, ['amount' => 'required|int|min:1']);
        RateLimiter::enforce('offer_update:' . $order['id'], 1, 10, 'Patientez quelques secondes avant de modifier à nouveau votre prix.');
        NegotiationService::customerUpdateOffer($order, $v['amount']);
        return $this->json(OrderPolicy::present(DispatchService::fresh((int) $order['id']), 'customer'));
    }

    public function acceptCounter(Request $r): Response
    {
        $order = OrderPolicy::load($r->intParam('id'), $this->user(), ['customer']);
        NegotiationService::customerAccept($order, $r->intParam('nid'));
        return $this->json(OrderPolicy::present(DispatchService::fresh((int) $order['id']), 'customer'));
    }

    public function rejectCounter(Request $r): Response
    {
        $order = OrderPolicy::load($r->intParam('id'), $this->user(), ['customer']);
        NegotiationService::customerReject($order, $r->intParam('nid'));
        return $this->ok('Contre-proposition refusée.');
    }

    public function pay(Request $r): Response
    {
        $order = OrderPolicy::load($r->intParam('id'), $this->user(), ['customer']);
        $key = (string) ($r->header('idempotency-key') ?? $r->input('idempotency_key', ''));
        $payment = PaymentService::initiate($order, (int) $this->user()['id'], $key);
        return $this->json(['payment_id' => (int) $payment['id'], 'uuid' => $payment['uuid'], 'status' => $payment['status'], 'amount' => (int) $payment['amount'],
            'redirect_url' => $payment['redirect_url'], 'instructions' => $payment['instructions'] ?? null]);
    }

    // ------------------------------------------------------------------ Messagerie liée à la commande

    public function messages(Request $r): Response
    {
        $order = OrderPolicy::load($r->intParam('id'), $this->user(), ['customer', 'provider', 'merchant', 'admin']);
        $rows = Database::select('SELECT m.id, m.sender_id, m.body, m.created_at, u.full_name, r.code role FROM messages m JOIN users u ON u.id = m.sender_id JOIN roles r ON r.id = u.role_id
                                   WHERE m.order_id = :o AND m.id > :s ORDER BY m.id LIMIT 200', ['o' => $order['id'], 's' => (int) $r->input('since', 0)]);
        $me = (int) $this->user()['id'];
        return $this->json(array_map(static fn ($m) => ['id' => (int) $m['id'], 'mine' => (int) $m['sender_id'] === $me, 'author' => Str::firstName($m['full_name']),
            'role' => $m['role'], 'body' => $m['body'], 'created_at' => $m['created_at']], $rows));
    }

    public function sendMessage(Request $r): Response
    {
        $u = $this->user();
        $order = OrderPolicy::load($r->intParam('id'), $u, ['customer', 'provider', 'merchant', 'admin']);
        if (in_array($order['status'], OrderStateMachine::TERMINAL, true) || ($order['completed_at'] && strtotime((string) $order['completed_at']) < time() - 86400)) {
            throw HttpException::conflict('La messagerie de cette commande est fermée.');
        }
        $v = $this->validate($r, ['body' => 'required|string|min:1|max:500']);
        RateLimiter::enforce('msg:' . $u['id'], 20, 60, 'Vous envoyez trop de messages. Patientez une minute.');
        // Filtrage : masque les numéros de téléphone et liens pour protéger la vie privée et limiter le spam.
        $body = preg_replace('/(\+?\d[\d\s.-]{7,}\d)/', '[numéro masqué]', $v['body']);
        $body = preg_replace('#https?://\S+#i', '[lien supprimé]', (string) $body);
        $flagged = $body !== $v['body'];
        $id = Database::insert('messages', ['order_id' => $order['id'], 'sender_id' => $u['id'], 'body' => $body, 'is_flagged' => $flagged ? 1 : 0]);
        foreach (array_filter([(int) $order['customer_id'], $order['provider_id'] ? (int) $order['provider_id'] : null,
            $order['merchant_id'] ? (int) Database::value('SELECT owner_user_id FROM merchants WHERE id = :m', ['m' => $order['merchant_id']]) : null]) as $uid) {
            if ($uid !== (int) $u['id']) {
                RealtimeService::push($uid, 'message', (int) $order['id'], ['order_id' => (int) $order['id'], 'reference' => $order['reference'], 'body' => mb_substr($body, 0, 80), 'author' => Str::firstName($u['full_name'])]);
            }
        }
        return $this->json(['id' => $id, 'body' => $body, 'filtered' => $flagged], 201);
    }

    // ------------------------------------------------------------------ Évaluations

    public function rate(Request $r): Response
    {
        $u = $this->user();
        $order = OrderPolicy::load($r->intParam('id'), $u, ['customer']);
        if (!in_array($order['status'], ['completed', 'delivered', 'returned', 'paid'], true)) {
            throw HttpException::conflict('Vous pourrez noter la commande une fois terminée.');
        }
        $v = $this->validate($r, [
            'provider_score' => 'nullable|int|between:1,5', 'provider_comment' => 'nullable|string|max:500',
            'merchant_score' => 'nullable|int|between:1,5', 'merchant_comment' => 'nullable|string|max:500',
            'service_score' => 'nullable|int|between:1,5', 'service_comment' => 'nullable|string|max:500',
        ]);
        $moderate = (bool) \App\Core\Settings::get('ratings.moderation', false);
        Database::transaction(static function () use ($order, $u, $v, $moderate) {
            $targets = ['provider' => $order['provider_id'], 'merchant' => $order['merchant_id'], 'service' => null];
            foreach ($targets as $type => $targetId) {
                if (empty($v[$type . '_score']) || ($type !== 'service' && !$targetId)) {
                    continue;
                }
                $n = Database::execute('INSERT IGNORE INTO ratings (order_id, rater_id, target_type, target_id, score, comment, status) VALUES (:o, :r, :t, :ti, :s, :c, :st)',
                    ['o' => $order['id'], 'r' => $u['id'], 't' => $type, 'ti' => $targetId, 's' => $v[$type . '_score'], 'c' => $v[$type . '_comment'] ?? null,
                     'st' => $moderate && !empty($v[$type . '_comment']) ? 'pending' : 'published']);
                if ($n && $type === 'provider') {
                    Database::execute('UPDATE provider_profiles SET rating_avg = (rating_avg * rating_count + :s) / (rating_count + 1), rating_count = rating_count + 1 WHERE user_id = :p', ['s' => $v['provider_score'], 'p' => $targetId]);
                }
                if ($n && $type === 'merchant') {
                    Database::execute('UPDATE merchants SET rating_avg = (rating_avg * rating_count + :s) / (rating_count + 1), rating_count = rating_count + 1 WHERE id = :m', ['s' => $v['merchant_score'], 'm' => $targetId]);
                }
            }
            Database::execute('UPDATE orders SET rated_at = NOW() WHERE id = :id', ['id' => $order['id']]);
        });
        return $this->ok('Merci pour votre évaluation !');
    }

    public function receipt(Request $r): Response
    {
        $order = OrderPolicy::load($r->intParam('id'), $this->user(), ['customer', 'business', 'admin']);
        $inv = Database::first("SELECT * FROM invoices WHERE kind = 'receipt' AND order_id = :o", ['o' => $order['id']]);
        if (!$inv) {
            throw HttpException::notFound('Le reçu sera disponible après confirmation du paiement.');
        }
        $data = OrderPolicy::present($order, $order['_viewer']);
        return \App\Core\Response::html(\App\Core\View::render('pages/receipt', ['order' => $data, 'invoice' => $inv, 'title' => 'Reçu ' . $inv['number']], null));
    }
}
