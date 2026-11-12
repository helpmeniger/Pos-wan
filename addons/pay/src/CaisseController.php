<?php
declare(strict_types=1);

namespace Addons\Pay;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;

/** Points d'accès JSON utilisés par l'écran de caisse (« Paiement électronique »). */
final class CaisseController extends Controller
{
    /** Données de transaction exposées à la caisse (aucun secret, aucune preuve brute). */
    private static function public(array $t): array
    {
        $m = DB::one('SELECT label, sale_method FROM payment_methods WHERE id = ?', [$t['method_id']]);
        return ['id' => (int) $t['id'], 'number' => $t['number'], 'status' => $t['status'], 'status_label' => t(PayEngine::STATUSES[$t['status']]), 'message' => $t['message'],
            'amount' => (float) $t['amount'], 'currency' => $t['currency'], 'provider_amount' => (float) $t['provider_amount'], 'provider_currency' => $t['provider_currency'],
            'sale_method' => $m['sale_method'] ?? 'card', 'label' => ($m['label'] ?? '') . ((int) $t['is_test'] ? ' (TEST)' : ''), 'test' => (int) $t['is_test'] === 1,
            'reference' => ((int) $t['is_test'] ? 'TEST ' : '') . ($t['provider_reference'] ?: ($t['authorization_code'] ?: $t['number'])),
            'redirect_url' => in_array($t['status'], ['pending', 'processing'], true) ? $t['redirect_url'] : null, 'payer_hint' => $t['payer_hint'],
            'expires_at' => $t['expires_at'], 'usable' => $t['status'] === 'succeeded' || ($t['status'] === 'to_verify' && $t['driver'] === 'terminal_standalone' && $t['authorization_code'])];
    }

    /** Transaction de la caisse de l'utilisateur uniquement. */
    private function own(int $id): array
    {
        $t = DB::one('SELECT * FROM payment_transactions WHERE id = ?', [$id]);
        $u = Auth::user();
        if (!$t || ((int) $t['register_id'] !== (int) ($u['register_id'] ?? 0) && (int) $t['user_id'] !== (int) $u['id'])) {
            throw new HttpException(404);
        }
        return $t;
    }

    public function methods(): void
    {
        $u = Auth::user();
        $reg = $u['register_id'] ? DB::one('SELECT id, store_id FROM cash_registers WHERE id = ?', [$u['register_id']]) : null;
        json_response(['ok' => true, 'methods' => $reg ? PayEngine::methodsFor((int) $reg['id'], (int) $reg['store_id']) : [], 'currency' => PayEngine::shopCurrency()]);
    }

    public function initiate(): void
    {
        $in = Request::json();
        $this->jsonAttempt(fn() => ['transaction' => self::public(PayEngine::initiate([
            'method_id' => (int) ($in['method_id'] ?? 0), 'amount' => (string) ($in['amount'] ?? ''), 'cart_uuid' => (string) ($in['cart_uuid'] ?? ''), 'request_key' => (string) ($in['request_key'] ?? ''),
            'payer_phone' => (string) ($in['payer_phone'] ?? ''), 'customer_id' => (int) ($in['customer_id'] ?? 0) ?: null,
            'authorization_code' => isset($in['authorization_code']) ? (string) $in['authorization_code'] : null]))]);
    }

    public function status(int $id): void
    {
        $t = $this->own($id);
        $this->jsonAttempt(fn() => ['transaction' => self::public(in_array($t['status'], ['pending', 'processing'], true) ? PayEngine::refresh($id) : $t)]);
    }

    public function cancel(int $id): void
    {
        $t = $this->own($id);
        if ($t['sale_id'] !== null) {
            throw new BusinessException('Paiement déjà rattaché à une vente.');
        }
        $this->jsonAttempt(fn() => ['transaction' => self::public(PayEngine::cancel($id))]);
    }
}
