<?php
declare(strict_types=1);

namespace App\Controllers\Saas;

use App\Controllers\Controller;
use App\Core\BusinessException;
use App\Core\HttpException;
use App\Saas\Billing;
use App\Saas\Central;
use App\Saas\Saas;
use App\Saas\Subscription;

/** Espace client → Mon abonnement : offre, échéance, limites, déclaration de paiement, factures. */
final class SubscriptionController extends Controller
{
    public function index(): void
    {
        $t = Saas::refreshTenant();
        view('saas/tenant/subscription', [
            'title' => 'Mon abonnement', 't' => $t, 'sub' => Subscription::state($t), 'plan' => Subscription::plan($t), 'usage' => Subscription::usage(),
            'plans' => Central::all('SELECT * FROM saas_plans WHERE active = 1 AND public = 1 ORDER BY sort, price_month'),
            'payments' => Central::all('SELECT p.*, pl.name AS plan_name FROM saas_payments p JOIN saas_plans pl ON pl.id = p.plan_id WHERE p.tenant_id = ? ORDER BY p.id DESC LIMIT 50', [$t['id']]),
            'instructions' => (string) Central::setting('payment_instructions', ''), 'operator' => PublicController::operator(),
            'methods' => Subscription::METHODS, 'addonNames' => PublicController::addonNames(),
        ]);
    }

    public function declare(): void
    {
        $t = Saas::tenant();
        try {
            Billing::declare((int) $t['id'], $_POST, (string) ($this->user()['full_name'] ?? $this->user()['username']));
        } catch (BusinessException $e) {
            $this->fail($e->getMessage(), '/abonnement');
        }
        \App\Core\Audit::log('saas.payment_declared', null, null, ['amount' => (float) ($_POST['amount'] ?? 0), 'reference' => (string) ($_POST['reference'] ?? '')]);
        flash('success', 'Paiement déclaré. Il sera vérifié par le service ; votre abonnement sera prolongé dès la validation.');
        redirect('/abonnement');
    }

    public function invoice(int $id): void
    {
        // Uniquement les factures de CET espace
        $p = Central::one("SELECT * FROM saas_payments WHERE id = ? AND tenant_id = ? AND status = 'validated'", [$id, Saas::tenant()['id']]) ?? throw new HttpException(404);
        ConsoleController::sendPdf(Billing::invoicePdf($p), 'Facture-' . $p['invoice_no'] . '.pdf');
    }
}
