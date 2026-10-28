<?php
declare(strict_types=1);

namespace Addons\Pressing;

use App\Controllers\Controller;
use App\Core\DB;
use App\Core\Export;
use App\Core\HttpException;
use App\Core\Request;

final class PressingController extends Controller
{
    private function order(int $id): array
    {
        $o = DB::one('SELECT o.*, u.full_name AS user_name, d.full_name AS delivered_name FROM pressing_orders o JOIN users u ON u.id = o.user_id LEFT JOIN users d ON d.id = o.delivered_by WHERE o.id = ?', [$id]);
        if (!$o) {
            throw new HttpException(404);
        }
        return $o;
    }

    /** Tableau de bord + liste (filtres : statut, recherche n° / nom / téléphone / étiquette). */
    public function index(): void
    {
        $today = date('Y-m-d');
        $relance = max(1, (int) setting('pressing_relance_jours', '30'));
        $counts = array_column(DB::all('SELECT status, COUNT(*) AS n FROM pressing_orders GROUP BY status'), 'n', 'status');
        $stats = [
            'late' => (int) DB::value("SELECT COUNT(*) FROM pressing_orders WHERE status IN ('received','processing') AND due_date < ?", [$today]),
            'forgotten' => (int) DB::value("SELECT COUNT(*) FROM pressing_orders WHERE status = 'ready' AND ready_at < NOW() - INTERVAL ? DAY", [$relance]),
            'today' => (int) DB::value('SELECT COUNT(*) FROM pressing_orders WHERE DATE(created_at) = ?', [$today]),
            'cash_today' => (float) DB::value('SELECT COALESCE(SUM(amount),0) FROM pressing_payments WHERE DATE(created_at) = ?', [$today]),
            'due_ready' => (float) DB::value("SELECT COALESCE(SUM(total - paid),0) FROM pressing_orders WHERE status = 'ready'"),
        ];
        $where = ['1=1'];
        $params = [];
        $filter = (string) Request::query('f', '');
        if (isset(PressingService::STATUSES[$filter])) {
            $where[] = 'o.status = ?';
            $params[] = $filter;
        } elseif ($filter === 'late') {
            $where[] = "o.status IN ('received','processing') AND o.due_date < ?";
            $params[] = $today;
        } elseif ($filter === 'forgotten') {
            $where[] = "o.status = 'ready' AND o.ready_at < NOW() - INTERVAL ? DAY";
            $params[] = $relance;
        } elseif ($filter === '') {
            $where[] = "o.status IN ('received','processing','ready')";
        }
        if (($q = trim((string) Request::query('q', ''))) !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $where[] = '(o.number LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ? OR EXISTS (SELECT 1 FROM pressing_items i WHERE i.order_id = o.id AND i.tag = ?))';
            array_push($params, $like, $like, $like, $q);
        }
        $p = paginate('FROM pressing_orders o WHERE ' . implode(' AND ', $where), $params, 'o.*', "FIELD(o.status, 'ready', 'processing', 'received', 'delivered', 'cancelled'), o.due_date, o.id DESC");
        view('pressing::index', ['title' => 'Pressing', 'p' => $p, 'counts' => $counts, 'stats' => $stats, 'filter' => $filter, 'q' => $q ?? '', 'today' => $today]);
    }

    public function create(): void
    {
        $recent = DB::all('SELECT customer_name, phone FROM pressing_orders GROUP BY customer_name, phone ORDER BY MAX(id) DESC LIMIT 300');
        view('pressing::form', ['title' => 'Nouveau dépôt pressing', 'tarifs' => PressingService::tarifs(), 'recent' => $recent, 'scripts' => ['addons/pressing/pressing.js']]);
    }

    public function store(): void
    {
        $r = $this->attempt(fn() => PressingService::create([
            'customer_name' => Request::str('customer_name'), 'phone' => Request::str('phone'), 'express' => Request::input('express') === '1',
            'due_date' => Request::str('due_date'), 'discount' => Request::str('discount'), 'note' => Request::str('note'),
            'lines' => $this->rowsFromForm('lines'),
            'deposit' => ['method' => Request::str('pay_method'), 'amount' => Request::str('pay_amount'), 'reference' => Request::str('pay_reference')],
        ]), '/pressing/create');
        flash('success', sprintf(t('Dépôt %s enregistré.'), $r['number']) . ($r['change'] > 0 ? ' ' . t('Monnaie à rendre') . ' : ' . money($r['change']) . '.' : ''));
        redirect('/pressing/' . $r['id'], ['print' => 1]);
    }

    public function show(int $id): void
    {
        $o = $this->order($id);
        view('pressing::show', [
            'title' => 'Dépôt ' . $o['number'], 'o' => $o,
            'items' => DB::all('SELECT * FROM pressing_items WHERE order_id = ? ORDER BY line_no', [$id]),
            'payments' => DB::all('SELECT p.*, u.full_name FROM pressing_payments p JOIN users u ON u.id = p.user_id WHERE p.order_id = ? ORDER BY p.id', [$id]),
            'log' => DB::all('SELECT l.*, u.full_name FROM pressing_status_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.order_id = ? ORDER BY l.id', [$id]),
            'autoPrint' => Request::query('print') === '1', 'scripts' => ['addons/pressing/pressing.js'],
        ]);
    }

    public function status(int $id): void
    {
        $this->order($id);
        $this->attempt(fn() => PressingService::setStatus($id, Request::str('status')), '/pressing/' . $id);
        flash('success', t('Statut mis à jour.'));
        redirect('/pressing/' . $id);
    }

    private function payData(): array
    {
        return ['method' => Request::str('pay_method'), 'amount' => Request::str('pay_amount'), 'reference' => Request::str('pay_reference')];
    }

    public function pay(int $id): void
    {
        $this->order($id);
        $change = $this->attempt(fn() => PressingService::pay($id, $this->payData()), '/pressing/' . $id);
        flash('success', t('Paiement enregistré.') . ($change > 0 ? ' ' . t('Monnaie à rendre') . ' : ' . money($change) . '.' : ''));
        redirect('/pressing/' . $id);
    }

    public function deliver(int $id): void
    {
        $this->order($id);
        $change = $this->attempt(fn() => PressingService::deliver($id, $this->payData(), Request::str('unpaid_reason') ?: null), '/pressing/' . $id);
        flash('success', t('Linge remis au client.') . ($change > 0 ? ' ' . t('Monnaie à rendre') . ' : ' . money($change) . '.' : ''));
        redirect('/pressing/' . $id);
    }

    public function partial(int $id): void
    {
        $this->order($id);
        $change = $this->attempt(fn() => PressingService::deliverPartial($id, (array) Request::input('qty', []), $this->payData()), '/pressing/' . $id);
        flash('success', t('Pièces remises au client.') . ($change > 0 ? ' ' . t('Monnaie à rendre') . ' : ' . money($change) . '.' : ''));
        redirect('/pressing/' . $id);
    }

    /** Caisse pressing (caisse principale en mode Pressing) : dépôt direct + retraits. */
    public function caisse(): void
    {
        $recent = DB::all('SELECT customer_name, phone FROM pressing_orders GROUP BY customer_name, phone ORDER BY MAX(id) DESC LIMIT 300');
        view('pressing::form', ['title' => 'Caisse pressing', 'tarifs' => PressingService::tarifs(), 'recent' => $recent, 'scripts' => ['addons/pressing/pressing.js'], 'caisse' => true,
            'ready' => DB::all("SELECT id, number, customer_name, phone, total - paid AS due, due_date FROM pressing_orders WHERE status IN ('ready','partial') ORDER BY due_date LIMIT 12"),
            'readyCount' => (int) DB::value("SELECT COUNT(*) FROM pressing_orders WHERE status IN ('ready','partial')")]);
    }

    /** Recherche d'un dépôt pour le retrait (n°, téléphone, nom, étiquette ou QR du ticket). */
    public function find(): void
    {
        $q = trim((string) Request::query('q', ''));
        $exact = $q !== '' ? DB::value('SELECT id FROM pressing_orders WHERE number = ?', [$q]) : null;
        if ($exact) {
            redirect('/pressing/' . $exact);
        }
        redirect('/pressing', ['q' => $q, 'f' => 'all']);
    }

    /** Historique d'un client (par téléphone). */
    public function client(): void
    {
        $phone = trim((string) Request::query('phone', ''));
        $digits = preg_replace('/\D/', '', $phone);
        $orders = $digits !== '' ? DB::all("SELECT o.*, (SELECT " . PressingService::CLOTHES_SQL . " FROM pressing_items i WHERE i.order_id = o.id) AS clothes
                FROM pressing_orders o WHERE REPLACE(REPLACE(REPLACE(o.phone, ' ', ''), '+', ''), '-', '') LIKE ? ORDER BY o.id DESC LIMIT 200", ['%' . substr((string) $digits, -8)]) : [];
        view('pressing::client', ['title' => 'Historique client', 'phone' => $phone, 'orders' => $orders]);
    }

    /** Suivi des vêtements déposés : toutes les commandes, retards, soldes ; export CSV / PDF. */
    public function suivi(): void
    {
        $f = (string) Request::query('f', 'open');
        $q = trim((string) Request::query('q', ''));
        $alertDays = max(0, (int) setting('pressing_alerte_jours', '1'));
        $where = match ($f) {
            'late' => "o.status IN ('received','processing','ready','partial') AND o.due_date < CURDATE()",
            'soon' => "o.status IN ('received','processing') AND o.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL " . $alertDays . ' DAY',
            'ready' => "o.status IN ('ready','partial')",
            'delivered' => "o.status = 'delivered'",
            'unpaid' => "o.status <> 'cancelled' AND o.total - o.paid > 0.009",
            'all' => '1=1',
            default => "o.status IN ('received','processing','ready','partial')",
        };
        $params = [];
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $where .= ' AND (o.number LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $rows = DB::all("SELECT o.*, (SELECT " . PressingService::CLOTHES_SQL . " FROM pressing_items i WHERE i.order_id = o.id) AS clothes,
                (SELECT MAX(p.created_at) FROM pressing_payments p WHERE p.order_id = o.id) AS last_payment
                FROM pressing_orders o WHERE $where ORDER BY FIELD(o.status, 'ready', 'partial', 'processing', 'received', 'delivered', 'cancelled'), o.due_date, o.id DESC LIMIT 1000", $params);
        $counts = [
            'late' => (int) DB::value("SELECT COUNT(*) FROM pressing_orders WHERE status IN ('received','processing','ready','partial') AND due_date < CURDATE()"),
            'soon' => (int) DB::value("SELECT COUNT(*) FROM pressing_orders WHERE status IN ('received','processing') AND due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL " . $alertDays . ' DAY'),
            'ready' => (int) DB::value("SELECT COUNT(*) FROM pressing_orders WHERE status IN ('ready','partial')"),
            'unpaid' => (float) DB::value("SELECT COALESCE(SUM(total - paid),0) FROM pressing_orders WHERE status NOT IN ('cancelled')"),
        ];
        if (\App\Core\Export::requested() && can('reports.export')) {
            \App\Core\Export::download('pressing_suivi_' . date('Y-m-d') . '.csv', 'Pressing — suivi des vêtements déposés', ['N°', 'Client', 'Téléphone', 'Vêtements', 'Pièces', 'Dépôt', 'Livraison prévue', 'Total', 'Payé', 'Reste', 'Statut', 'Retard (j)'],
                array_map(fn($o) => [$o['number'], $o['customer_name'], $o['phone'], $o['clothes'], (int) $o['pieces'], substr($o['created_at'], 0, 16), $o['due_date'], $o['total'], $o['paid'], round((float) $o['total'] - (float) $o['paid'], 2), PressingService::SHORT[$o['status']], PressingService::lateDays($o)], $rows),
                ['landscape' => true, 'subtitle' => count($rows) . ' dépôt(s) · ' . date('d/m/Y H:i')]);
        }
        view('pressing::suivi', ['title' => 'Suivi des vêtements déposés', 'rows' => $rows, 'f' => $f, 'q' => $q, 'counts' => $counts, 'alertDays' => $alertDays]);
    }

    public function cancel(int $id): void
    {
        $this->order($id);
        $this->attempt(fn() => PressingService::cancel($id, Request::str('reason')), '/pressing/' . $id);
        flash('success', t('Dépôt annulé ; les sommes versées ont été remboursées en espèces.'));
        redirect('/pressing/' . $id);
    }

    /** Ticket client (80 / 58 mm) avec QR code. */
    public function ticket(int $id): void
    {
        $o = $this->order($id);
        view('pressing::ticket', ['title' => 'Ticket ' . $o['number'], 'o' => $o, 'items' => DB::all('SELECT * FROM pressing_items WHERE order_id = ? ORDER BY line_no', [$id]),
            'bodyClass' => setting('ticket_width', '80') === '58' ? 't58' : 't80', 'autoPrint' => Request::query('auto') === '1', 'backUrl' => url('/pressing/' . $id)], 'layouts/print');
    }

    /** Étiquettes à agrafer sur chaque pièce (une étiquette par pièce). */
    public function labels(int $id): void
    {
        $o = $this->order($id);
        view('pressing::labels', ['title' => 'Étiquettes ' . $o['number'], 'o' => $o, 'items' => DB::all('SELECT * FROM pressing_items WHERE order_id = ? ORDER BY line_no', [$id]),
            'bodyClass' => 't58', 'backUrl' => url('/pressing/' . $id)], 'layouts/print');
    }

    public function tarifs(): void
    {
        view('pressing::tarifs', ['title' => 'Tarifs pressing', 'tarifs' => PressingService::tarifs(false)]);
    }

    public function tarifsSave(): void
    {
        if (Request::str('new_name') !== '') {
            $this->attempt(fn() => PressingService::addArticle(Request::str('new_name'), Request::str('new_category'), Request::str('new_unit', 'piece')), '/pressing/tarifs');
            flash('success', t('Article ajouté : saisissez ses prix.'));
            redirect('/pressing/tarifs');
        }
        $this->attempt(fn() => PressingService::saveTarifs((array) Request::input('prices', []), (array) Request::input('names', []), (array) Request::input('active', []), (array) Request::input('units', [])), '/pressing/tarifs');
        flash('success', t('Tarifs enregistrés.'));
        redirect('/pressing/tarifs');
    }

    /** Rapport : dépôts, encaissements, services, articles ; export CSV / PDF. */
    public function report(): void
    {
        [$from, $to] = date_range('first day of this month');
        $range = [$from . ' 00:00:00', $to . ' 23:59:59'];
        $orders = DB::all("SELECT o.*, (SELECT COALESCE(SUM(amount),0) FROM pressing_payments p WHERE p.order_id = o.id) AS cash FROM pressing_orders o WHERE o.created_at BETWEEN ? AND ? ORDER BY o.id", $range);
        if (Export::requested() && can('reports.export')) {
            $valid = array_filter($orders, fn($o) => $o['status'] !== 'cancelled');
            Export::download("pressing_{$from}_{$to}.csv", 'Rapport pressing', ['N°', 'Date', 'Client', 'Téléphone', 'Pièces', 'Total', 'Payé', 'Reste', 'Statut'],
                array_map(fn($o) => [$o['number'], substr($o['created_at'], 0, 16), $o['customer_name'], $o['phone'], (int) $o['pieces'], $o['total'], $o['paid'], round((float) $o['total'] - (float) $o['paid'], 2), PressingService::STATUSES[$o['status']]], $orders),
                ['subtitle' => Export::period($from, $to), 'footer' => ['TOTAL (hors annulés)', '', '', '', array_sum(array_column($valid, 'pieces')), array_sum(array_column($valid, 'total')), array_sum(array_column($valid, 'paid')), round(array_sum(array_column($valid, 'total')) - array_sum(array_column($valid, 'paid')), 2), '']]);
        }
        view('pressing::report', [
            'title' => 'Rapport pressing', 'from' => $from, 'to' => $to, 'orders' => $orders,
            'payments' => DB::all('SELECT method, kind, SUM(amount) AS total, COUNT(*) AS n FROM pressing_payments WHERE created_at BETWEEN ? AND ? GROUP BY method, kind ORDER BY method', $range),
            'services' => DB::all("SELECT i.service, SUM(IF(i.unit = 'kg', 0, i.quantity)) AS qty, SUM(IF(i.unit = 'kg', i.quantity, 0)) AS kg, SUM(i.line_total) AS total FROM pressing_items i JOIN pressing_orders o ON o.id = i.order_id WHERE o.status <> 'cancelled' AND o.created_at BETWEEN ? AND ? GROUP BY i.service ORDER BY total DESC", $range),
            'articles' => DB::all("SELECT i.article_name, SUM(IF(i.unit = 'kg', 0, i.quantity)) AS qty, SUM(IF(i.unit = 'kg', i.quantity, 0)) AS kg, SUM(i.line_total) AS total FROM pressing_items i JOIN pressing_orders o ON o.id = i.order_id WHERE o.status <> 'cancelled' AND o.created_at BETWEEN ? AND ? GROUP BY i.article_name ORDER BY total DESC LIMIT 15", $range),
        ]);
    }

    /** Écran des dépôts en cours (plein écran, actualisé automatiquement), comme la cuisine ou la supervision clinique. */
    public function ecran(): void
    {
        view('pressing::ecran', ['u' => $this->user(), 'refresh' => 15, 'public' => (bool) Request::query('public'),
            'canProcess' => can('pressing.process')], null);
    }

    public function ecranFeed(): void
    {
        header('Cache-Control: no-store');
        json_response(['ok' => true] + PressingService::board((bool) Request::query('public')));
    }

    /** Avancement depuis l'écran (reçu → en traitement → prêt). */
    public function ecranStatus(int $id): void
    {
        $this->order($id);
        $this->jsonAttempt(function () use ($id) {
            PressingService::setStatus($id, (string) Request::input('status', ''));
            return [];
        });
    }
}
