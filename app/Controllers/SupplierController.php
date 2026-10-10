<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Request;
use App\Core\Validator;
use App\Services\SupplierService;

final class SupplierController extends Controller
{
    private const RULES = ['name' => 'required|max:150', 'contact_name' => 'nullable|max:150', 'phone' => 'nullable|phone', 'email' => 'nullable|email|max:150', 'address' => 'nullable|max:255', 'nif' => 'nullable|max:50', 'notes' => 'nullable|max:2000'];
    private const LABELS = ['name' => 'Nom', 'phone' => 'Téléphone', 'email' => 'E-mail'];

    public function index(): void
    {
        $q = trim((string) Request::query('q', ''));
        $p = paginate('FROM suppliers s WHERE s.active = 1' . ($q !== '' ? ' AND (s.name LIKE ? OR s.phone LIKE ?)' : ''), $q !== '' ? ["%$q%", "%$q%"] : [],
            's.*, (SELECT MAX(created_at) FROM receptions WHERE supplier_id = s.id) AS last_reception', 's.name');
        view('suppliers/index', ['title' => 'Fournisseurs', 'p' => $p, 'q' => $q, 'debt' => (float) DB::value('SELECT COALESCE(SUM(balance),0) FROM suppliers WHERE balance > 0')]);
    }

    public function create(): void
    {
        view('suppliers/form', ['title' => 'Nouveau fournisseur', 's' => []]);
    }

    public function store(): void
    {
        $v = Validator::make($_POST, self::RULES, self::LABELS);
        if ($v->fails()) {
            $this->fail($v->firstError(), '/suppliers/create');
        }
        $id = DB::insert('suppliers', $v->data);
        Audit::log('supplier.create', 'supplier', $id);
        flash('success', 'Fournisseur créé.');
        redirect('/suppliers/' . $id);
    }

    public function edit(int $id): void
    {
        view('suppliers/form', ['title' => 'Modifier le fournisseur', 's' => $this->findOr404('suppliers', $id)]);
    }

    public function update(int $id): void
    {
        $this->findOr404('suppliers', $id);
        $v = Validator::make($_POST, self::RULES, self::LABELS);
        if ($v->fails()) {
            $this->fail($v->firstError(), '/suppliers/' . $id . '/edit');
        }
        $d = $v->data + ['active' => Request::input('archive') === '1' ? 0 : 1];
        DB::update('suppliers', $d, 'id = ?', [$id]);
        Audit::log('supplier.update', 'supplier', $id);
        flash('success', 'Fournisseur mis à jour.');
        redirect('/suppliers/' . $id);
    }

    public function show(int $id): void
    {
        $s = $this->findOr404('suppliers', $id);
        view('suppliers/show', [
            'title' => $s['name'], 's' => $s,
            'ledger' => DB::all('SELECT t.*, u.full_name FROM supplier_transactions t LEFT JOIN users u ON u.id = t.user_id WHERE t.supplier_id = ? ORDER BY t.id DESC LIMIT 100', [$id]),
            'orders' => DB::all('SELECT * FROM purchase_orders WHERE supplier_id = ? ORDER BY id DESC LIMIT 20', [$id]),
            'receptions' => DB::all('SELECT * FROM receptions WHERE supplier_id = ? ORDER BY id DESC LIMIT 20', [$id]),
            'due' => DB::all("SELECT * FROM supplier_transactions WHERE supplier_id = ? AND type = 'invoice' AND due_date IS NOT NULL ORDER BY due_date DESC LIMIT 10", [$id]),
        ]);
    }

    public function pay(int $id): void
    {
        $this->findOr404('suppliers', $id);
        $method = Request::str('method');
        if (!isset(payment_methods()[$method])) {
            $this->fail('Mode de paiement invalide.', '/suppliers/' . $id);
        }
        $this->attempt(fn() => SupplierService::pay($id, Request::num('amount'), $method, Request::str('note') ?: null, Request::input('from_register') === '1'), '/suppliers/' . $id);
        flash('success', 'Paiement fournisseur enregistré.');
        redirect('/suppliers/' . $id);
    }
}
