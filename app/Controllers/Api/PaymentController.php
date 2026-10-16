<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Payment\DemoGateway;
use App\Services\PaymentService;

final class PaymentController extends Controller
{
    /** Point de réception des notifications des prestataires de paiement (signature obligatoire). */
    public function webhook(Request $r): Response
    {
        $result = PaymentService::handleWebhook((string) $r->param('gateway'), $r->rawBody, $r->headers);
        return Response::json(['received' => true, 'result' => $result['status'] ?? null]);
    }

    /**
     * Simulateur de la passerelle de DÉMONSTRATION : produit une notification signée
     * identique à celle d'un prestataire, traitée par le même code que les vrais webhooks.
     */
    public function simulateDemo(Request $r): Response
    {
        if (!PaymentService::demoEnabled()) {
            throw HttpException::notFound();
        }
        $payment = Database::first("SELECT * FROM payments WHERE uuid = :u AND gateway = 'demo'", ['u' => (string) $r->param('uuid')]);
        if (!$payment || (int) $payment['payer_user_id'] !== (int) $this->user()['id']) {
            throw HttpException::notFound('Paiement introuvable.');
        }
        $success = $r->input('outcome') === 'success';
        [$body, $sig] = DemoGateway::buildWebhook((string) $payment['external_ref'], (int) $payment['amount'], $success);
        $result = PaymentService::handleWebhook('demo', $body, ['x-marwa-signature' => $sig]);
        return $this->json(['status' => $result['status'] ?? 'unknown', 'order_id' => (int) $payment['order_id']]);
    }
}
