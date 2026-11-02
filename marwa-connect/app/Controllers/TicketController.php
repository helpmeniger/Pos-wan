<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\AuditService;
use App\Services\ResellerService;
use App\Services\TicketPrintService;
use App\Services\TicketService;
use Throwable;

final class TicketController extends Controller
{
    private const PER_PAGE = 50;

    private function filters(Request $request): array
    {
        $f = [
            'q' => $request->str('q'), 'status' => $request->str('status'), 'offer_id' => $request->int('offer_id'),
            'router_id' => $request->int('router_id', active_router_id()), 'batch_id' => $request->int('batch_id'), 'reseller_id' => $request->int('reseller_id'),
        ];
        $where = ['1=1'];
        $p = [];
        if ($f['q'] !== '') {
            $where[] = '(t.username LIKE :q OR t.customer_phone LIKE :q2)';
            $p['q'] = '%' . $f['q'] . '%';
            $p['q2'] = '%' . $f['q'] . '%';
        }
        if (isset(TicketService::STATUSES[$f['status']])) {
            $where[] = 't.status = :st';
            $p['st'] = $f['status'];
        } elseif ($f['status'] === 'not_pushed') {
            $where[] = 't.on_router = 0';
        }
        foreach (['offer_id', 'router_id', 'batch_id', 'reseller_id'] as $k) {
            if ($f[$k] > 0) {
                $where[] = "t.{$k} = :{$k}";
                $p[$k] = $f[$k];
            }
        }
        return [$f, implode(' AND ', $where), $p];
    }

    public function index(Request $request): Response
    {
        $this->authorize('tickets.view');
        [$f, $where, $p] = $this->filters($request);
        $page = max(1, $request->int('page', 1));
        $total = (int) Database::value("SELECT COUNT(*) FROM tickets t WHERE {$where}", $p);
        $tickets = Database::fetchAll(
            "SELECT t.*, o.name AS offer_name, r.name AS router_name, rs.business_name AS reseller_name, b.batch_code
               FROM tickets t JOIN offers o ON o.id = t.offer_id JOIN routers r ON r.id = t.router_id
               LEFT JOIN resellers rs ON rs.id = t.reseller_id LEFT JOIN ticket_batches b ON b.id = t.batch_id
              WHERE {$where} ORDER BY t.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $p);
        $counts = array_column(Database::fetchAll("SELECT t.status, COUNT(*) n FROM tickets t WHERE {$where} GROUP BY t.status", $p), 'n', 'status');
        return $this->view('tickets/index', [
            'tickets' => $tickets, 'f' => $f, 'page' => $page, 'pages' => (int) ceil($total / self::PER_PAGE), 'total' => $total, 'counts' => $counts,
            'offers' => Database::fetchAll('SELECT id, name FROM offers ORDER BY price'),
            'routers' => Database::fetchAll('SELECT id, name FROM routers WHERE deleted_at IS NULL ORDER BY name'),
            'templates' => Database::fetchAll('SELECT id, name FROM ticket_print_templates ORDER BY is_default DESC, id'),
        ]);
    }

    public function generateForm(Request $request): Response
    {
        $this->authorize('tickets.generate');
        return $this->view('tickets/generate', [
            'offers' => Database::fetchAll("SELECT * FROM offers WHERE status = 'active' ORDER BY sort_order, price"),
            'routers' => Database::fetchAll("SELECT id, name, connection_state FROM routers WHERE deleted_at IS NULL AND status = 'active' ORDER BY is_default DESC, name"),
            'resellers' => can('resellers.manage') ? Database::fetchAll("SELECT id, business_name FROM resellers WHERE status = 'active' ORDER BY business_name") : [],
            'sel' => ['offer_id' => $request->int('offer_id'), 'router_id' => $request->int('router_id', active_router_id())],
        ]);
    }

    public function generate(Request $request): Response
    {
        $this->authorize('tickets.generate');
        $d = $this->validate($request, [
            'offer_id' => 'required|integer|exists:offers,id', 'router_id' => 'required|integer|exists:routers,id',
            'quantity' => 'required|regex:/^(\d+|custom)$/', 'prefix' => 'regex:/^[A-Za-z0-9]{0,10}$/',
            'username_length' => 'required|integer', 'password_length' => 'required|integer',
            'charset' => 'required|in:numeric,alpha,alphanumeric,alphanumeric_upper',
            'activation_mode' => 'required|in:first_login,first_auth,on_generation',
            'validity_mode' => 'required|in:elapsed_time,cumulative_uptime,data_quota,fixed_date',
            'shared_users' => 'required|integer', 'expires_at' => 'regex:/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', 'reseller_id' => 'integer',
        ], ['offer_id' => 'Offre', 'router_id' => 'Routeur', 'quantity' => 'Quantité', 'prefix' => 'Préfixe', 'expires_at' => "Date d'expiration"]);
        $qty = $request->str('quantity') === 'custom' ? $request->int('custom_quantity') : (int) $d['quantity'];
        if ($qty < 1 || $qty > 5000) {
            Session::flash('danger', 'La quantité doit être comprise entre 1 et 5 000.');
            Session::flashOld($request->all());
            return $this->redirect('/tickets/generate');
        }
        $d['quantity'] = $qty;
        $d['same_credentials'] = (bool) $request->input('same_credentials');
        $d['reseller_id'] = (int) ($d['reseller_id'] ?? 0) ?: null;
        if ($d['reseller_id'] && !can('resellers.manage')) {
            $d['reseller_id'] = null;
        }
        @set_time_limit(300);
        try {
            $r = TicketService::generate($d, Auth::id());
        } catch (Throwable $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashOld($request->all());
            return $this->redirect('/tickets/generate');
        }
        if ($r['pushed'] === $r['created']) {
            Session::flash('success', "{$r['created']} ticket(s) générés et créés sur le routeur (lot {$r['batch_code']}).");
        } elseif ($r['pushed'] > 0) {
            Session::flash('warning', "{$r['pushed']}/{$r['created']} ticket(s) créés sur le routeur. Les autres seront re-poussés automatiquement. Erreur : {$r['error']}");
        } else {
            Session::flash('danger', "{$r['created']} ticket(s) enregistrés mais NON créés sur le routeur (non vendables tant qu'ils ne sont pas poussés) : {$r['error']}");
        }
        return $this->redirect('/tickets/batches/' . $r['batch_id']);
    }

    public function batches(Request $request): Response
    {
        $this->authorize('tickets.view');
        $batches = Database::fetchAll(
            "SELECT b.*, o.name AS offer_name, o.price, r.name AS router_name, u.full_name AS creator, rs.business_name AS reseller_name,
                    (SELECT COUNT(*) FROM tickets t WHERE t.batch_id = b.id AND t.status = 'available') AS available
               FROM ticket_batches b JOIN offers o ON o.id = b.offer_id JOIN routers r ON r.id = b.router_id
               LEFT JOIN users u ON u.id = b.created_by LEFT JOIN resellers rs ON rs.id = b.reseller_id
              ORDER BY b.id DESC LIMIT 200");
        return $this->view('tickets/batches', compact('batches'));
    }

    public function batch(Request $request, string $id): Response
    {
        $this->authorize('tickets.view');
        $batch = Database::fetch(
            'SELECT b.*, o.name AS offer_name, o.price, r.name AS router_name, u.full_name AS creator FROM ticket_batches b
               JOIN offers o ON o.id = b.offer_id JOIN routers r ON r.id = b.router_id LEFT JOIN users u ON u.id = b.created_by WHERE b.id = :id', ['id' => (int) $id]);
        $this->notFoundUnless($batch, 'Lot introuvable.');
        $counts = array_column(Database::fetchAll('SELECT status, COUNT(*) n FROM tickets WHERE batch_id = :b GROUP BY status', ['b' => $batch['id']]), 'n', 'status');
        $errors = Database::fetchAll('SELECT username, push_error FROM tickets WHERE batch_id = :b AND on_router = 0 LIMIT 20', ['b' => $batch['id']]);
        $templates = Database::fetchAll('SELECT id, name, format FROM ticket_print_templates ORDER BY is_default DESC, id');
        return $this->view('tickets/batch', compact('batch', 'counts', 'errors', 'templates'));
    }

    public function pushBatch(Request $request, string $id): Response
    {
        $this->authorize('tickets.generate');
        @set_time_limit(300);
        $r = TicketService::pushBatch((int) $id);
        Session::flash($r['failed'] ? 'warning' : 'success', "{$r['pushed']} ticket(s) créé(s) sur le routeur" . ($r['failed'] ? ", {$r['failed']} en échec : {$r['error']}" : '.'));
        return $this->redirect('/tickets/batches/' . (int) $id);
    }

    /** Résout la sélection de tickets à imprimer/exporter (ids, lot ou filtres). */
    private function selection(Request $request): array
    {
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));
        if (!$ids && $request->int('batch_id')) {
            $sql = 'SELECT id FROM tickets WHERE batch_id = :b';
            if ($request->str('only') !== 'all') {
                $sql .= " AND status = 'available'";
            }
            $ids = array_column(Database::fetchAll($sql . ' ORDER BY id', ['b' => $request->int('batch_id')]), 'id');
        }
        if (!$ids && $request->int('sale_id')) {
            $ids = array_column(Database::fetchAll('SELECT ticket_id FROM sale_items WHERE sale_id = :s', ['s' => $request->int('sale_id')]), 'ticket_id');
        }
        if (!$ids) {
            [, $where, $p] = $this->filters($request);
            $ids = array_column(Database::fetchAll("SELECT t.id FROM tickets t WHERE {$where} ORDER BY t.id LIMIT 2000", $p), 'id');
        }
        // Un revendeur ne peut imprimer que son propre stock.
        if (!can('tickets.view') && can('reseller.portal')) {
            $res = ResellerService::forUser((int) Auth::id());
            if (!$res) {
                throw new HttpException(403);
            }
            $ids = $ids ? array_column(Database::fetchAll('SELECT id FROM tickets WHERE reseller_id = :r AND id IN (' . implode(',', array_map('intval', $ids)) . ')', ['r' => $res['id']]), 'id') : [];
        }
        return array_slice(array_map('intval', $ids), 0, 2000);
    }

    public function print(Request $request): Response
    {
        $this->authorize('tickets.print');
        $tpl = TicketPrintService::template($request->int('template_id') ?: null);
        if ($request->str('format') && isset(TicketPrintService::FORMATS[$request->str('format')])) {
            $tpl['format'] = $request->str('format');
        }
        $ids = $this->selection($request);
        $tickets = TicketPrintService::prepare($ids);
        AuditService::log('tickets.print', 'ticket', null, 'Impression de ' . count($tickets) . ' ticket(s)');
        if ($request->str('output') === 'pdf') {
            $pdf = TicketPrintService::pdf($tickets, $tpl);
            return new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="tickets-' . date('Ymd-His') . '.pdf"']);
        }
        return Response::html(View::render('tickets/print', ['tickets' => $tickets, 'tpl' => $tpl], null));
    }

    public function export(Request $request): Response
    {
        $this->authorize('tickets.print');
        $ids = $this->selection($request);
        $rows = $ids ? Database::fetchAll('SELECT t.*, o.name AS offer_name, r.name AS router_name FROM tickets t JOIN offers o ON o.id = t.offer_id JOIN routers r ON r.id = t.router_id WHERE t.id IN (' . implode(',', $ids) . ') ORDER BY t.id') : [];
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Identifiant', 'Mot de passe', 'Offre', 'Prix', 'Routeur', 'Statut', 'Créé le', 'Vendu le', '1re utilisation', 'Expire le'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [$r['username'], Crypto::decrypt($r['password_enc']), $r['offer_name'], (float) $r['price'], $r['router_name'],
                TicketService::STATUSES[$r['status']] ?? $r['status'], $r['created_at'], $r['sold_at'], $r['first_used_at'], $r['expires_at']], ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        AuditService::log('tickets.export', 'ticket', null, 'Export CSV de ' . count($rows) . ' ticket(s)');
        return new Response((string) $csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="tickets-' . date('Ymd-His') . '.csv"']);
    }

    public function show(Request $request, string $id): Response
    {
        $this->authorize('tickets.view');
        $t = Database::fetch('SELECT t.*, o.name AS offer_name, r.name AS router_name, b.batch_code, s.reference AS sale_ref, rs.business_name AS reseller_name
            FROM tickets t JOIN offers o ON o.id = t.offer_id JOIN routers r ON r.id = t.router_id LEFT JOIN ticket_batches b ON b.id = t.batch_id
            LEFT JOIN sales s ON s.id = t.sale_id LEFT JOIN resellers rs ON rs.id = t.reseller_id WHERE t.id = :id', ['id' => (int) $id]);
        $this->notFoundUnless($t, 'Ticket introuvable.');
        $t['password'] = can('tickets.print') ? Crypto::decrypt($t['password_enc']) : '••••••';
        $sessions = Database::fetchAll('SELECT ws.*, r.name AS router_name FROM wifi_sessions ws JOIN routers r ON r.id = ws.router_id WHERE ws.username = :u ORDER BY ws.login_at DESC LIMIT 50', ['u' => $t['username']]);
        $audit = Database::fetchAll("SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.entity_type = 'ticket' AND a.entity_id = :id ORDER BY a.id DESC", ['id' => $t['id']]);
        return $this->view('tickets/show', compact('t', 'sessions', 'audit'));
    }

    public function action(Request $request, string $id): Response
    {
        $this->authorize('tickets.manage', 'hotspot.manage');
        $t = Database::fetch('SELECT * FROM tickets WHERE id = :id', ['id' => (int) $id]);
        $this->notFoundUnless($t);
        try {
            $new = TicketService::changeStatus($t, $request->str('action'), Auth::id());
            Session::flash('success', 'Ticket mis à jour : ' . (TicketService::STATUSES[explode(' ', $new)[0]] ?? $new) . substr($new, strlen(explode(' ', $new)[0])));
        } catch (Throwable $e) {
            Session::flash('danger', $e->getMessage());
        }
        return $this->back($request, '/tickets/' . $id);
    }

    public function bulk(Request $request): Response
    {
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));
        $action = $request->str('bulk_action');
        if (!$ids) {
            Session::flash('warning', 'Aucun ticket sélectionné.');
            return $this->back($request, '/tickets');
        }
        if (in_array($action, ['print', 'pdf', 'csv'], true)) {
            $q = ['ids' => $ids, 'template_id' => $request->int('template_id')];
            return $this->redirect(($action === 'csv' ? '/tickets/export' : '/tickets/print') . '?' . http_build_query($q + ($action === 'pdf' ? ['output' => 'pdf'] : [])));
        }
        $this->authorize('tickets.manage');
        $ok = 0;
        $errors = [];
        foreach (Database::fetchAll('SELECT * FROM tickets WHERE id IN (' . implode(',', $ids) . ')') as $t) {
            try {
                TicketService::changeStatus($t, $action, Auth::id());
                $ok++;
            } catch (Throwable $e) {
                $errors[] = $t['username'] . ' : ' . $e->getMessage();
            }
        }
        Session::flash($errors ? 'warning' : 'success', "{$ok} ticket(s) mis à jour." . ($errors ? ' ' . implode(' ; ', array_slice($errors, 0, 5)) : ''));
        return $this->back($request, '/tickets');
    }

    public function templates(Request $request): Response
    {
        $this->authorize('tickets.print');
        $templates = Database::fetchAll('SELECT * FROM ticket_print_templates ORDER BY id');
        foreach ($templates as &$t) {
            $t['fields'] = json_decode((string) $t['fields'], true) ?: [];
            $t['options'] = json_decode((string) $t['options'], true) ?: [];
        }
        return $this->view('tickets/templates', compact('templates'));
    }

    public function saveTemplate(Request $request, string $id): Response
    {
        $this->authorize('settings.manage', 'tickets.manage');
        $tpl = Database::fetch('SELECT * FROM ticket_print_templates WHERE id = :id', ['id' => (int) $id]);
        $this->notFoundUnless($tpl);
        $fields = array_values(array_intersect((array) $request->input('fields', []), array_keys(TicketPrintService::FIELDS)));
        $accent = preg_match('/^#[0-9A-Fa-f]{6}$/', $request->str('accent')) ? $request->str('accent') : '#D7192A';
        $format = isset(TicketPrintService::FORMATS[$request->str('format')]) ? $request->str('format') : $tpl['format'];
        Database::transaction(static function () use ($request, $tpl, $fields, $accent, $format) {
            if ($request->input('is_default')) {
                Database::query('UPDATE ticket_print_templates SET is_default = 0');
            }
            Database::update('ticket_print_templates', [
                'name' => mb_substr($request->str('name') ?: $tpl['name'], 0, 100), 'format' => $format,
                'fields' => json_encode($fields), 'options' => json_encode(['accent' => $accent]),
                'is_default' => $request->input('is_default') ? 1 : (int) $tpl['is_default'],
            ], 'id = :id', ['id' => $tpl['id']]);
        });
        Session::flash('success', 'Modèle enregistré.');
        return $this->redirect('/tickets/templates');
    }
}
