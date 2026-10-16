<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Validator;
use App\Services\ImageService;
use App\Services\StockService;
use App\Services\TrainingService;

final class ProductController extends Controller
{
    private function product(Request $r): array
    {
        $p = Database::fetch('SELECT * FROM products WHERE id = :id AND store_id = :s AND deleted_at IS NULL', ['id' => (int) $r->param('id'), 's' => $this->storeId()]);
        if (!$p) {
            throw new HttpException(404, 'Produit introuvable.');
        }
        return $p;
    }

    public function index(Request $r): void
    {
        [$limit, $offset, $page] = $this->paginate(30);
        $where = 'p.store_id = :s AND p.deleted_at IS NULL';
        $params = ['s' => $this->storeId()];
        if ($q = $r->query('q')) {
            $where .= ' AND (p.name LIKE :q OR p.brand LIKE :q2 OR p.barcode = :q3 OR p.reference = :q4)';
            $params += ['q' => "%{$q}%", 'q2' => "%{$q}%", 'q3' => $q, 'q4' => $q];
        }
        if ($cat = (int) $r->query('category')) {
            $where .= ' AND p.category_id = :c';
            $params['c'] = $cat;
        }
        if ($st = $r->query('recognition')) {
            $where .= ' AND p.recognition_status = :rs';
            $params['rs'] = (string) $st;
        }
        if ($r->query('active') !== null && $r->query('active') !== '') {
            $where .= ' AND p.is_active = :a';
            $params['a'] = (int) $r->query('active');
        }
        $total = (int) Database::value("SELECT COUNT(*) FROM products p WHERE {$where}", $params);
        $rows = Database::fetchAll("SELECT p.*, c.name category, (SELECT COUNT(*) FROM product_images i WHERE i.product_id = p.id AND i.usage_split <> 'exclue') photos
            FROM products p LEFT JOIN product_categories c ON c.id = p.category_id WHERE {$where} ORDER BY p.name LIMIT {$limit} OFFSET {$offset}", $params);
        $this->view('products/index', ['title' => 'Produits', 'products' => $rows, 'categories' => $this->categories(),
            'page' => $page, 'pages' => (int) ceil($total / $limit), 'total' => $total, 'minImages' => (int) Settings::get('ai.min_images_per_product', 8)]);
    }

    private function categories(): array
    {
        return Database::fetchAll('SELECT id, name FROM product_categories WHERE is_active = 1 ORDER BY name');
    }

    public function search(Request $r): void
    {
        $q = (string) $r->query('q', '');
        $rows = Database::fetchAll('SELECT id, name, barcode, sale_price, stock_quantity, unit FROM products
            WHERE store_id = :s AND deleted_at IS NULL AND is_active = 1 AND (barcode = :b OR name LIKE :q OR reference = :r) ORDER BY barcode = :b2 DESC, name LIMIT 15',
            ['s' => $this->storeId(), 'b' => $q, 'q' => "%{$q}%", 'r' => $q, 'b2' => $q]);
        Response::json(['ok' => true, 'items' => $rows]);
    }

    public function create(Request $r): void
    {
        $this->view('products/form', ['title' => 'Nouveau produit', 'p' => ['unit' => 'pièce', 'min_confidence' => 0.75, 'is_active' => 1], 'categories' => $this->categories()]);
    }

    private function validated(Request $r): array
    {
        $v = Validator::make($r->all(), [
            'name' => 'required|string|max:150', 'category_id' => 'int', 'brand' => 'string|max:100', 'reference' => 'string|max:80',
            'barcode' => 'string|max:64|regex:/^[0-9A-Za-z\-]+$/', 'purchase_price' => 'numeric|min:0', 'sale_price' => 'numeric|min:0',
            'stock_alert_threshold' => 'numeric|min:0', 'unit' => 'required|string|max:20', 'min_confidence' => 'required|numeric|min:0.5|max:0.99',
            'is_active' => 'bool',
        ]);
        if ($v->fails()) {
            $this->back('error', 'Formulaire invalide : ' . implode(' ', array_map(static fn ($k, $m) => "[$k] $m", array_keys($v->errors), $v->errors)));
        }
        $d = $v->data;
        $d['purchase_price'] ??= 0;
        $d['sale_price'] ??= 0;
        $d['stock_alert_threshold'] ??= 0;
        if ($d['category_id'] && !Database::value('SELECT 1 FROM product_categories WHERE id = :id', ['id' => $d['category_id']])) {
            $d['category_id'] = null;
        }
        return $d;
    }

    public function store(Request $r): void
    {
        $d = $this->validated($r);
        $initial = (float) str_replace(',', '.', (string) $r->input('initial_stock', '0'));
        $d += ['uuid' => uuid4(), 'store_id' => $this->storeId()];
        try {
            $id = Database::insert('products', $d);
        } catch (\PDOException $e) {
            $this->back('error', 'Code-barres déjà utilisé pour un autre produit.');
        }
        if ($initial > 0) {
            StockService::record($this->storeId(), $id, 'reception', $initial, ['comment' => 'Stock initial à la création']);
        }
        Audit::log('produit_cree', 'product', $id, ['nom' => $d['name']]);
        $this->flash('success', 'Produit enregistré. Ajoutez maintenant des photos de référence sous différents angles.');
        Response::redirect('products/' . $id);
    }

    public function edit(Request $r): void
    {
        $this->view('products/form', ['title' => 'Modifier le produit', 'p' => $this->product($r), 'categories' => $this->categories()]);
    }

    public function update(Request $r): void
    {
        $p = $this->product($r);
        $d = $this->validated($r);
        try {
            Database::update('products', $d, 'id = :id', ['id' => $p['id']]);
        } catch (\PDOException $e) {
            $this->back('error', 'Code-barres déjà utilisé pour un autre produit.');
        }
        Audit::log('produit_modifie', 'product', $p['id']);
        $this->flash('success', 'Produit mis à jour. Le stock se modifie uniquement via le module Stocks.');
        Response::redirect('products/' . $p['id']);
    }

    public function destroy(Request $r): void
    {
        $p = $this->product($r);
        Database::update('products', ['deleted_at' => date('Y-m-d H:i:s'), 'is_active' => 0, 'detection_enabled' => 0], 'id = :id', ['id' => $p['id']]);
        Audit::log('produit_supprime', 'product', $p['id']);
        $this->flash('success', 'Produit archivé (suppression logique : l\'historique est conservé).');
        Response::redirect('products');
    }

    public function show(Request $r): void
    {
        $p = $this->product($r);
        $photos = Database::fetchAll('SELECT * FROM product_images WHERE product_id = :p ORDER BY id', ['p' => $p['id']]);
        $validation = Database::fetchAll('SELECT r.*, v.version_number, v.status vstatus, c.name confused_name FROM ai_validation_results r
            JOIN ai_model_versions v ON v.id = r.model_version_id LEFT JOIN products c ON c.id = r.most_confused_with_product_id
            WHERE r.product_id = :p ORDER BY r.id DESC LIMIT 5', ['p' => $p['id']]);
        $cat = $p['category_id'] ? Database::value('SELECT name FROM product_categories WHERE id = :id', ['id' => $p['category_id']]) : null;
        $this->view('products/show', ['title' => $p['name'], 'p' => $p, 'photos' => $photos, 'validation' => $validation, 'category' => $cat,
            'minImages' => (int) Settings::get('ai.min_images_per_product', 8), 'recImages' => (int) Settings::get('ai.recommended_images_per_product', 15),
            'maxMb' => Env::int('UPLOAD_MAX_IMAGE_MB', 8), 'scripts' => ['js/photos.js']]);
    }

    public function uploadPhotos(Request $r): void
    {
        $p = $this->product($r);
        $source = in_array($r->input('source'), ['webcam', 'import', 'smartphone'], true) ? $r->input('source') : 'import';
        $angle = in_array($r->input('view_angle'), ['avant', 'arriere', 'gauche', 'droite', 'dessus', 'dessous', 'autre'], true) ? $r->input('view_angle') : 'autre';
        $results = [];
        $stored = [];
        $files = $r->files('photos');
        if (count($files) > 30) {
            throw new HttpException(422, '30 photos maximum par envoi.');
        }
        foreach ($files as $f) {
            try {
                $stored[] = ImageService::storeUpload($f, 'p' . $p['id']);
            } catch (HttpException $e) {
                $results[] = ['file' => mb_substr((string) $f['name'], 0, 80), 'error' => $e->getMessage()];
            }
        }
        $b64 = (string) $r->input('image_base64', '');
        if ($b64 !== '') {
            if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $b64, $m)) {
                throw new HttpException(422, 'Image de la caméra invalide.');
            }
            $stored[] = ImageService::storeBinary((string) base64_decode($m[2], true), 'p' . $p['id']);
        }
        $added = 0;
        foreach ($stored as $s) {
            if (Database::value('SELECT 1 FROM product_images WHERE product_id = :p AND sha256 = :h', ['p' => $p['id'], 'h' => $s['sha256']])) {
                @unlink(STORAGE_PATH . '/product_images/' . $s['path']);
                $results[] = ['file' => basename($s['path']), 'error' => 'Photo identique déjà enregistrée.'];
                continue;
            }
            Database::insert('product_images', ['product_id' => $p['id'], 'file_path' => $s['path'], 'sha256' => $s['sha256'], 'width' => $s['width'], 'height' => $s['height'],
                'view_angle' => $angle, 'source' => $source, 'quality_score' => $s['quality']['score'], 'quality_notes' => implode(', ', $s['quality']['issues']) ?: null,
                'uploaded_by' => Auth::id()]);
            $added++;
        }
        $this->refreshRecognitionStatus((int) $p['id']);
        Audit::log('photos_ajoutees', 'product', $p['id'], ['nombre' => $added]);
        if ($r->wantsJson()) {
            Response::json(['ok' => true, 'added' => $added, 'errors' => $results]);
        }
        $msg = "{$added} photo(s) ajoutée(s).";
        foreach ($results as $e) {
            $msg .= ' ' . $e['file'] . ' : ' . $e['error'];
        }
        $this->back($results ? 'warning' : 'success', $msg, 'products/' . $p['id']);
    }

    public function updatePhoto(Request $r): void
    {
        $p = $this->product($r);
        $v = Validator::make($r->all(), ['view_angle' => 'required|in:avant,arriere,gauche,droite,dessus,dessous,autre',
            'usage_split' => 'required|in:reference,test,exclue', 'annotation' => 'string|max:255']);
        if ($v->fails()) {
            $this->back('error', $v->firstError());
        }
        Database::execute('UPDATE product_images SET view_angle = :a, usage_split = :u, annotation = :n WHERE id = :id AND product_id = :p',
            ['a' => $v->data['view_angle'], 'u' => $v->data['usage_split'], 'n' => $v->data['annotation'], 'id' => (int) $r->param('photo'), 'p' => $p['id']]);
        $this->refreshRecognitionStatus((int) $p['id']);
        $this->back('success', 'Photo mise à jour.', 'products/' . $p['id']);
    }

    public function deletePhoto(Request $r): void
    {
        $p = $this->product($r);
        $img = Database::fetch('SELECT * FROM product_images WHERE id = :id AND product_id = :p', ['id' => (int) $r->param('photo'), 'p' => $p['id']]);
        if ($img) {
            Database::execute('DELETE FROM product_images WHERE id = :id', ['id' => $img['id']]);
            $file = realpath(STORAGE_PATH . '/product_images/' . $img['file_path']);
            if ($file && str_starts_with($file, realpath(STORAGE_PATH . '/product_images'))) {
                @unlink($file);
            }
            Audit::log('photo_supprimee', 'product_image', $img['id']);
        }
        $this->refreshRecognitionStatus((int) $p['id']);
        $this->back('success', 'Photo supprimée.', 'products/' . $p['id']);
    }

    /** « Enregistrer l'article et préparer sa reconnaissance IA ». */
    public function prepare(Request $r): void
    {
        $p = $this->product($r);
        $n = (int) Database::value("SELECT COUNT(*) FROM product_images WHERE product_id = :p AND usage_split <> 'exclue'", ['p' => $p['id']]);
        $min = (int) Settings::get('ai.min_images_per_product', 8);
        if ($n < $min) {
            $this->back('error', "Photos insuffisantes : {$n} sur {$min} minimum. Ajoutez des vues variées (faces, côtés, distances, éclairages).", 'products/' . $p['id']);
        }
        $res = TrainingService::start($this->storeId(), 'Préparation lancée depuis la fiche « ' . $p['name'] . ' »');
        if (!$res['ok']) {
            $this->back('error', (string) $res['error'], 'products/' . $p['id']);
        }
        $this->flash('info', 'Préparation lancée. Les résultats de validation apparaîtront sur cette page lorsqu\'ils seront disponibles.');
        Response::redirect('training/jobs/' . $res['job_id']);
    }

    private function refreshRecognitionStatus(int $productId): void
    {
        $n = (int) Database::value("SELECT COUNT(*) FROM product_images WHERE product_id = :p AND usage_split <> 'exclue'", ['p' => $productId]);
        $min = (int) Settings::get('ai.min_images_per_product', 8);
        $status = $n === 0 ? 'aucune' : ($n < $min ? 'photos_insuffisantes' : 'prete');
        Database::execute("UPDATE products SET recognition_status = :s WHERE id = :id AND recognition_status IN ('aucune','photos_insuffisantes','prete')",
            ['s' => $status, 'id' => $productId]);
    }

    public function importForm(Request $r): void
    {
        $this->view('products/import', ['title' => 'Importer des produits']);
    }

    /** Import CSV : nom;categorie;marque;reference;code_barres;prix_achat;prix_vente;stock_initial;seuil;unite */
    public function import(Request $r): void
    {
        $f = $r->file('csv');
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > Env::int('UPLOAD_MAX_CSV_MB', 5) * 1048576) {
            $this->back('error', 'Fichier CSV manquant ou trop volumineux.', 'products/import');
        }
        $fh = fopen($f['tmp_name'], 'r');
        $first = (string) fgets($fh);
        $sep = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        rewind($fh);
        $header = array_map(static fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($fh, 0, $sep, '"', '') ?: []);
        $cats = array_column(Database::fetchAll('SELECT id, LOWER(name) n FROM product_categories'), 'id', 'n');
        $created = $updated = 0;
        $errors = [];
        $line = 1;
        while (($row = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            $line++;
            if (count($row) < 2) {
                continue;
            }
            $d = array_combine(array_slice($header, 0, count($row)), array_slice($row, 0, count($header)));
            $name = trim((string) ($d['nom'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150) {
                $errors[] = "Ligne {$line} : nom manquant ou trop long.";
                continue;
            }
            $num = static fn ($k) => (float) str_replace([' ', ','], ['', '.'], (string) ($d[$k] ?? 0));
            $barcode = preg_replace('/[^0-9A-Za-z\-]/', '', (string) ($d['code_barres'] ?? '')) ?: null;
            $data = ['name' => $name, 'category_id' => $cats[mb_strtolower(trim((string) ($d['categorie'] ?? '')))] ?? null,
                'brand' => mb_substr(trim((string) ($d['marque'] ?? '')), 0, 100) ?: null, 'reference' => mb_substr(trim((string) ($d['reference'] ?? '')), 0, 80) ?: null,
                'barcode' => $barcode, 'purchase_price' => $num('prix_achat'), 'sale_price' => $num('prix_vente'),
                'stock_alert_threshold' => $num('seuil'), 'unit' => mb_substr(trim((string) ($d['unite'] ?? 'pièce')), 0, 20) ?: 'pièce'];
            $existing = $barcode ? Database::value('SELECT id FROM products WHERE store_id = :s AND barcode = :b', ['s' => $this->storeId(), 'b' => $barcode]) : null;
            if ($existing) {
                Database::update('products', $data, 'id = :id', ['id' => $existing]);
                $updated++;
            } else {
                $id = Database::insert('products', $data + ['uuid' => uuid4(), 'store_id' => $this->storeId()]);
                if ($num('stock_initial') > 0) {
                    StockService::record($this->storeId(), $id, 'reception', $num('stock_initial'), ['comment' => 'Stock initial (import CSV)']);
                }
                $created++;
            }
        }
        fclose($fh);
        Audit::log('produits_importes', 'product', null, ['crees' => $created, 'maj' => $updated]);
        $this->back($errors ? 'warning' : 'success', "{$created} produit(s) créé(s), {$updated} mis à jour. " . implode(' ', array_slice($errors, 0, 10)), 'products');
    }
}
