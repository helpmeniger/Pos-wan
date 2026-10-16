<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\Csv;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Upload;
use App\Services\Barcode;
use App\Services\ProductService;
use App\Services\StockService;

final class ProductController extends Controller
{
    private function lists(): array
    {
        return [
            'categories' => DB::all('SELECT c.id, c.name, p.name AS parent FROM categories c LEFT JOIN categories p ON p.id = c.parent_id WHERE c.active = 1 ORDER BY COALESCE(p.name, c.name), c.parent_id IS NOT NULL, c.name'),
            'brands' => DB::all('SELECT id, name FROM brands ORDER BY name'),
            'units' => DB::all('SELECT id, name, short_name FROM units ORDER BY name'),
            'suppliers' => DB::all('SELECT id, name FROM suppliers WHERE active = 1 ORDER BY name'),
            'parents' => feature('variants') ? DB::all("SELECT id, name, reference FROM products WHERE parent_id IS NULL AND status = 'active' ORDER BY name LIMIT 2000") : [],
            'warehouses' => $this->warehouses(),
        ];
    }

    private function filters(): array
    {
        $where = ['1=1'];
        $params = [];
        $status = Request::query('status', 'active');
        if (in_array($status, ['active', 'archived'], true)) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        if (($q = trim((string) Request::query('q', ''))) !== '') {
            $where[] = '(p.name LIKE ? OR p.reference LIKE ? OR p.barcode LIKE ? OR p.active_ingredient LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if (($b = trim((string) Request::query('barcode', ''))) !== '') {
            $where[] = '(p.barcode = ? OR p.id IN (SELECT product_id FROM product_packagings WHERE barcode = ?))';
            array_push($params, $b, $b);
        }
        if ($c = (int) Request::query('category', 0)) {
            $where[] = '(p.category_id = ? OR p.category_id IN (SELECT id FROM categories WHERE parent_id = ?))';
            array_push($params, $c, $c);
        }
        if ($s = (int) Request::query('supplier', 0)) {
            $where[] = 'p.supplier_id = ?';
            $params[] = $s;
        }
        $stock = Request::query('stock');
        if ($stock === 'low') {
            $where[] = 'p.track_stock = 1 AND COALESCE(st.q,0) <= p.min_stock';
        } elseif ($stock === 'out') {
            $where[] = 'p.track_stock = 1 AND COALESCE(st.q,0) <= 0';
        }
        $from = 'FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN units u ON u.id = p.unit_id
                 LEFT JOIN (SELECT product_id, SUM(quantity) q FROM stock_levels GROUP BY product_id) st ON st.product_id = p.id
                 WHERE ' . implode(' AND ', $where);
        return [$from, $params];
    }

    public function index(): void
    {
        [$from, $params] = $this->filters();
        $sort = ['name' => 'p.name', 'price' => 'p.sale_price', 'stock' => 'st.q', 'ref' => 'p.reference', 'cat' => 'c.name'][Request::query('sort', 'name')] ?? 'p.name';
        $dir = Request::query('dir') === 'desc' ? 'DESC' : 'ASC';
        $p = paginate($from, $params, 'p.*, c.name AS category, c.color AS cat_color, u.short_name AS unit, COALESCE(st.q,0) AS stock', "$sort $dir, p.id", 30);
        view('products/index', ['title' => 'Articles', 'p' => $p] + $this->lists());
    }

    public function create(): void
    {
        $prefill = [];
        if ($dupId = (int) Request::query('duplicate', 0)) {
            $prefill = DB::one('SELECT * FROM products WHERE id = ?', [$dupId]) ?? [];
            unset($prefill['id'], $prefill['reference'], $prefill['barcode'], $prefill['image']);
        }
        view('products/form', ['title' => 'Nouvel article', 'p' => $prefill, 'packs' => []] + $this->lists());
    }

    public function store(): void
    {
        $id = $this->attempt(function () {
            $d = ProductService::validate($_POST);
            if (Request::input('generate_barcode') === '1' && !$d['barcode']) {
                $d['barcode'] = Barcode::newInternalEan();
            }
            if (!empty($_FILES['image']['name'])) {
                $d['image'] = Upload::image($_FILES['image'], 'products');
            }
            return ProductService::create($d, Request::num('initial_qty'), Request::int('initial_warehouse') ?: null, Request::str('initial_lot') ?: null, Request::str('initial_expiry') ?: null);
        }, '/products/create');
        flash('success', 'Article créé.');
        redirect(Request::input('save_new') === '1' ? '/products/create' : '/products/' . $id);
    }

    public function edit(int $id): void
    {
        $p = $this->findOr404('products', $id);
        view('products/form', ['title' => 'Modifier : ' . $p['name'], 'p' => $p, 'packs' => DB::all('SELECT * FROM product_packagings WHERE product_id = ? ORDER BY factor', [$id])] + $this->lists());
    }

    public function update(int $id): void
    {
        $old = $this->findOr404('products', $id);
        $this->attempt(function () use ($id, $old) {
            $d = ProductService::validate($_POST, $id);
            if (Request::input('generate_barcode') === '1' && !$d['barcode']) {
                $d['barcode'] = Barcode::newInternalEan();
            }
            if (!empty($_FILES['image']['name'])) {
                $d['image'] = Upload::image($_FILES['image'], 'products');
            } elseif (Request::input('remove_image') === '1') {
                $d['image'] = null;
            }
            ProductService::update($id, $d, can('products.price_edit'), can('products.price_edit') && can('products.cost_view'));
            if (array_key_exists('image', $d) && $old['image'] && $old['image'] !== $d['image']) {
                Upload::delete($old['image']);
            }
        }, '/products/' . $id . '/edit');
        if (!can('products.price_edit')) {
            flash('warning', 'Les prix n\'ont pas été modifiés : permission « Modifier les prix de vente » requise.');
        }
        flash('success', 'Article mis à jour.');
        redirect('/products/' . $id);
    }

    public function show(int $id): void
    {
        $p = DB::one('SELECT p.*, c.name AS category, b.name AS brand, u.name AS unit, s.name AS supplier, pp.name AS parent_name FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN brands b ON b.id = p.brand_id LEFT JOIN units u ON u.id = p.unit_id LEFT JOIN suppliers s ON s.id = p.supplier_id LEFT JOIN products pp ON pp.id = p.parent_id WHERE p.id = ?', [$id]);
        if (!$p) {
            throw new HttpException(404);
        }
        view('products/show', [
            'title' => $p['name'], 'p' => $p,
            'levels' => DB::all('SELECT w.name, sl.quantity FROM stock_levels sl JOIN warehouses w ON w.id = sl.warehouse_id WHERE sl.product_id = ? ORDER BY w.name', [$id]),
            'lots' => DB::all('SELECT l.*, w.name AS warehouse FROM stock_lots l JOIN warehouses w ON w.id = l.warehouse_id WHERE l.product_id = ? AND l.quantity <> 0 ORDER BY l.expiry_date', [$id]),
            'movements' => DB::all('SELECT m.*, u.full_name, w.name AS warehouse, l.lot_number FROM stock_movements m LEFT JOIN users u ON u.id = m.user_id JOIN warehouses w ON w.id = m.warehouse_id LEFT JOIN stock_lots l ON l.id = m.lot_id WHERE m.product_id = ? ORDER BY m.id DESC LIMIT 50', [$id]),
            'prices' => DB::all('SELECT ph.*, u.full_name FROM price_history ph LEFT JOIN users u ON u.id = ph.user_id WHERE ph.product_id = ? ORDER BY ph.id DESC LIMIT 30', [$id]),
            'packs' => DB::all('SELECT * FROM product_packagings WHERE product_id = ? ORDER BY factor', [$id]),
            'variants' => DB::all('SELECT p.id, p.name, p.variant_size, p.variant_color, p.sale_price, COALESCE(SUM(sl.quantity),0) AS stock FROM products p LEFT JOIN stock_levels sl ON sl.product_id = p.id WHERE p.parent_id = ? GROUP BY p.id, p.name, p.variant_size, p.variant_color, p.sale_price', [$id]),
            'stats' => DB::one("SELECT COALESCE(SUM(si.base_quantity),0) AS qty, COALESCE(SUM(si.line_total),0) AS revenue FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.product_id = ? AND s.status <> 'cancelled' AND s.created_at >= CURDATE() - INTERVAL 30 DAY", [$id]),
        ]);
    }

    public function archive(int $id): void
    {
        $p = $this->findOr404('products', $id);
        // On n'efface jamais un article ayant un historique : archivage (masqué en caisse, historique conservé)
        DB::update('products', ['status' => 'archived'], 'id = ?', [$id]);
        Audit::log('product.archive', 'product', $id, ['name' => $p['name'], 'stock' => StockService::totalQty($id)]);
        flash('success', 'Article archivé (historique conservé). Il n\'apparaît plus en caisse.');
        redirect('/products');
    }

    public function restore(int $id): void
    {
        $this->findOr404('products', $id);
        DB::update('products', ['status' => 'active'], 'id = ?', [$id]);
        Audit::log('product.restore', 'product', $id);
        flash('success', 'Article réactivé.');
        redirect('/products/' . $id);
    }

    public function addPackaging(int $id): void
    {
        $this->findOr404('products', $id);
        $this->attempt(function () use ($id) {
            $name = Request::str('name');
            $factor = Request::num('factor');
            $price = Request::num('sale_price');
            $barcode = Request::str('barcode') ?: null;
            if ($name === '' || mb_strlen($name) > 50 || $factor <= 1 || $price < 0) {
                throw new BusinessException('Conditionnement invalide (nom, quantité > 1, prix).');
            }
            if ($barcode && (DB::value('SELECT 1 FROM products WHERE barcode = ?', [$barcode]) || DB::value('SELECT 1 FROM product_packagings WHERE barcode = ?', [$barcode]))) {
                throw new BusinessException('Code-barres déjà utilisé.');
            }
            DB::insert('product_packagings', ['product_id' => $id, 'name' => $name, 'factor' => $factor, 'sale_price' => $price, 'barcode' => $barcode]);
            Audit::log('product.packaging_add', 'product', $id, ['name' => $name, 'factor' => $factor, 'price' => $price]);
        }, '/products/' . $id . '/edit');
        flash('success', 'Conditionnement ajouté.');
        redirect('/products/' . $id . '/edit');
    }

    public function deletePackaging(int $id): void
    {
        $pk = $this->findOr404('product_packagings', $id);
        if (DB::value('SELECT 1 FROM sale_items WHERE packaging_id = ? LIMIT 1', [$id])) {
            $this->fail('Ce conditionnement a déjà été vendu : il ne peut pas être supprimé.', '/products/' . $pk['product_id'] . '/edit');
        }
        DB::run('DELETE FROM product_packagings WHERE id = ?', [$id]);
        Audit::log('product.packaging_delete', 'product', (int) $pk['product_id'], ['name' => $pk['name']]);
        flash('success', 'Conditionnement supprimé.');
        redirect('/products/' . $pk['product_id'] . '/edit');
    }

    // ------------------------------------------------------------------ import / export
    private const CSV_HEADERS = ['reference', 'code_barres', 'nom', 'categorie', 'unite', 'prix_vente', 'prix_achat', 'prix_gros', 'seuil_alerte', 'stock_initial', 'lot', 'peremption'];

    public function export(): void
    {
        [$from, $params] = $this->filters();
        $showCost = can('products.cost_view');
        $rows = DB::all("SELECT p.reference, p.barcode, p.name, c.name AS category, u.short_name, p.sale_price, " . ($showCost ? 'p.cost_price' : "''") . ", p.wholesale_price, p.min_stock, COALESCE(st.q,0), p.status $from ORDER BY p.name", $params);
        Audit::log('product.export', null, null, ['count' => count($rows)]);
        Csv::download('articles_' . date('Ymd') . '.csv', ['reference', 'code_barres', 'nom', 'categorie', 'unite', 'prix_vente', 'prix_achat', 'prix_gros', 'seuil_alerte', 'stock', 'statut'], $rows);
    }

    public function template(): void
    {
        Csv::download('modele_import_articles.csv', self::CSV_HEADERS, [
            ['ART00100', '2100000000017', 'Exemple : Sac de riz 25 kg', 'Alimentation', 'sac', '13000', '10000', '12500', '5', '20', '', ''],
        ]);
    }

    public function importForm(): void
    {
        view('products/import', ['title' => 'Importer / exporter des articles', 'warehouses' => $this->warehouses(), 'report' => \App\Core\Session::get('import_report')]);
    }

    /**
     * Import CSV : crée les nouveaux articles (avec stock initial éventuel) et met à jour
     * nom/prix des articles existants (même référence). Le stock d'un article existant
     * n'est JAMAIS modifié par l'import (utiliser les ajustements / réceptions).
     * Tout le fichier est traité dans une transaction : en cas d'erreur, rien n'est enregistré.
     */
    public function import(): void
    {
        \App\Core\Session::forget('import_report');
        $simulate = Request::input('simulate') === '1';
        $wh = Request::int('warehouse_id');
        try {
            $path = Upload::csv($_FILES['file'] ?? []);
            $rows = Csv::read($path);
            if (!$rows) {
                throw new BusinessException('Fichier vide.');
            }
            if (count($rows) > 5000) {
                throw new BusinessException('5 000 lignes maximum par import.');
            }
            $missing = array_diff(['nom', 'prix_vente'], array_keys($rows[0]));
            if ($missing) {
                throw new BusinessException('Colonnes obligatoires manquantes : ' . implode(', ', $missing) . '. Téléchargez le modèle.');
            }
            $report = ['created' => 0, 'updated' => 0, 'errors' => [], 'simulate' => $simulate];
            $pdo = DB::connection();
            $pdo->beginTransaction();
            try {
                foreach ($rows as $n => $r) {
                    $line = $n + 2;
                    try {
                        $catId = null;
                        if (($cn = $r['categorie'] ?? '') !== '') {
                            $catId = DB::value('SELECT id FROM categories WHERE name = ?', [$cn]) ?: DB::insert('categories', ['name' => mb_substr($cn, 0, 120)]);
                        }
                        $unitId = null;
                        if (($un = $r['unite'] ?? '') !== '') {
                            $unitId = DB::value('SELECT id FROM units WHERE short_name = ? OR name = ?', [$un, $un]);
                        }
                        $ref = $r['reference'] ?? '';
                        $existing = $ref !== '' ? DB::one('SELECT * FROM products WHERE reference = ?', [$ref]) : null;
                        $input = [
                            'name' => $r['nom'] ?? '', 'reference' => $ref, 'barcode' => $r['code_barres'] ?? ($existing['barcode'] ?? ''),
                            'category_id' => $catId ?? ($existing['category_id'] ?? null), 'unit_id' => $unitId ?? ($existing['unit_id'] ?? null),
                            'sale_price' => $r['prix_vente'] ?? '', 'cost_price' => $r['prix_achat'] ?? '', 'wholesale_price' => $r['prix_gros'] ?? '',
                            'min_stock' => $r['seuil_alerte'] ?? '', 'track_stock' => 1, 'track_lots' => $existing['track_lots'] ?? (feature('lots') && ($r['lot'] ?? '') !== '' ? 1 : 0),
                        ];
                        if ($existing) {
                            $d = ProductService::validate($input + $existing, (int) $existing['id']);
                            ProductService::update((int) $existing['id'], $d, can('products.price_edit'), false);
                            $report['updated']++;
                        } else {
                            $d = ProductService::validate($input);
                            $qty = (float) str_replace([' ', ','], ['', '.'], (string) ($r['stock_initial'] ?? '0'));
                            if ($qty > 0 && !$wh) {
                                throw new BusinessException('entrepôt du stock initial non choisi');
                            }
                            $exp = $r['peremption'] ?? '';
                            if ($exp !== '' && preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $exp, $m)) {
                                $exp = "$m[3]-$m[2]-$m[1]";
                            }
                            ProductService::create($d, max(0, $qty), $wh ?: null, ($r['lot'] ?? '') ?: null, $exp ?: null);
                            $report['created']++;
                        }
                    } catch (BusinessException $e) {
                        $report['errors'][] = "Ligne $line : " . $e->getMessage();
                    }
                }
                if ($report['errors'] || $simulate) {
                    $pdo->rollBack();
                } else {
                    $pdo->commit();
                    Audit::log('product.import', null, null, ['created' => $report['created'], 'updated' => $report['updated']]);
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            \App\Core\Session::set('import_report', $report);
            flash($report['errors'] ? 'danger' : 'success', $report['errors']
                ? 'Import annulé : corrigez les erreurs ci-dessous puis recommencez (aucune donnée enregistrée).'
                : ($simulate ? 'Simulation réussie : ' : 'Import terminé : ') . "{$report['created']} création(s), {$report['updated']} mise(s) à jour.");
        } catch (BusinessException $e) {
            flash('danger', $e->getMessage());
        }
        redirect('/products/import');
    }

    // ------------------------------------------------------------------ étiquettes, prix groupés, doublons
    /** Formulaire article : propose un EAN-13 interne libre (bouton « Générer »). */
    public function newBarcode(): void
    {
        json_response(['ok' => true, 'barcode' => Barcode::newInternalEan()]);
    }

    /** Fiche article : crée un code-barres interne pour un article qui n'en a pas. */
    public function assignBarcode(int $id): void
    {
        $p = $this->findOr404('products', $id);
        if ($p['barcode']) {
            flash('warning', 'Cet article possède déjà un code-barres.');
        } else {
            $code = Barcode::newInternalEan();
            DB::update('products', ['barcode' => $code], 'id = ?', [$id]);
            Audit::log('product.barcode', 'product', $id, ['barcode' => $code]);
            flash('success', 'Code-barres ' . $code . ' créé. Imprimez l\'étiquette pour l\'apposer sur l\'article.');
        }
        redirect('/products/' . $id);
    }

    /** Crée un code-barres interne pour tous les articles actifs qui n'en ont pas. */
    public function bulkBarcodes(): void
    {
        $ids = array_column(DB::all("SELECT id FROM products WHERE active = 1 AND (barcode IS NULL OR barcode = '') ORDER BY id LIMIT 2000"), 'id');
        DB::transaction(function () use ($ids) {
            foreach ($ids as $id) {
                DB::update('products', ['barcode' => Barcode::newInternalEan()], 'id = ?', [(int) $id]);
            }
        });
        Audit::log('product.barcode_bulk', 'product', null, ['count' => count($ids)]);
        flash('success', count($ids) ? count($ids) . ' code(s)-barres créé(s). Imprimez les étiquettes correspondantes.' : 'Tous les articles actifs ont déjà un code-barres.');
        if ($ids) {
            redirect('/products/labels', ['ids' => array_slice(array_map('intval', $ids), 0, 300)]);
        }
        redirect('/products');
    }

    public function barcode(int $id): void
    {
        $p = $this->findOr404('products', $id);
        header('Content-Type: image/svg+xml');
        header('Cache-Control: private, max-age=3600');
        echo Barcode::svg($p['barcode'] ?: $p['reference']);
        exit;
    }

    public function bulkPriceForm(): void
    {
        view('products/bulk_price', ['title' => 'Modification groupée des prix', 'categories' => DB::all('SELECT id, name FROM categories ORDER BY name')]);
    }

    public function bulkPrice(): void
    {
        $mode = Request::str('mode') === 'amount' ? 'amount' : 'percent';
        $value = Request::num('value');
        if ($value == 0.0 || ($mode === 'percent' && ($value < -90 || $value > 500))) {
            $this->fail('Valeur de variation invalide.', '/products/bulk-price');
        }
        $n = ProductService::bulkPrice(Request::int('category_id') ?: null, $mode, $value, max(1, Request::int('rounding', 1)));
        flash('success', "$n prix modifié(s). L'historique des prix a été enregistré.");
        redirect('/products');
    }

    public function duplicates(): void
    {
        $rows = DB::all("SELECT LOWER(TRIM(name)) AS k, COUNT(*) AS n, GROUP_CONCAT(CONCAT(id, '|', reference, '|', COALESCE(barcode,'')) SEPARATOR ';;') AS items FROM products WHERE status = 'active' GROUP BY LOWER(TRIM(name)) HAVING n > 1 ORDER BY n DESC LIMIT 200");
        view('products/duplicates', ['title' => 'Doublons potentiels', 'rows' => $rows]);
    }
}
