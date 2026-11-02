<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class AuditController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $this->authorize('audit.view');
        $tab = $request->str('tab') === 'logins' ? 'logins' : 'audit';
        $q = $request->str('q');
        $from = $request->str('from');
        $to = $request->str('to');
        $page = max(1, $request->int('page', 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $where = ['1=1'];
        $params = [];
        $dateCol = $tab === 'logins' ? 'l.created_at' : 'a.created_at';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = "{$dateCol} >= :from";
            $params['from'] = $from . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = "{$dateCol} <= :to";
            $params['to'] = $to . ' 23:59:59';
        }

        if ($tab === 'logins') {
            if ($q !== '') {
                $where[] = '(l.identifier LIKE :q1 OR l.ip_address LIKE :q2)';
                $params += ['q1' => "%{$q}%", 'q2' => "%{$q}%"];
            }
            $sqlWhere = implode(' AND ', $where);
            $total = (int) Database::value("SELECT COUNT(*) FROM login_attempts l WHERE {$sqlWhere}", $params);
            $rows = Database::fetchAll(
                "SELECT l.*, u.full_name FROM login_attempts l LEFT JOIN users u ON u.id = l.user_id
                  WHERE {$sqlWhere} ORDER BY l.id DESC LIMIT " . self::PER_PAGE . " OFFSET {$offset}",
                $params
            );
        } else {
            if ($q !== '') {
                $where[] = '(a.action LIKE :q1 OR a.description LIKE :q2 OR u.full_name LIKE :q3 OR a.ip_address LIKE :q4)';
                $params += ['q1' => "%{$q}%", 'q2' => "%{$q}%", 'q3' => "%{$q}%", 'q4' => "%{$q}%"];
            }
            $sqlWhere = implode(' AND ', $where);
            $total = (int) Database::value("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE {$sqlWhere}", $params);
            $rows = Database::fetchAll(
                "SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                  WHERE {$sqlWhere} ORDER BY a.id DESC LIMIT " . self::PER_PAGE . " OFFSET {$offset}",
                $params
            );
        }
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        return $this->view('audit/index', compact('tab', 'rows', 'q', 'from', 'to', 'page', 'pages', 'total'));
    }
}
