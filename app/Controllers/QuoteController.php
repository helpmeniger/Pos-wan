<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Documents;
use App\Services\QuoteService;

final class QuoteController extends Controller
{
    private function quote(int $id): array
    {
        $q = DB::one('SELECT q.*, u.full_name AS user_name, s.number AS sale_number, c.name AS customer FROM quotes q JOIN users u ON u.id = q.user_id
                LEFT JOIN sales s ON s.id = q.sale_id LEFT JOIN customers c ON c.id = q.customer_id WHERE q.id = ?', [$id]);
        if (!$q) {
            throw new HttpException(404);
        }
        return $q;
    }

    private function items(int $id): array
    {
        return DB::all('SELECT qi.*, p.reference, p.tax_rate, p.vat_exempt, un.short_name AS unit FROM quote_items qi JOIN products p ON p.id = qi.product_id LEFT JOIN units un ON un.id = p.unit_id WHERE qi.quote_id = ? ORDER BY qi.id', [$id]);
    }

    public function index(): void
    {
        $where = ['1=1'];
        $params = [];
        if (($st = Request::query('status')) && isset(QuoteService::STATUSES[$st])) {
            $where[] = 'q.status = ?';
            $params[] = $st;
        }
        if ($term = trim((string) Request::query('q', ''))) {
            $where[] = '(q.number LIKE ? OR q.customer_name LIKE ?)';
            $params[] = '%' . $term . '%';
            $params[] = '%' . $term . '%';
        }
        $p = paginate('FROM quotes q JOIN users u ON u.id = q.user_id WHERE ' . implode(' AND ', $where), $params, 'q.*, u.full_name AS user_name', 'q.id DESC');
        view('quotes/index', ['title' => 'Devis', 'p' => $p]);
    }

    public function create(): void
    {
        $q = ['id' => null, 'customer_id' => null, 'customer_name' => '', 'customer_phone' => '', 'customer_address' => '', 'note' => '',
            'valid_until' => date('Y-m-d', strtotime('+' . max(1, (int) setting('quote_validity_days', '15')) . ' days'))];
        if ($cid = Request::int('customer')) {
            $c = DB::one('SELECT id, name, phone, address FROM customers WHERE id = ?', [$cid]);
            if ($c) {
                $q = array_merge($q, ['customer_id' => $c['id'], 'customer_name' => $c['name'], 'customer_phone' => $c['phone'], 'customer_address' => $c['address']]);
            }
        }
        view('quotes/form', ['title' => 'Nouveau devis', 'q' => $q, 'items' => [], 'customers' => $this->customers()]);
    }

    private function customers(): array
    {
        return DB::all('SELECT id, name, phone FROM customers WHERE active = 1 ORDER BY name LIMIT 1000');
    }

    public function store(): void
    {
        $id = $this->attempt(fn() => QuoteService::create($_POST, $this->rowsFromForm()), '/quotes/create');
        flash('success', 'Devis enregistré. Il n\'affecte ni le stock ni la caisse.');
        redirect('/quotes/' . $id);
    }

    public function show(int $id): void
    {
        $q = $this->quote($id);
        view('quotes/show', ['title' => 'Devis ' . $q['number'], 'q' => $q, 'items' => $this->items($id), 'statuses' => QuoteService::STATUSES]);
    }

    public function edit(int $id): void
    {
        $q = $this->quote($id);
        if (!in_array($q['status'], ['draft', 'sent', 'accepted'], true)) {
            $this->fail('Ce devis ne peut plus être modifié.', '/quotes/' . $id);
        }
        view('quotes/form', ['title' => 'Modifier le devis ' . $q['number'], 'q' => $q, 'items' => $this->items($id), 'customers' => $this->customers()]);
    }

    public function update(int $id): void
    {
        $this->quote($id);
        $this->attempt(fn() => QuoteService::update($id, $_POST, $this->rowsFromForm()), '/quotes/' . $id . '/edit');
        flash('success', 'Devis mis à jour.');
        redirect('/quotes/' . $id);
    }

    public function status(int $id): void
    {
        $this->quote($id);
        $this->attempt(fn() => QuoteService::setStatus($id, Request::str('status')), '/quotes/' . $id);
        flash('success', 'Statut du devis mis à jour.');
        redirect('/quotes/' . $id);
    }

    public function duplicate(int $id): void
    {
        $q = $this->quote($id);
        $rows = array_map(fn($i) => ['product_id' => $i['product_id'], 'quantity' => $i['quantity'], 'unit_price' => $i['unit_price'], 'discount' => $i['discount']], $this->items($id));
        $new = $this->attempt(fn() => QuoteService::create(['customer_id' => $q['customer_id'], 'customer_name' => $q['customer_name'], 'customer_phone' => $q['customer_phone'], 'customer_address' => $q['customer_address'], 'note' => $q['note']], $rows), '/quotes/' . $id);
        flash('success', 'Copie du devis créée avec une nouvelle date de validité.');
        redirect('/quotes/' . $new);
    }

    public function print(int $id): void
    {
        $q = $this->quote($id);
        $items = $this->items($id);
        view('quotes/print', ['title' => 'Devis ' . $q['number'], 'doc' => Documents::quoteA4($q, $items), 'bodyClass' => 'a4', 'backUrl' => url('/quotes/' . $id)], 'layouts/print');
    }

    public function convertForm(int $id): void
    {
        $q = $this->quote($id);
        if (!can('pos.sell')) {
            throw new HttpException(403, 'La facturation d\'un devis exige le droit de vendre à la caisse.');
        }
        if (!in_array($q['status'], ['draft', 'sent', 'accepted'], true)) {
            $this->fail('Ce devis ne peut pas être facturé.', '/quotes/' . $id);
        }
        view('quotes/convert', ['title' => 'Facturer le devis ' . $q['number'], 'q' => $q, 'items' => $this->items($id), 'uuid' => $this->uuid()]);
    }

    public function convert(int $id): void
    {
        $this->quote($id);
        if (!can('pos.sell')) {
            throw new HttpException(403);
        }
        $payments = [];
        foreach ((array) Request::input('payments', []) as $p) {
            $amount = (float) str_replace([' ', ','], ['', '.'], (string) ($p['amount'] ?? '0'));
            if ($amount > 0) {
                [$method, $provider] = array_pad(explode('|', (string) ($p['method'] ?? ''), 2), 2, null);
                $payments[] = ['method' => $method, 'provider' => $provider, 'amount' => $amount, 'reference' => (string) ($p['reference'] ?? '')];
            }
        }
        $r = $this->attempt(fn() => QuoteService::convert($id, $payments, Request::str('client_uuid')), '/quotes/' . $id . '/convert');
        flash('success', 'Vente ' . $r['number'] . ' enregistrée à partir du devis' . ($r['change'] > 0 ? ' — monnaie à rendre : ' . money($r['change']) : '') . '.');
        redirect('/sales/' . $r['sale_id']);
    }
}
