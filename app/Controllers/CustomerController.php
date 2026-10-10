<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;
use App\Services\CustomerService;

final class CustomerController extends Controller
{
    private const RULES = ['name' => 'required|max:150', 'phone' => 'nullable|phone', 'email' => 'nullable|email|max:150', 'address' => 'nullable|max:255', 'nif' => 'nullable|max:50', 'credit_limit' => 'nullable|numeric|min:0|max:1000000000', 'notes' => 'nullable|max:2000'];
    private const LABELS = ['name' => 'Nom', 'phone' => 'Téléphone', 'email' => 'E-mail', 'credit_limit' => 'Plafond de crédit'];

    public function index(): void
    {
        $q = trim((string) Request::query('q', ''));
        $where = 'c.active = 1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (c.name LIKE ? OR c.phone LIKE ?)';
            array_push($params, "%$q%", "%$q%");
        }
        if (Request::query('debt') === '1') {
            $where .= ' AND c.balance > 0';
        }
        $p = paginate("FROM customers c WHERE $where", $params, "c.*, (SELECT MAX(created_at) FROM sales WHERE customer_id = c.id) AS last_sale, (SELECT MIN(due_date) FROM customer_transactions WHERE customer_id = c.id AND type = 'credit_sale' AND due_date IS NOT NULL) AS first_due", 'c.name');
        view('customers/index', ['title' => 'Clients & crédits', 'p' => $p, 'q' => $q, 'total' => (float) DB::value('SELECT COALESCE(SUM(balance),0) FROM customers WHERE balance > 0')]);
    }

    public function create(): void
    {
        view('customers/form', ['title' => 'Nouveau client', 'c' => []]);
    }

    private function data(string $back): array
    {
        $v = Validator::make($_POST, self::RULES, self::LABELS);
        if ($v->fails()) {
            $this->fail($v->firstError(), $back);
        }
        $d = $v->data;
        // Seul un responsable habilité au crédit fixe le plafond
        if (!can('pos.credit_sale')) {
            unset($d['credit_limit']);
        } else {
            $d['credit_limit'] ??= 0;
        }
        return $d;
    }

    public function store(): void
    {
        $id = DB::insert('customers', $this->data('/customers/create'));
        Audit::log('customer.create', 'customer', $id);
        flash('success', 'Client créé.');
        redirect('/customers/' . $id);
    }

    public function edit(int $id): void
    {
        view('customers/form', ['title' => 'Modifier le client', 'c' => $this->findOr404('customers', $id)]);
    }

    public function update(int $id): void
    {
        $old = $this->findOr404('customers', $id);
        $d = $this->data('/customers/' . $id . '/edit');
        if (Request::input('archive') === '1') {
            if ((float) $old['balance'] != 0.0) {
                $this->fail('Impossible d\'archiver un client dont le solde n\'est pas nul.', '/customers/' . $id . '/edit');
            }
            $d['active'] = 0;
        }
        DB::update('customers', $d, 'id = ?', [$id]);
        Audit::log('customer.update', 'customer', $id, isset($d['credit_limit']) && (float) $d['credit_limit'] !== (float) $old['credit_limit'] ? ['credit_limit' => [$old['credit_limit'], $d['credit_limit']]] : null);
        flash('success', 'Client mis à jour.');
        redirect('/customers/' . $id);
    }

    public function show(int $id): void
    {
        $c = $this->findOr404('customers', $id);
        view('customers/show', [
            'title' => $c['name'], 'c' => $c,
            'ledger' => DB::all('SELECT t.*, u.full_name FROM customer_transactions t LEFT JOIN users u ON u.id = t.user_id WHERE t.customer_id = ? ORDER BY t.id DESC LIMIT 100', [$id]),
            'sales' => DB::all('SELECT id, number, total, credit_amount, status, created_at FROM sales WHERE customer_id = ? ORDER BY id DESC LIMIT 30', [$id]),
            'stats' => DB::one("SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS total, MAX(created_at) AS last FROM sales WHERE customer_id = ? AND status <> 'cancelled'", [$id]),
        ]);
    }

    public function payment(int $id): void
    {
        $this->findOr404('customers', $id);
        $type = Request::str('type') === 'deposit' ? 'deposit' : 'payment';
        $txId = $this->attempt(fn() => CustomerService::payment($id, Request::num('amount'), Request::str('method'), Request::str('note') ?: null, $type), '/customers/' . $id);
        flash('success', 'Règlement enregistré.');
        redirect('/customer-payments/' . $txId);
    }

    public function receipt(int $id): void
    {
        $t = DB::one('SELECT t.*, c.name, c.phone, u.full_name FROM customer_transactions t JOIN customers c ON c.id = t.customer_id LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?', [$id]);
        if (!$t) {
            throw new HttpException(404);
        }
        view('customers/receipt', ['title' => 'Reçu de paiement', 't' => $t, 'bodyClass' => setting('ticket_width', '80') === '58' ? 't58' : 't80', 'backUrl' => url('/customers/' . $t['customer_id'])], 'layouts/print');
    }

    public function statement(int $id): void
    {
        $c = $this->findOr404('customers', $id);
        [$from, $to] = date_range('-90 days');
        $opening = (float) (DB::value('SELECT balance_after FROM customer_transactions WHERE customer_id = ? AND created_at < ? ORDER BY id DESC LIMIT 1', [$id, $from . ' 00:00:00']) ?? 0);
        view('customers/statement', ['title' => 'Relevé de compte', 'c' => $c, 'from' => $from, 'to' => $to, 'opening' => $opening, 'bodyClass' => 'a4', 'backUrl' => url('/customers/' . $id),
            'rows' => DB::all('SELECT * FROM customer_transactions WHERE customer_id = ? AND created_at BETWEEN ? AND ? ORDER BY id', [$id, $from . ' 00:00:00', $to . ' 23:59:59'])], 'layouts/print');
    }
}
