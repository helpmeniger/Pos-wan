<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;

final class DetectionController extends Controller
{
    public function index(Request $r): void
    {
        [$from, $to] = $this->dateRange();
        [$limit, $offset, $page] = $this->paginate(50);
        $w = 'c.store_id = :s AND d.detected_at BETWEEN :f AND :t';
        $p = ['s' => $this->storeId(), 'f' => "$from 00:00:00", 't' => "$to 23:59:59"];
        if ($cid = (int) $r->query('camera_id')) { $w .= ' AND d.camera_id = :c'; $p['c'] = $cid; }
        if ($pid = (int) $r->query('product_id')) { $w .= ' AND d.product_id = :p'; $p['p'] = $pid; }
        if ($r->query('unidentified')) { $w .= ' AND d.product_id IS NULL'; }
        $total = (int) Database::value("SELECT COUNT(*) FROM detection_results d JOIN cameras c ON c.id = d.camera_id WHERE $w", $p);
        $rows = Database::fetchAll("SELECT d.*, c.name camera, pr.name product, t.track_number, t.frames_seen, t.end_reason
            FROM detection_results d JOIN cameras c ON c.id = d.camera_id LEFT JOIN products pr ON pr.id = d.product_id LEFT JOIN object_tracks t ON t.id = d.track_id
            WHERE $w ORDER BY d.detected_at DESC LIMIT $limit OFFSET $offset", $p);
        $this->view('detections/index', ['title' => 'Détections', 'rows' => $rows, 'from' => $from, 'to' => $to, 'page' => $page,
            'pages' => (int) ceil($total / $limit), 'total' => $total] + $this->filters());
    }

    public function exits(Request $r): void
    {
        [$from, $to] = $this->dateRange();
        [$limit, $offset, $page] = $this->paginate(50);
        $w = 'e.store_id = :s AND e.occurred_at BETWEEN :f AND :t';
        $p = ['s' => $this->storeId(), 'f' => "$from 00:00:00", 't' => "$to 23:59:59"];
        if ($cid = (int) $r->query('camera_id')) { $w .= ' AND e.camera_id = :c'; $p['c'] = $cid; }
        if ($pid = (int) $r->query('product_id')) { $w .= ' AND e.product_id = :p'; $p['p'] = $pid; }
        if (in_array($r->query('direction'), ['sortie', 'entree', 'ambigu', 'perte_suivi'], true)) { $w .= ' AND e.direction = :d'; $p['d'] = $r->query('direction'); }
        if (in_array($r->query('recon'), ['vente_trouvee', 'aucune_vente', 'incertain', 'non_applicable'], true)) { $w .= ' AND e.reconciliation_status = :rc'; $p['rc'] = $r->query('recon'); }
        $total = (int) Database::value("SELECT COUNT(*) FROM exit_events e WHERE $w", $p);
        $counts = Database::fetch("SELECT SUM(direction='sortie') s, SUM(direction='entree') en, SUM(direction IN ('ambigu','perte_suivi')) u FROM exit_events e WHERE $w", $p);
        $rows = Database::fetchAll("SELECT e.*, c.name camera, pr.name product, l.name line, a.id alert_id, a.level, a.status alert_status
            FROM exit_events e JOIN cameras c ON c.id = e.camera_id LEFT JOIN products pr ON pr.id = e.product_id LEFT JOIN virtual_lines l ON l.id = e.virtual_line_id
            LEFT JOIN alerts a ON a.exit_event_id = e.id WHERE $w ORDER BY e.occurred_at DESC LIMIT $limit OFFSET $offset", $p);
        $this->view('detections/exits', ['title' => 'Sorties observées', 'rows' => $rows, 'counts' => $counts, 'from' => $from, 'to' => $to,
            'page' => $page, 'pages' => (int) ceil($total / $limit), 'total' => $total] + $this->filters());
    }

    private function filters(): array
    {
        $s = $this->storeId();
        return ['cameras' => Database::fetchAll('SELECT id, name FROM cameras WHERE store_id = :s ORDER BY name', ['s' => $s]),
            'products' => Database::fetchAll('SELECT id, name FROM products WHERE store_id = :s AND deleted_at IS NULL ORDER BY name', ['s' => $s])];
    }
}
