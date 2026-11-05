<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\BusinessException;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Services\StockEntry;

/** Gestion des articles → Ajout de stock rapide ; Import du stock depuis Excel / CSV. */
final class StockEntryController extends Controller
{
    // ------------------------------------------------------------------ ajout rapide

    public function quick(): void
    {
        $f = ['user' => Request::int('user') ?: null, 'from' => (string) Request::query('from', ''), 'to' => (string) Request::query('to', '')];
        foreach (['from', 'to'] as $k) {
            if ($f[$k] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) {
                $f[$k] = '';
            }
        }
        $pid = (int) Request::query('product', 0);
        view('stock/quick', [
            'title' => 'Ajout de stock rapide', 'warehouses' => $this->warehouses(), 'wh' => $this->defaultWarehouseId(),
            'history' => StockEntry::quickHistory($f), 'f' => $f,
            'users' => DB::all("SELECT DISTINCT u.id, u.full_name FROM stock_movements m JOIN users u ON u.id = m.user_id WHERE m.type = 'quick_entry' ORDER BY u.full_name"),
            'preset' => $pid ? (DB::one("SELECT id, name FROM products WHERE id = ? AND status = 'active'", [$pid]) ?: null) : null,
            'scripts' => ['js/stock-quick.js'], 'canCost' => can('products.cost_view'),
        ]);
    }

    public function card(int $id): void
    {
        $this->jsonAttempt(function () use ($id) {
            $p = StockEntry::productCard($id);
            if (!can('products.cost_view')) {
                unset($p['cost_price']);
            }
            return ['product' => $p];
        });
    }

    public function quickStore(): void
    {
        $this->jsonAttempt(function () {
            $d = Request::json() ?: $_POST;
            $cost = isset($d['cost']) && trim((string) $d['cost']) !== '' && can('products.cost_view') ? (float) str_replace(',', '.', (string) $d['cost']) : null;
            $r = StockEntry::quickAdd((int) ($d['product_id'] ?? 0), (int) ($d['warehouse_id'] ?? 0), (float) str_replace(',', '.', (string) ($d['quantity'] ?? '0')),
                $cost, isset($d['lot']) ? (string) $d['lot'] : null, isset($d['expiry']) && $d['expiry'] !== '' ? (string) $d['expiry'] : null, (string) ($d['reason'] ?? ''));
            $p = StockEntry::productCard((int) $d['product_id']);
            return $r + ['product' => ['id' => $p['id'], 'name' => $p['name'], 'reference' => $p['reference'], 'total' => $p['total'], 'levels' => $p['levels']],
                'warehouse' => DB::value('SELECT name FROM warehouses WHERE id = ?', [(int) $d['warehouse_id']]), 'user' => $this->user()['full_name'], 'at' => date('d/m/Y H:i')];
        });
    }

    // ------------------------------------------------------------------ import Excel / CSV

    public function importForm(): void
    {
        view('stock/import', [
            'title' => 'Import du stock (Excel / CSV)', 'warehouses' => $this->warehouses(), 'wh' => $this->defaultWarehouseId(),
            'imports' => DB::all('SELECT i.*, u.full_name AS user_name, w.name AS warehouse FROM stock_imports i JOIN users u ON u.id = i.user_id LEFT JOIN warehouses w ON w.id = i.warehouse_id ORDER BY i.id DESC LIMIT 100'),
            'preview' => null,
        ]);
    }

    private function checkFile(array $file): array
    {
        if (empty($file) || !isset($file['error']) || is_array($file['error'])) {
            throw new BusinessException('Choisissez un fichier.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new BusinessException(in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'Fichier trop volumineux pour ce serveur (limite PHP : ' . ini_get('upload_max_filesize') . ').' : 'Aucun fichier reçu.');
        }
        if (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') {
            throw new BusinessException('Fichier invalide.');
        }
        if ($file['size'] > 10 * 1048576) {
            throw new BusinessException('Fichier trop volumineux (10 Mo au plus).');
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        $ok = match ($ext) {
            'xlsx' => in_array($mime, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], true),
            'csv', 'txt' => str_starts_with($mime, 'text/') || in_array($mime, ['application/csv', 'application/vnd.ms-excel', 'application/octet-stream'], true),
            'xls' => true, // message explicatif plus loin
            default => false,
        };
        if (!$ok) {
            throw new BusinessException('Format non accepté : fichier Excel (.xlsx) ou CSV.');
        }
        return [$ext, (string) $file['name']];
    }

    /** Étape 1 : envoi du fichier → aperçu ligne par ligne, rien n'est encore modifié. */
    public function importPreview(): void
    {
        $mode = Request::str('mode') === 'set' ? 'set' : 'add';
        $wh = Request::int('warehouse_id');
        try {
            [$ext, $name] = $this->checkFile($_FILES['file'] ?? []);
            $rows = StockEntry::readFile($_FILES['file']['tmp_name'], $name);
            $lines = StockEntry::analyze($rows, $mode, $wh);
        } catch (BusinessException $e) {
            $this->fail($e->getMessage(), '/stock/import');
        }
        $token = bin2hex(random_bytes(16));
        $dir = STORAGE_PATH . '/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        foreach (glob($dir . '/stockimport_*') ?: [] as $old) {
            if (filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }
        $path = $dir . '/stockimport_' . $token . '.' . $ext;
        PHP_SAPI === 'cli' ? copy($_FILES['file']['tmp_name'], $path) : move_uploaded_file($_FILES['file']['tmp_name'], $path);
        Session::set('stock_import', ['token' => $token, 'path' => $path, 'name' => $name, 'mode' => $mode, 'wh' => $wh, 'at' => time()]);
        view('stock/import', [
            'title' => 'Import du stock : vérification', 'warehouses' => $this->warehouses(), 'wh' => $wh, 'imports' => [],
            'preview' => ['lines' => $lines, 'name' => $name, 'mode' => $mode, 'token' => $token,
                'count' => array_count_values(array_column($lines, 'status')) + ['ok' => 0, 'skip' => 0, 'error' => 0]],
        ]);
    }

    /** Étape 2 : confirmation → mouvements de stock, import conservé avec son fichier. */
    public function importApply(): void
    {
        $s = Session::get('stock_import');
        if (!$s || !hash_equals((string) $s['token'], Request::str('token')) || !is_file((string) $s['path']) || time() - (int) $s['at'] > 3600) {
            $this->fail('Aperçu expiré : envoyez de nouveau le fichier.', '/stock/import');
        }
        try {
            $r = StockEntry::apply($s['path'], $s['name'], $s['mode'], (int) $s['wh'], Request::str('note'));
        } catch (BusinessException $e) {
            $this->fail($e->getMessage(), '/stock/import');
        }
        @unlink($s['path']);
        Session::forget('stock_import');
        flash('success', sprintf('Import n° %d enregistré : %d ligne(s) appliquée(s) sur %d. Le détail et le fichier d\'origine sont conservés.', $r['id'], $r['applied'], $r['total']));
        redirect('/stock/imports/' . $r['id']);
    }

    public function importShow(int $id): void
    {
        $i = DB::one('SELECT i.*, u.full_name AS user_name, w.name AS warehouse FROM stock_imports i JOIN users u ON u.id = i.user_id LEFT JOIN warehouses w ON w.id = i.warehouse_id WHERE i.id = ?', [$id]) ?? throw new HttpException(404);
        view('stock/import_show', ['title' => 'Import du stock n° ' . $id, 'i' => $i,
            'rows' => DB::all('SELECT r.*, p.name AS product, p.reference, w.name AS warehouse FROM stock_import_rows r LEFT JOIN products p ON p.id = r.product_id LEFT JOIN warehouses w ON w.id = r.warehouse_id WHERE r.import_id = ? ORDER BY r.line_no', [$id])]);
    }

    public function importFile(int $id): void
    {
        $i = DB::one('SELECT * FROM stock_imports WHERE id = ?', [$id]) ?? throw new HttpException(404);
        $path = STORAGE_PATH . '/imports/' . basename((string) $i['stored_file']);
        if (!is_file($path)) {
            throw new HttpException(404, 'Fichier d\'origine introuvable sur le serveur.');
        }
        \App\Core\Audit::log('stock.import_download', 'stock_import', $id);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="import-' . $id . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $i['file_name']) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function template(): void
    {
        $bin = StockEntry::template(Request::int('warehouse_id') ?: $this->defaultWarehouseId());
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="modele-stock-' . date('Y-m-d') . '.xlsx"');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    }
}
