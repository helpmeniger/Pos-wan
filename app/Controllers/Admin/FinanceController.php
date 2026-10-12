<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Api\ExportResponder;
use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\DispatchService;
use App\Services\FinanceService;
use App\Services\LedgerService;
use App\Services\PaymentService;
use App\Services\ReportService;

/** Finance : paiements, rapprochements, remboursements, versements, grand livre, dépenses, rapports. */
final class FinanceController extends Controller
{
    public function payments(Request $r): Response
    {
        [$limit, $offset] = $this->page($r, 100, 500);
        $w = ['1=1'];
        $p = [];
        foreach (['status', 'method'] as $k) {
            if ($v = $r->input($k)) {
                $w[] = "pm.$k = :$k";
                $p[$k] = (string) $v;
            }
        }
        if ($r->input('discrepancy')) {
            $w[] = 'pm.declared_amount IS NOT NULL AND pm.declared_amount <> pm.amount';
        }
        return $this->json(Database::select('SELECT pm.*, o.reference, o.service_type FROM payments pm JOIN orders o ON o.id = pm.order_id WHERE ' . implode(' AND ', $w) . " ORDER BY pm.id DESC LIMIT $limit OFFSET $offset", $p));
    }

    public function verifyCash(Request $r): Response
    {
        $v = $this->validate($r, ['verified_amount' => 'required|int|min:0', 'absorb_difference' => 'nullable|bool']);
        return $this->json(PaymentService::verifyCash($r->intParam('id'), $v['verified_amount'], !empty($v['absorb_difference']), (int) Auth::id()));
    }

    /** Détection des opérations en double et incohérences (rapprochement). */
    public function reconciliation(Request $r): Response
    {
        return $this->json([
            'duplicate_payments' => Database::select("SELECT order_id, COUNT(*) n, SUM(amount) total FROM payments WHERE status IN ('declared','confirmed') GROUP BY order_id HAVING n > 1"),
            'cash_discrepancies' => Database::select("SELECT pm.id, o.reference, pm.amount, pm.declared_amount, pm.verified_amount, pm.status FROM payments pm JOIN orders o ON o.id = pm.order_id
                                                       WHERE pm.method = 'cash' AND (pm.declared_amount <> pm.amount OR (pm.verified_amount IS NOT NULL AND pm.verified_amount <> pm.declared_amount)) ORDER BY pm.id DESC LIMIT 200"),
            'unverified_cash' => Database::first("SELECT COUNT(*) n, COALESCE(SUM(declared_amount),0) amount FROM payments WHERE method = 'cash' AND status = 'declared'"),
            'paid_not_settled' => Database::select("SELECT id, reference, status, payment_status FROM orders WHERE payment_status = 'paid' AND settled_at IS NULL AND status IN ('completed','delivered','returned')"),
            'invalid_webhooks' => Database::select('SELECT gateway, event_id, received_at FROM payment_webhooks WHERE signature_valid = 0 ORDER BY id DESC LIMIT 50'),
            'trial_balance' => LedgerService::trialBalance(),
        ]);
    }

    public function refunds(Request $r): Response
    {
        return $this->json(Database::select('SELECT rf.*, o.reference, u.full_name requested_by_name FROM refunds rf JOIN orders o ON o.id = rf.order_id JOIN users u ON u.id = rf.requested_by ORDER BY rf.id DESC LIMIT 300'));
    }

    public function createRefund(Request $r): Response
    {
        $v = $this->validate($r, ['order_id' => 'required|int', 'amount' => 'required|int|min:1', 'reason' => 'required|string|min:3|max:255', 'borne_by' => 'required|in:marwa,provider,merchant', 'complaint_id' => 'nullable|int']);
        $order = DispatchService::fresh($v['order_id']);
        $execute = Auth::can('refunds.manage');
        return $this->json(FinanceService::requestRefund($order, $v['amount'], $v['reason'], $v['borne_by'], (int) Auth::id(), $execute, $v['complaint_id'] ?? null), 201);
    }

    public function reviewRefund(Request $r): Response
    {
        $decision = (string) $r->input('decision');
        if ($decision === 'approve') {
            return $this->json(FinanceService::approveRefund($r->intParam('id'), (int) Auth::id()));
        }
        if ($decision === 'reject') {
            FinanceService::rejectRefund($r->intParam('id'), (int) Auth::id(), (string) $r->input('note', ''));
            return $this->ok('Remboursement refusé.');
        }
        throw HttpException::badRequest('Décision inconnue.');
    }

    public function payouts(Request $r): Response
    {
        $s = $r->input('status');
        return $this->json(Database::select('SELECT po.*, u.full_name, u.phone FROM payouts po JOIN users u ON u.id = po.partner_user_id ' . ($s ? 'WHERE po.status = :s ' : '') . 'ORDER BY po.id DESC LIMIT 300', $s ? ['s' => (string) $s] : []));
    }

    public function reviewPayout(Request $r): Response
    {
        $v = $this->validate($r, ['decision' => 'required|in:approve,pay,reject', 'reference' => 'nullable|string|max:120', 'note' => 'nullable|string|max:255']);
        return $this->json(FinanceService::reviewPayout($r->intParam('id'), $v['decision'], (int) Auth::id(), $v['reference'] ?? null, $v['note'] ?? null));
    }

    public function remittance(Request $r): Response
    {
        $v = $this->validate($r, ['partner_type' => 'required|in:provider,merchant', 'owner_id' => 'required|int', 'amount' => 'required|int|min:1', 'method' => 'required|in:cash,mobile_money,bank_transfer', 'reference' => 'required|string|max:120']);
        return $this->json(FinanceService::recordRemittance($v['partner_type'], $v['owner_id'], $v['amount'], $v['method'], $v['reference'], (int) Auth::id()), 201);
    }

    public function businessPayment(Request $r): Response
    {
        $v = $this->validate($r, ['amount' => 'required|int|min:1', 'reference' => 'required|string|max:120']);
        FinanceService::recordBusinessPayment($r->intParam('id'), $v['amount'], $v['reference'], (int) Auth::id());
        return $this->ok('Règlement enregistré.');
    }

    public function balances(Request $r): Response
    {
        return $this->json(Database::select('SELECT pb.*, CASE pb.owner_type WHEN \'merchant\' THEN m.owner_user_id ELSE pb.owner_id END user_id
                                               FROM partner_balances pb LEFT JOIN merchants m ON m.id = pb.owner_id AND pb.owner_type = \'merchant\' ORDER BY pb.balance'));
    }

    public function ledger(Request $r): Response
    {
        $accounts = Database::select('SELECT a.id, a.code, a.name, a.type, a.owner_type, COALESCE(SUM(e.debit),0) debit, COALESCE(SUM(e.credit),0) credit
                                        FROM ledger_accounts a LEFT JOIN ledger_entries e ON e.account_id = a.id WHERE a.owner_type = \'system\' GROUP BY a.id ORDER BY a.code');
        $txns = Database::select('SELECT t.id, t.kind, t.description, t.occurred_at, t.reverses_id, o.reference, u.full_name created_by_name,
                                         (SELECT SUM(debit) FROM ledger_entries e WHERE e.transaction_id = t.id) amount
                                    FROM ledger_transactions t LEFT JOIN orders o ON o.id = t.order_id LEFT JOIN users u ON u.id = t.created_by ORDER BY t.id DESC LIMIT 100');
        return $this->json(['accounts' => $accounts, 'transactions' => $txns, 'trial_balance' => LedgerService::trialBalance()]);
    }

    public function transaction(Request $r): Response
    {
        $id = $r->intParam('id');
        return $this->json(['transaction' => Database::first('SELECT * FROM ledger_transactions WHERE id = :id', ['id' => $id]),
            'entries' => Database::select('SELECT e.*, a.code, a.name FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id WHERE e.transaction_id = :id', ['id' => $id])]);
    }

    public function accountStatement(Request $r): Response
    {
        $id = $r->intParam('id');
        return $this->json(['account' => Database::first('SELECT * FROM ledger_accounts WHERE id = :id', ['id' => $id]), 'balance' => LedgerService::balance($id), 'entries' => LedgerService::statement($id, 500)]);
    }

    public function adjustment(Request $r): Response
    {
        $v = $this->validate($r, ['debit_account_id' => 'required|int', 'credit_account_id' => 'required|int', 'amount' => 'required|int|min:1', 'description' => 'required|string|min:5|max:200']);
        if ($v['debit_account_id'] === $v['credit_account_id']) {
            throw \App\Core\ValidationException::field('credit_account_id', 'Les comptes doivent être différents.');
        }
        return $this->json(['transaction_id' => FinanceService::adjustment($v['debit_account_id'], $v['credit_account_id'], $v['amount'], $v['description'], (int) Auth::id())], 201);
    }

    public function reverse(Request $r): Response
    {
        $v = $this->validate($r, ['reason' => 'required|string|min:5|max:200']);
        $id = LedgerService::reverse($r->intParam('id'), $v['reason'], (int) Auth::id());
        \App\Core\Audit::log('ledger.reversed', 'ledger_transaction', $r->intParam('id'), null, ['reversal_id' => $id, 'reason' => $v['reason']]);
        return $this->json(['transaction_id' => $id], 201);
    }

    public function expenses(Request $r): Response
    {
        return $this->json(Database::select('SELECT e.*, c.name city, u.full_name created_by_name FROM expenses e LEFT JOIN cities c ON c.id = e.city_id JOIN users u ON u.id = e.created_by ORDER BY e.expense_date DESC, e.id DESC LIMIT 500'));
    }

    public function createExpense(Request $r): Response
    {
        $v = $this->validate($r, ['category' => 'required|string|max:60', 'description' => 'required|string|max:255', 'amount' => 'required|int|min:1',
            'expense_date' => 'required|date', 'city_id' => 'nullable|int', 'paid_from' => 'nullable|in:cash,bank,mobile_money']);
        return $this->json(FinanceService::recordExpense($v, (int) Auth::id()), 201);
    }

    public function subscription(Request $r): Response
    {
        $v = $this->validate($r, ['partner_type' => 'required|in:provider,merchant,business', 'owner_id' => 'required|int', 'plan_name' => 'required|string|max:80', 'amount' => 'required|int|min:1',
            'period_start' => 'required|date', 'period_end' => 'required|date']);
        return $this->json(FinanceService::billSubscription($v['partner_type'], $v['owner_id'], $v['plan_name'], $v['amount'], $v['period_start'], $v['period_end'], (int) Auth::id()), 201);
    }

    public function report(Request $r): Response
    {
        $type = (string) $r->param('type');
        $f = ReportService::filters($r->query);
        $report = ReportService::run($type, $f);
        $format = (string) $r->input('format', 'json');
        if ($format !== 'json') {
            if (!Auth::can('reports.export')) {
                throw HttpException::forbidden('Permission requise : reports.export');
            }
            \App\Core\Audit::log('report.exported', 'report', null, null, ['type' => $type, 'format' => $format, 'from' => $f['from'], 'to' => $f['to']]);
            return ExportResponder::respond($report, $format, $type . '-' . $f['from'] . '-' . $f['to']);
        }
        return $this->json($report);
    }
}
