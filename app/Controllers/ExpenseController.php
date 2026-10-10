<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csv;
use App\Core\DB;
use App\Core\Request;
use App\Core\Validator;
use App\Services\ExpenseService;

final class ExpenseController extends Controller
{
    public function index(): void
    {
        [$from, $to] = date_range('first day of this month');
        $where = 'e.expense_date BETWEEN ? AND ?';
        $params = [$from, $to];
        if ($t = Request::query('type')) {
            if (in_array($t, ['expense', 'income'], true)) {
                $where .= ' AND e.type = ?';
                $params[] = $t;
            }
        }
        if ($c = (int) Request::query('category', 0)) {
            $where .= ' AND e.category_id = ?';
            $params[] = $c;
        }
        $from_ = "FROM expenses e JOIN expense_categories c ON c.id = e.category_id JOIN users u ON u.id = e.user_id WHERE $where";
        if (Request::query('export') === 'csv') {
            Csv::download("depenses_{$from}_{$to}.csv", ['N°', 'Date', 'Type', 'Catégorie', 'Description', 'Mode', 'Montant', 'Statut', 'Saisi par'],
                DB::all("SELECT e.number, e.expense_date, e.type, c.name, e.description, e.method, e.amount, e.status, u.full_name $from_ ORDER BY e.expense_date", $params));
        }
        $p = paginate($from_, $params, 'e.*, c.name AS category, u.full_name', 'e.expense_date DESC, e.id DESC');
        $byCat = DB::all("SELECT c.name, e.type, SUM(e.amount) AS total FROM expenses e JOIN expense_categories c ON c.id = e.category_id WHERE $where AND e.status = 'valid' GROUP BY c.name, e.type ORDER BY total DESC", $params);
        view('expenses/index', ['title' => 'Dépenses & recettes diverses', 'p' => $p, 'from' => $from, 'to' => $to, 'byCat' => $byCat,
            'categories' => DB::all('SELECT * FROM expense_categories ORDER BY type, name')]);
    }

    public function store(): void
    {
        $v = Validator::make($_POST, ['type' => 'required|in:expense,income', 'category_id' => 'required|int', 'amount' => 'required|numeric|min:1', 'method' => 'required|max:30', 'expense_date' => 'required|date', 'description' => 'required|max:255'],
            ['category_id' => 'Catégorie', 'amount' => 'Montant', 'expense_date' => 'Date', 'description' => 'Description']);
        if ($v->fails()) {
            $this->fail($v->firstError(), '/expenses');
        }
        $d = $v->data;
        if (!isset(payment_methods()[$d['method']])) {
            $this->fail('Mode de paiement invalide.', '/expenses');
        }
        if ($d['expense_date'] > date('Y-m-d')) {
            $this->fail('La date ne peut pas être dans le futur.', '/expenses');
        }
        $this->attempt(fn() => ExpenseService::create($d['type'], (int) $d['category_id'], (float) $d['amount'], $d['method'], $d['expense_date'], $d['description'], Request::input('from_register') === '1'), '/expenses');
        flash('success', ($d['type'] === 'expense' ? 'Dépense' : 'Recette') . ' enregistrée.');
        redirect('/expenses');
    }

    public function reverse(int $id): void
    {
        $this->attempt(fn() => ExpenseService::reverse($id, Request::str('reason')), '/expenses');
        flash('success', 'Opération contrepassée (l\'originale reste visible dans l\'historique).');
        redirect('/expenses');
    }

    public function storeCategory(): void
    {
        $name = Request::str('name');
        $type = Request::str('type') === 'income' ? 'income' : 'expense';
        if ($name === '' || mb_strlen($name) > 100) {
            $this->fail('Nom de catégorie invalide.', '/expenses');
        }
        DB::run('INSERT IGNORE INTO expense_categories (name, type) VALUES (?, ?)', [$name, $type]);
        flash('success', 'Catégorie ajoutée.');
        redirect('/expenses');
    }
}
