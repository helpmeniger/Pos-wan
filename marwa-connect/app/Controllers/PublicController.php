<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\OnlineOrderService;
use App\Services\RateLimiter;
use Throwable;

/**
 * Pages publiques : achat de forfait en ligne et réception des webhooks de paiement.
 */
final class PublicController extends Controller
{
    public function buy(Request $request): Response
    {
        $offers = Database::fetchAll(
            "SELECT DISTINCT o.* FROM offers o JOIN offer_router orr ON orr.offer_id = o.id JOIN routers r ON r.id = orr.router_id AND r.status = 'active' AND r.deleted_at IS NULL
              WHERE o.status = 'active' AND o.is_public = 1 ORDER BY o.sort_order, o.price");
        $routers = Database::fetchAll(
            "SELECT DISTINCT r.id, r.name, r.location FROM routers r JOIN offer_router orr ON orr.router_id = r.id JOIN offers o ON o.id = orr.offer_id AND o.is_public = 1 AND o.status = 'active'
              WHERE r.status = 'active' AND r.deleted_at IS NULL ORDER BY r.name");
        $methods = Database::fetchAll('SELECT id, name, merchant_number, instructions FROM payment_methods WHERE is_active = 1 AND available_online = 1 ORDER BY sort_order');
        return $this->view('public/buy', compact('offers', 'routers', 'methods'), 'layouts/public');
    }

    public function order(Request $request): Response
    {
        if (!RateLimiter::hit('buy:' . $request->ip(), 10, 3600)) {
            Session::flash('danger', 'Trop de tentatives. Réessayez plus tard.');
            return $this->redirect('/buy');
        }
        $phone = preg_replace('/[^0-9+]/', '', $request->str('phone'));
        if (strlen((string) $phone) < 8) {
            Session::flash('danger', 'Numéro de téléphone invalide.');
            return $this->redirect('/buy');
        }
        try {
            $o = OnlineOrderService::create($request->int('offer_id'), $request->int('router_id'), $request->int('payment_method_id'), (string) $phone, $request->ip());
        } catch (Throwable $e) {
            Session::flash('danger', $e->getMessage());
            return $this->redirect('/buy');
        }
        return $this->redirect('/buy/order/' . $o['reference'] . '?t=' . $o['token']);
    }

    public function status(Request $request, string $reference): Response
    {
        $order = OnlineOrderService::findByToken($reference, $request->str('t'));
        $this->notFoundUnless($order, 'Commande introuvable.');
        if ($request->isAjax() || $request->str('format') === 'json') {
            return $this->json(['status' => $order['status'], 'delivered' => $order['status'] === 'delivered']);
        }
        return $this->view('public/order', ['order' => $order, 'token' => $request->str('t')], 'layouts/public');
    }

    public function webhook(Request $request, string $provider): Response
    {
        $body = (string) file_get_contents('php://input');
        $headers = array_filter(['content-type' => $request->header('Content-Type'), 'user-agent' => $request->userAgent()]);
        [$code, $msg] = OnlineOrderService::handleWebhook(preg_replace('/[^a-z0-9_]/', '', strtolower($provider)), $body, $request->header('X-Signature'), $request->ip(), $headers);
        return $this->json(['success' => $code === 200, 'message' => $msg], $code);
    }
}
