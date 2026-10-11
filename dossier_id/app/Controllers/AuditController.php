<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Models\Person;
use App\Support\Labels;
use App\Support\Paginator;

final class AuditController extends Controller
{
    private function where(array $f): array
    {
        $w = ['1=1'];
        $p = [];
        if ($f['username'] !== '') {
            $w[] = 'username LIKE :u';
            $p['u'] = Person::like($f['username']) . '%';
        }
        if ($f['action'] !== '') {
            $w[] = 'action LIKE :a';
            $p['a'] = Person::like($f['action']) . '%';
        }
        if ($f['entity_id'] !== '' && ctype_digit($f['entity_id'])) {
            $w[] = "entity_type = 'person' AND entity_id = :e";
            $p['e'] = (int) $f['entity_id'];
        }
        foreach (['date_from' => 'created_at >=', 'date_to' => 'created_at <'] as $k => $sql) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) {
                $w[] = "$sql :$k";
                $p[$k] = $k === 'date_to' ? date('Y-m-d', strtotime($f[$k] . ' +1 day')) : $f[$k];
            }
        }
        return [implode(' AND ', $w), $p];
    }

    public function index(): string
    {
        Auth::require('audit.view');
        $f = $this->filters(['username', 'action', 'entity_id', 'date_from', 'date_to']);
        [$where, $params] = $this->where($f);
        $pager = Paginator::fromRequest((int) Database::value("SELECT COUNT(*) FROM audit_log WHERE $where", $params), 50);
        $rows = Database::all(
            "SELECT * FROM audit_log WHERE $where ORDER BY id DESC LIMIT {$pager->perPage} OFFSET {$pager->offset}",
            $params
        );
        Audit::log('audit.view', null, null, ['filtres' => array_keys(array_filter($f))]);
        return $this->view('audit/index', [
            'title' => "Journal d'audit", 'rows' => $rows, 'pager' => $pager, 'f' => $f,
            'chain' => Session::get('_chain_result'),
            'actions' => Labels::AUDIT_ACTIONS,
        ]);
    }

    public function export(): Response
    {
        Auth::require('audit.view');
        Auth::require('export.csv');
        $f = $this->filters(['username', 'action', 'entity_id', 'date_from', 'date_to']);
        [$where, $params] = $this->where($f);
        $rows = Database::all("SELECT * FROM audit_log WHERE $where ORDER BY id DESC LIMIT 50000", $params);
        Audit::log('audit.export', null, null, ['lignes' => count($rows)]);
        return Response::csv(
            ['N°', 'Date', 'Utilisateur', 'Action', 'Libellé', 'Objet', 'Id objet', 'Poste', 'Détails', 'Empreinte'],
            array_map(fn ($r) => [$r['id'], $r['created_at'], $r['username'], $r['action'], Labels::AUDIT_ACTIONS[$r['action']] ?? '',
                $r['entity_type'], $r['entity_id'], $r['ip_address'], $r['details'], $r['hash']], $rows),
            'journal_audit_' . date('Ymd_His') . '.csv'
        );
    }

    public function verify(): never
    {
        Auth::require('audit.view');
        $res = Audit::verifyChain();
        Audit::log('audit.verify', null, null, ['resultat' => $res['ok'] ? 'intègre' : 'rompu', 'entrees' => $res['count'], 'rupture' => $res['broken_at']]);
        Session::set('_chain_result', $res + ['at' => date('Y-m-d H:i:s')]);
        $this->redirect('audit.index');
    }
}
