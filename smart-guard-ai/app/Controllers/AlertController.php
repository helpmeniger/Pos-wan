<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AlertService;
use App\Services\StockService;

final class AlertController extends Controller
{
    public function index(Request $r): void
    {
        [$from, $to] = $this->dateRange('-7 days');
        [$limit, $offset, $page] = $this->paginate(30);
        $w = 'a.store_id = :s AND a.occurred_at BETWEEN :f AND :t';
        $p = ['s' => $this->storeId(), 'f' => "$from 00:00:00", 't' => "$to 23:59:59"];
        $status = $r->query('status', 'ouvertes');
        if ($status === 'ouvertes') {
            $w .= " AND a.status IN ('nouvelle','en_examen')";
        } elseif (in_array($status, AlertService::STATUSES, true)) {
            $w .= ' AND a.status = :st';
            $p['st'] = $status;
        }
        $level = $r->query('level', '');
        if (in_array($level, ['information', 'a_verifier', 'prioritaire'], true)) {
            $w .= ' AND a.level = :lv';
            $p['lv'] = $level;
        } elseif ($level === '') {
            $w .= " AND a.level <> 'information'";
        }
        if ($cid = (int) $r->query('camera_id')) { $w .= ' AND a.camera_id = :c'; $p['c'] = $cid; }
        if ($pid = (int) $r->query('product_id')) { $w .= ' AND a.product_id = :p'; $p['p'] = $pid; }
        $total = (int) Database::value("SELECT COUNT(*) FROM alerts a WHERE $w", $p);
        $rows = Database::fetchAll("SELECT a.*, c.name camera, pr.name product, e.snapshot_id FROM alerts a LEFT JOIN cameras c ON c.id = a.camera_id
            LEFT JOIN products pr ON pr.id = a.product_id LEFT JOIN exit_events e ON e.id = a.exit_event_id
            WHERE $w ORDER BY FIELD(a.level,'prioritaire','a_verifier','information'), a.occurred_at DESC LIMIT $limit OFFSET $offset", $p);
        $s = $this->storeId();
        $this->view('alerts/index', ['title' => 'Alertes', 'rows' => $rows, 'from' => $from, 'to' => $to, 'status' => $status, 'level' => $level,
            'page' => $page, 'pages' => (int) ceil($total / $limit), 'total' => $total,
            'cameras' => Database::fetchAll('SELECT id, name FROM cameras WHERE store_id = :s ORDER BY name', ['s' => $s]),
            'products' => Database::fetchAll('SELECT id, name FROM products WHERE store_id = :s AND deleted_at IS NULL ORDER BY name', ['s' => $s])]);
    }

    private function alert(Request $r): array
    {
        $a = Database::fetch('SELECT * FROM alerts WHERE id = :id AND store_id = :s', ['id' => (int) $r->param('id'), 's' => $this->storeId()]);
        if (!$a) {
            throw new HttpException(404, 'Alerte introuvable.');
        }
        return $a;
    }

    public function show(Request $r): void
    {
        $a = $this->alert($r);
        $exit = $a['exit_event_id'] ? Database::fetch('SELECT e.*, l.name line FROM exit_events e LEFT JOIN virtual_lines l ON l.id = e.virtual_line_id WHERE e.id = :id', ['id' => $a['exit_event_id']]) : null;
        $snapshot = $exit && $exit['snapshot_id'] ? Database::fetch('SELECT * FROM video_records WHERE id = :id AND deleted_at IS NULL', ['id' => $exit['snapshot_id']]) : null;
        $clip = $exit && $exit['clip_id'] ? Database::fetch('SELECT * FROM video_records WHERE id = :id AND deleted_at IS NULL', ['id' => $exit['clip_id']]) : null;
        $sale = $exit && $exit['matched_sale_item_id'] ? Database::fetch('SELECT s.receipt_number, s.sold_at, si.quantity, s.id sale_id FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.id = :id', ['id' => $exit['matched_sale_item_id']]) : null;
        $reviews = Database::fetchAll('SELECT r.*, u.full_name FROM alert_reviews r LEFT JOIN users u ON u.id = r.user_id WHERE r.alert_id = :a ORDER BY r.id', ['a' => $a['id']]);
        $this->view('alerts/show', ['title' => 'Alerte #' . $a['id'], 'a' => $a, 'exit' => $exit, 'snapshot' => $snapshot, 'clip' => $clip, 'sale' => $sale, 'reviews' => $reviews,
            'camera' => $a['camera_id'] ? Database::value('SELECT name FROM cameras WHERE id = :id', ['id' => $a['camera_id']]) : null,
            'product' => $a['product_id'] ? Database::fetch('SELECT id, name, unit FROM products WHERE id = :id', ['id' => $a['product_id']]) : null]);
    }

    public function review(Request $r): void
    {
        $a = $this->alert($r);
        $status = (string) $r->input('status');
        $comment = mb_substr(trim((string) $r->input('comment', '')), 0, 1000) ?: null;
        if (!in_array($status, AlertService::STATUSES, true)) {
            $this->back('error', 'Statut invalide.');
        }
        if (in_array($status, AlertService::CLOSING, true) && !Auth::can('alerts.close')) {
            throw new HttpException(403, 'Vous n\'êtes pas autorisé à clôturer une alerte.');
        }
        if ($status === 'anomalie_confirmee' && !$comment) {
            $this->back('error', 'Un commentaire est obligatoire pour confirmer une anomalie (décrivez la vérification effectuée).');
        }
        $stock = null;
        $type = (string) $r->input('stock_type', '');
        if ($type !== '') {
            if (!Auth::can('stock.manage')) {
                throw new HttpException(403, 'Droit « stock » requis pour enregistrer un mouvement.');
            }
            if (!in_array($type, ['sortie_autorisee', 'mouvement_observe', 'mouvement_non_explique', 'casse'], true)) {
                $this->back('error', 'Type de mouvement invalide.');
            }
            $q = (float) str_replace(',', '.', (string) $r->input('stock_qty', '1'));
            if ($q <= 0 || $q > 10000) {
                $this->back('error', 'Quantité invalide.');
            }
            $stock = ['type' => $type, 'quantity' => $q];
        }
        AlertService::review($a, $status, $comment, $stock);
        $this->flash('success', 'Vérification enregistrée.' . ($stock ? ' Le mouvement de stock a été enregistré.' : ''));
        Response::redirect('alerts/' . $a['id']);
    }
}
