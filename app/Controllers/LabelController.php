<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Request;

/**
 * Étiquettes à codes-barres : choix des articles (recherche, catégorie, scan) et du nombre
 * d'étiquettes, informations imprimées, format (planche A4 ou rouleau thermique), modèles
 * enregistrés, aperçu, étiquette de calibrage.
 */
final class LabelController extends Controller
{
    public const FIELDS = ['barcode' => 'Code-barres', 'name' => 'Nom du produit', 'price' => 'Prix', 'reference' => 'Référence', 'company' => 'Nom du magasin'];

    /** Combinaisons proposées en un clic. */
    public const PRESETS = [
        'Code-barres seul' => ['barcode'], 'Nom seul' => ['name'], 'Prix seul' => ['price'], 'Code-barres + nom' => ['barcode', 'name'],
        'Code-barres + prix' => ['barcode', 'price'], 'Nom + prix' => ['name', 'price'], 'Code-barres + nom + prix' => ['barcode', 'name', 'price'],
        'Code-barres + nom + prix + référence' => ['barcode', 'name', 'price', 'reference'],
    ];

    private const DEFAULT = ['paper' => 'a4', 'width' => 63.5, 'height' => 38.1, 'cols' => 3, 'margin_top' => 10, 'margin_left' => 7, 'gap_x' => 2.5, 'gap_y' => 0,
        'barcode_height' => 12, 'font' => 8, 'fields' => ['barcode', 'name', 'price'], 'border' => false];

    /** Réglages contrôlés (dimensions en millimètres). */
    public static function config(array $in): array
    {
        $c = self::DEFAULT;
        $c['paper'] = ($in['paper'] ?? '') === 'roll' ? 'roll' : 'a4';
        $num = function (string $k, float $min, float $max) use ($in, &$c): void {
            if (isset($in[$k]) && is_numeric(str_replace(',', '.', (string) $in[$k]))) {
                $c[$k] = max($min, min($max, round((float) str_replace(',', '.', (string) $in[$k]), 2)));
            }
        };
        $num('width', 15, 210);
        $num('height', 8, 297);
        $num('cols', 1, 8);
        $num('margin_top', 0, 50);
        $num('margin_left', 0, 50);
        $num('gap_x', 0, 30);
        $num('gap_y', 0, 30);
        $num('barcode_height', 4, 80);
        $num('font', 5, 24);
        $c['cols'] = (int) $c['cols'];
        if ($c['paper'] === 'roll') {
            $c['cols'] = 1;
        }
        $fields = array_values(array_intersect(array_keys(self::FIELDS), (array) ($in['fields'] ?? $c['fields'])));
        $c['fields'] = $fields ?: ['barcode'];
        $c['border'] = !empty($in['border']);
        if ($c['paper'] === 'a4' && $c['margin_left'] + $c['cols'] * $c['width'] + ($c['cols'] - 1) * $c['gap_x'] > 210.5) {
            throw new BusinessException('Les étiquettes dépassent la largeur de la feuille A4 (210 mm) : réduisez la largeur, le nombre de colonnes ou les marges.');
        }
        return $c;
    }

    public function index(): void
    {
        $ids = array_filter(array_map('intval', (array) Request::query('ids', [])));
        $pre = $ids ? DB::all("SELECT id, name, reference, barcode, sale_price FROM products WHERE status = 'active' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) : [];
        $copies = max(1, min(500, (int) Request::query('copies', 1)));
        view('products/labels', ['title' => 'Étiquettes code-barres', 'categories' => DB::all('SELECT id, name FROM categories WHERE active = 1 ORDER BY name'),
            'templates' => DB::all('SELECT id, name, config FROM label_templates ORDER BY name'), 'fields' => self::FIELDS, 'presets' => self::PRESETS,
            'preselected' => array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'reference' => $p['reference'], 'barcode' => $p['barcode'], 'price' => (float) $p['sale_price'], 'qty' => $copies], $pre),
            'default' => json_decode((string) setting('label_default', ''), true) ?: self::DEFAULT, 'scripts' => ['js/labels.js']]);
    }

    /** Recherche d'articles (nom, référence, code-barres exact ou toute une catégorie). */
    public function search(): void
    {
        $q = trim((string) Request::query('q', ''));
        $code = trim((string) Request::query('code', ''));
        $cat = (int) Request::query('category', 0);
        $sql = "SELECT id, name, reference, barcode, sale_price FROM products WHERE status = 'active'";
        $params = [];
        if ($code !== '') {
            $sql .= ' AND (barcode = ? OR reference = ? OR id IN (SELECT product_id FROM product_packagings WHERE barcode = ?))';
            array_push($params, $code, $code, $code);
        } elseif ($cat > 0) {
            $sql .= ' AND (category_id = ? OR category_id IN (SELECT id FROM categories WHERE parent_id = ?))';
            array_push($params, $cat, $cat);
        } elseif (mb_strlen($q) >= 2) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $sql .= ' AND (name LIKE ? OR reference LIKE ? OR barcode LIKE ?)';
            array_push($params, $like, $like, $like);
        } else {
            json_response(['ok' => true, 'items' => []]);
        }
        $rows = DB::all($sql . ' ORDER BY name LIMIT ' . ($cat > 0 ? 1000 : 30), $params);
        json_response(['ok' => true, 'items' => array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'reference' => $p['reference'], 'barcode' => $p['barcode'], 'price' => (float) $p['sale_price']], $rows)]);
    }

    /** Planche / rouleau d'étiquettes (aperçu dans la page ou impression). */
    public function render(): void
    {
        try {
            $cfg = self::config((array) Request::input('cfg', []));
        } catch (BusinessException $e) {
            echo '<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;color:#b00;padding:20px">' . e($e->getMessage()) . '</body>';
            exit;
        }
        $test = Request::input('test') === '1';
        $labels = [];
        if ($test) {
            $labels[] = ['name' => 'ÉTIQUETTE DE TEST', 'reference' => sprintf('%s × %s mm', $cfg['width'], $cfg['height']), 'barcode' => '2100000000000', 'sale_price' => 0];
            $cfg['border'] = true;
        } else {
            $qtys = [];
            foreach ((array) Request::input('items', []) as $id => $n) {
                $n = (int) $n;
                if ((int) $id > 0 && $n > 0) {
                    $qtys[(int) $id] = min(500, $n);
                }
            }
            if ($qtys) {
                $rows = array_column(DB::all("SELECT id, name, reference, barcode, sale_price FROM products WHERE id IN (" . implode(',', array_keys($qtys)) . ')'), null, 'id');
                foreach ($qtys as $id => $n) {
                    for ($i = 0; $i < $n && isset($rows[$id]) && count($labels) < 3000; $i++) {
                        $labels[] = $rows[$id];
                    }
                }
            }
        }
        $print = Request::input('print') === '1';
        if ($print && !$test && $labels) {
            Audit::log('labels.print', null, null, ['count' => count($labels), 'paper' => $cfg['paper']]);
        }
        view('products/labels_print', ['title' => 'Étiquettes', 'labels' => $labels, 'cfg' => $cfg, 'print' => $print, 'test' => $test], null);
    }

    public function saveTemplate(): void
    {
        $this->jsonAttempt(function () {
            $name = mb_substr(trim(Request::str('name')), 0, 80);
            if ($name === '') {
                throw new BusinessException('Nom du modèle obligatoire.');
            }
            $cfg = self::config((array) Request::input('cfg', []));
            $json = json_encode($cfg, JSON_UNESCAPED_UNICODE);
            $id = DB::value('SELECT id FROM label_templates WHERE name = ?', [$name]);
            if ($id) {
                DB::update('label_templates', ['config' => $json], 'id = ?', [$id]);
            } else {
                $id = DB::insert('label_templates', ['name' => $name, 'config' => $json, 'user_id' => \App\Core\Auth::id()]);
            }
            if (Request::input('default') === true || Request::str('default') === '1') {
                setting_set('label_default', $json);
            }
            Audit::log('labels.template', null, (int) $id, ['name' => $name]);
            return ['id' => (int) $id, 'name' => $name, 'config' => $cfg];
        });
    }

    public function deleteTemplate(int $id): void
    {
        $this->jsonAttempt(function () use ($id) {
            DB::run('DELETE FROM label_templates WHERE id = ?', [$id]);
            Audit::log('labels.template_delete', null, $id);
            return [];
        });
    }
}
