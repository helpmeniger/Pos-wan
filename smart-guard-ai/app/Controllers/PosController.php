<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\SaleService;

final class PosController extends Controller
{
    public function index(Request $r): void
    {
        $this->view('pos/index', ['title' => 'Caisse', 'scripts' => ['js/pos.js']]);
    }

    public function store(Request $r): void
    {
        $items = $r->input('items');
        if (!is_array($items)) {
            Response::json(['ok' => false, 'error' => 'Panier vide.'], 422);
        }
        try {
            $res = SaleService::create($this->storeId(), array_map(static fn ($i) => ['product_id' => (int) ($i['product_id'] ?? 0), 'quantity' => (float) ($i['quantity'] ?? 0)], $items),
                ['payment_method' => (string) $r->input('payment_method', 'especes'), 'amount_paid' => $r->input('amount_paid')]);
        } catch (\InvalidArgumentException | \DomainException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        Response::json(['ok' => true, 'sale' => $res, 'url' => url('pos/sales/' . $res['id'])]);
    }

    public function sales(Request $r): void
    {
        [$from, $to] = $this->dateRange();
        [$limit, $offset, $page] = $this->paginate(30);
        $w = 's.store_id = :s AND s.sold_at BETWEEN :f AND :t';
        $p = ['s' => $this->storeId(), 'f' => "$from 00:00:00", 't' => "$to 23:59:59"];
        if (!Auth::can('sales.view')) { // un caissier ne voit que ses propres ventes
            $w .= ' AND s.cashier_id = :u';
            $p['u'] = Auth::id();
        }
        $total = (int) Database::value("SELECT COUNT(*) FROM sales s WHERE $w", $p);
        $sum = Database::fetch("SELECT COALESCE(SUM(total_amount),0) t FROM sales s WHERE $w AND s.status <> 'annulee'", $p);
        $rows = Database::fetchAll("SELECT s.*, u.full_name cashier, (SELECT COUNT(*) FROM sale_items i WHERE i.sale_id = s.id) lines_count
            FROM sales s LEFT JOIN users u ON u.id = s.cashier_id WHERE $w ORDER BY s.sold_at DESC LIMIT $limit OFFSET $offset", $p);
        $this->view('pos/sales', ['title' => 'Ventes', 'rows' => $rows, 'from' => $from, 'to' => $to, 'sum' => $sum['t'] ?? 0,
            'page' => $page, 'pages' => (int) ceil($total / $limit), 'total' => $total]);
    }

    private function sale(Request $r): array
    {
        $s = Database::fetch('SELECT s.*, u.full_name cashier FROM sales s LEFT JOIN users u ON u.id = s.cashier_id WHERE s.id = :id AND s.store_id = :st',
            ['id' => (int) $r->param('id'), 'st' => $this->storeId()]);
        if (!$s || (!Auth::can('sales.view') && (int) $s['cashier_id'] !== Auth::id())) {
            throw new HttpException(404, 'Vente introuvable.');
        }
        return $s;
    }

    public function show(Request $r): void
    {
        $s = $this->sale($r);
        $items = Database::fetchAll('SELECT i.*, p.name, p.unit FROM sale_items i JOIN products p ON p.id = i.product_id WHERE i.sale_id = :s', ['s' => $s['id']]);
        $this->view('pos/show', ['title' => 'Ticket ' . $s['receipt_number'], 'sale' => $s, 'items' => $items]);
    }

    public function cancel(Request $r): void
    {
        $s = $this->sale($r);
        $reason = trim((string) $r->input('reason', ''));
        if ($reason === '') {
            $this->back('error', 'Le motif d\'annulation est obligatoire.');
        }
        try {
            SaleService::cancel($this->storeId(), (int) $s['id'], $reason);
        } catch (\DomainException $e) {
            $this->back('error', $e->getMessage());
        }
        $this->back('success', 'Vente annulée ; le stock a été rétabli.', 'pos/sales/' . $s['id']);
    }

    public function refund(Request $r): void
    {
        $s = $this->sale($r);
        try {
            SaleService::refund($this->storeId(), (int) $s['id'], (int) $r->input('item_id'), (float) str_replace(',', '.', (string) $r->input('quantity')),
                mb_substr(trim((string) $r->input('reason', 'Retour client')), 0, 255));
        } catch (\DomainException $e) {
            $this->back('error', $e->getMessage());
        }
        $this->back('success', 'Retour enregistré.', 'pos/sales/' . $s['id']);
    }

    public function importForm(Request $r): void
    {
        $this->view('pos/import', ['title' => 'Importer des ventes']);
    }

    /** CSV : reference;date;code_barres;quantite;prix_unitaire;paiement (une ligne par article ; même référence = même ticket) */
    public function import(Request $r): void
    {
        $f = $r->file('csv');
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > Env::int('UPLOAD_MAX_CSV_MB', 5) * 1048576) {
            $this->back('error', 'Fichier CSV manquant ou trop volumineux.', 'pos/import');
        }
        $fh = fopen($f['tmp_name'], 'r');
        $first = (string) fgets($fh);
        $sep = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        rewind($fh);
        $header = array_map(static fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($fh, 0, $sep, '"', '') ?: []);
        $tickets = [];
        $errors = [];
        $line = 1;
        while (($row = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            $line++;
            if (count($row) < 4) {
                continue;
            }
            $d = array_combine(array_slice($header, 0, count($row)), array_slice($row, 0, count($header)));
            $pid = Database::value('SELECT id FROM products WHERE store_id = :s AND barcode = :b AND deleted_at IS NULL', ['s' => $this->storeId(), 'b' => trim((string) ($d['code_barres'] ?? ''))]);
            $ts = strtotime(str_replace('/', '-', (string) ($d['date'] ?? '')));
            if (!$pid || !$ts) {
                $errors[] = "Ligne {$line} : produit inconnu ou date invalide.";
                continue;
            }
            $ref = mb_substr(trim((string) ($d['reference'] ?? '')), 0, 80) ?: 'L' . $line;
            $tickets[$ref]['sold_at'] = date('Y-m-d H:i:s', $ts);
            $tickets[$ref]['payment'] = (string) ($d['paiement'] ?? 'especes');
            $tickets[$ref]['items'][] = ['product_id' => (int) $pid, 'quantity' => (float) str_replace(',', '.', (string) $d['quantite']),
                'unit_price' => isset($d['prix_unitaire']) && $d['prix_unitaire'] !== '' ? (float) str_replace([' ', ','], ['', '.'], (string) $d['prix_unitaire']) : null];
        }
        fclose($fh);
        $ok = 0;
        $matched = 0;
        foreach ($tickets as $ref => $t) {
            try {
                $items = array_map(static fn ($i) => array_filter($i, static fn ($v) => $v !== null), $t['items']);
                $res = SaleService::create($this->storeId(), $items, ['source' => 'import_csv', 'external_ref' => $ref, 'sold_at' => $t['sold_at'], 'payment_method' => $t['payment']]);
                $ok++;
                $matched += $res['matched_exits'];
            } catch (\Throwable $e) {
                $errors[] = "Ticket {$ref} : " . $e->getMessage();
            }
        }
        $this->back($errors ? 'warning' : 'success', "{$ok} vente(s) importée(s), {$matched} sortie(s) vidéo rapprochée(s). " . implode(' ', array_slice($errors, 0, 10)), 'pos/sales');
    }
}
