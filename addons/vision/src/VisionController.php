<?php
declare(strict_types=1);

namespace Addons\Vision;

use App\Controllers\Controller;
use App\Core\DB;
use App\Core\Request;

final class VisionController extends Controller
{
    /** Apprentissage des articles, statistiques et réglages. */
    public function index(): void
    {
        $q = trim((string) Request::query('q', ''));
        $cat = (int) Request::query('category', 0);
        $f = (string) Request::query('f', '');
        $where = "WHERE p.status = 'active'";
        $params = [];
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $where .= ' AND (p.name LIKE ? OR p.reference LIKE ? OR p.barcode = ?)';
            array_push($params, $like, $like, $q);
        }
        if ($cat > 0) {
            $where .= ' AND (p.category_id = ? OR p.category_id IN (SELECT id FROM categories WHERE parent_id = ?))';
            array_push($params, $cat, $cat);
        }
        if ($f === 'none') {
            $where .= ' AND NOT EXISTS (SELECT 1 FROM vision_refs r WHERE r.product_id = p.id)';
        } elseif ($f === 'few') {
            $where .= ' AND (SELECT COUNT(*) FROM vision_refs r WHERE r.product_id = p.id) BETWEEN 1 AND 2';
        } elseif ($f === 'learned') {
            $where .= ' AND EXISTS (SELECT 1 FROM vision_refs r WHERE r.product_id = p.id)';
        }
        $p = paginate("FROM products p LEFT JOIN categories c ON c.id = p.category_id $where", $params,
            "p.id, p.name, p.reference, p.barcode, p.sale_price, p.image, c.name AS category,
             (SELECT COUNT(*) FROM vision_refs r WHERE r.product_id = p.id) AS refs,
             (SELECT COUNT(*) FROM vision_refs r WHERE r.product_id = p.id AND r.source = 'vente') AS refs_sale", 'p.name', 30);
        view('vision::index', ['title' => 'Reconnaissance des articles par photo', 'p' => $p, 'q' => $q, 'cat' => $cat, 'f' => $f,
            'categories' => DB::all('SELECT id, name FROM categories WHERE active = 1 ORDER BY name'), 'stats' => VisionService::stats(),
            'withImage' => (int) DB::value("SELECT COUNT(*) FROM products p WHERE p.status = 'active' AND p.image IS NOT NULL AND p.image <> '' AND NOT EXISTS (SELECT 1 FROM vision_refs r WHERE r.product_id = p.id AND r.source = 'fiche')"),
            'scripts' => ['addons/vision/vision-teach.js']]);
    }

    /** Réglages lus par chaque appareil (petit, jamais mis en cache). */
    public function config(): void
    {
        header('Cache-Control: no-store');
        json_response(['ok' => true, 'version' => VisionService::version(), 'teach' => can('vision.teach'),
            'auto' => setting('vision_auto', '0') === '1', 'autoScore' => (float) setting('vision_seuil_auto', '0.80'),
            'margin' => (float) setting('vision_marge', '0.06'), 'learn' => setting('vision_apprendre_ventes', '1') === '1',
            'refs' => (int) DB::value('SELECT COUNT(*) FROM vision_refs')]);
    }

    /** Empreintes de tous les articles ; l'adresse contient la version : mise en cache par le navigateur. */
    public function refsIndex(): void
    {
        $v = (int) Request::query('v', 0);
        header($v && $v === VisionService::version() ? 'Cache-Control: private, max-age=86400' : 'Cache-Control: no-store');
        json_response(['ok' => true] + VisionService::index());
    }

    public function products(): void
    {
        json_response(['ok' => true, 'items' => (object) VisionService::products(explode(',', (string) Request::query('ids', '')), $this->defaultWarehouseId())]);
    }

    /** Recherche d'un article pour corriger une proposition (et l'apprendre). */
    public function search(): void
    {
        $q = trim((string) Request::query('q', ''));
        if (mb_strlen($q) < 2) {
            json_response(['ok' => true, 'items' => []]);
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $ids = array_column(DB::all("SELECT id FROM products WHERE status = 'active' AND (name LIKE ? OR reference LIKE ? OR barcode = ?) ORDER BY name LIMIT 12", [$like, $like, $q]), 'id');
        json_response(['ok' => true, 'items' => array_values(VisionService::products($ids, $this->defaultWarehouseId()))]);
    }

    /** Photos apprises d'un article (vignettes), pour la page d'apprentissage. */
    public function refs(): void
    {
        $pid = (int) Request::query('product', 0);
        json_response(['ok' => true, 'items' => array_map(fn($r) => ['id' => (int) $r['id'], 'source' => $r['source'], 'label' => VisionService::SOURCES[$r['source']] ?? $r['source'],
            'thumb' => $r['thumb'], 'date' => fdate($r['created_at'])], DB::all('SELECT id, source, thumb, created_at FROM vision_refs WHERE product_id = ? ORDER BY id DESC', [$pid]))]);
    }

    /** Articles qui ont une photo sur leur fiche mais pas encore apprise. */
    public function fiches(): void
    {
        $rows = DB::all("SELECT p.id, p.name, p.image FROM products p WHERE p.status = 'active' AND p.image IS NOT NULL AND p.image <> ''
                         AND NOT EXISTS (SELECT 1 FROM vision_refs r WHERE r.product_id = p.id AND r.source = 'fiche') ORDER BY p.name LIMIT 2000");
        json_response(['ok' => true, 'items' => array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'image' => upload_url($r['image'])], $rows)]);
    }

    public function addRef(): void
    {
        $this->jsonAttempt(fn() => VisionService::addRef((int) Request::input('product_id'), (string) Request::input('vec', ''), Request::input('thumb'), (string) Request::input('source', 'photo')));
    }

    public function deleteRef(int $id): void
    {
        $this->jsonAttempt(fn() => ['product_id' => VisionService::deleteRef($id), 'version' => VisionService::version()]);
    }

    public function clearProduct(int $id): void
    {
        $this->jsonAttempt(fn() => ['deleted' => VisionService::clearProduct($id), 'version' => VisionService::version()]);
    }

    public function log(): void
    {
        $this->jsonAttempt(function () {
            VisionService::log(array_combine($k = ['suggested_id', 'chosen_id', 'score', 'margin', 'outcome', 'context'], array_map(fn($x) => Request::input($x), $k)));
            return [];
        });
    }

    public function settings(): void
    {
        $this->attempt(fn() => VisionService::saveSettings($_POST), '/vision');
        flash('success', t('Réglages de la reconnaissance enregistrés.'));
        redirect('/vision');
    }
}
