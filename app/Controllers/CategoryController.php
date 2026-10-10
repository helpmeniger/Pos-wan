<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Request;
use App\Core\Upload;
use App\Core\Validator;

final class CategoryController extends Controller
{
    public function index(): void
    {
        view('categories/index', [
            'title' => 'Catégories, marques et unités',
            'categories' => DB::all('SELECT c.*, p.name AS parent, (SELECT COUNT(*) FROM products WHERE category_id = c.id) AS n FROM categories c LEFT JOIN categories p ON p.id = c.parent_id ORDER BY COALESCE(p.sort_order, c.sort_order), COALESCE(p.name, c.name), c.parent_id IS NOT NULL, c.name'),
            'brands' => DB::all('SELECT b.*, (SELECT COUNT(*) FROM products WHERE brand_id = b.id) AS n FROM brands b ORDER BY name'),
            'units' => DB::all('SELECT * FROM units ORDER BY name'),
            'edit' => ($id = (int) Request::query('edit', 0)) ? DB::one('SELECT * FROM categories WHERE id = ?', [$id]) : null,
        ]);
    }

    private function data(?int $id): array
    {
        $_POST['kitchen_station'] = mb_strtolower(trim((string) ($_POST['kitchen_station'] ?? '')));
        $v = Validator::make($_POST, ['name' => 'required|max:120', 'parent_id' => 'nullable|int', 'color' => 'required|color', 'icon' => 'nullable|max:50|regex:/^bi-[a-z0-9-]+$/', 'sort_order' => 'nullable|int', 'active' => 'bool', 'kitchen_station' => 'nullable|max:30|regex:/^[a-z0-9_-]+$/'], ['name' => 'Nom', 'color' => 'Couleur', 'icon' => 'Icône']);
        if ($v->fails()) {
            $this->fail($v->firstError(), '/categories');
        }
        $d = $v->data;
        $d['sort_order'] ??= 0;
        $d['sales_channel'] = isset(\App\Services\ProductService::CHANNELS[$_POST['sales_channel'] ?? '']) ? $_POST['sales_channel'] : 'all';
        if ($d['parent_id'] && ($d['parent_id'] === $id || DB::value('SELECT parent_id FROM categories WHERE id = ?', [$d['parent_id']]))) {
            $this->fail('Catégorie parente invalide (deux niveaux maximum).', '/categories');
        }
        if (!empty($_FILES['image']['name'])) {
            $d['image'] = $this->attempt(fn() => Upload::image($_FILES['image'], 'categories'), '/categories');
        }
        return $d;
    }

    public function store(): void
    {
        $_POST['active'] = 1;
        $id = DB::insert('categories', $this->data(null));
        Audit::log('category.create', 'category', $id);
        flash('success', 'Catégorie créée.');
        redirect('/categories');
    }

    public function update(int $id): void
    {
        $this->findOr404('categories', $id);
        $_POST['active'] = Request::input('active') === '1' ? 1 : 0;
        DB::update('categories', $this->data($id), 'id = ?', [$id]);
        Audit::log('category.update', 'category', $id);
        flash('success', 'Catégorie mise à jour.');
        redirect('/categories');
    }

    public function storeBrand(): void
    {
        $name = Request::str('name');
        if ($name === '' || mb_strlen($name) > 120) {
            $this->fail('Nom de marque invalide.', '/categories');
        }
        DB::run('INSERT IGNORE INTO brands (name) VALUES (?)', [$name]);
        flash('success', 'Marque enregistrée.');
        redirect('/categories');
    }

    public function storeUnit(): void
    {
        $name = Request::str('name');
        $short = Request::str('short_name');
        if ($name === '' || $short === '' || mb_strlen($name) > 50 || mb_strlen($short) > 10) {
            $this->fail('Unité invalide.', '/categories');
        }
        DB::run('INSERT IGNORE INTO units (name, short_name, allow_decimal) VALUES (?, ?, ?)', [$name, $short, Request::input('allow_decimal') === '1' ? 1 : 0]);
        flash('success', 'Unité enregistrée.');
        redirect('/categories');
    }
}
