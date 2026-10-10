<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\CashService;

final class CashController extends Controller
{
    public function index(): void
    {
        $u = $this->user();
        $register = $u['register_id'] ? DB::one('SELECT r.*, s.name AS store_name FROM cash_registers r JOIN stores s ON s.id = r.store_id WHERE r.id = ?', [$u['register_id']]) : null;
        $session = $register ? CashService::openSessionForRegister((int) $register['id']) : null;
        $data = ['title' => 'Ma caisse', 'register' => $register, 'session' => $session, 'summary' => null, 'movements' => [], 'opener' => null];
        if ($session) {
            $data['summary'] = CashService::summary((int) $session['id']);
            $data['movements'] = DB::all('SELECT m.*, u.full_name FROM cash_movements m LEFT JOIN users u ON u.id = m.user_id WHERE m.cash_session_id = ? ORDER BY m.id DESC LIMIT 100', [$session['id']]);
            $data['opener'] = DB::value('SELECT full_name FROM users WHERE id = ?', [$session['user_id']]);
        }
        $data['last'] = $register ? DB::all("SELECT * FROM cash_sessions WHERE register_id = ? AND status = 'closed' ORDER BY id DESC LIMIT 5", [$register['id']]) : [];
        view('cash/index', $data);
    }

    public function open(): void
    {
        $u = $this->user();
        if (!$u['register_id']) {
            $this->fail('Aucune caisse associée à votre compte.', '/cash');
        }
        $back = Request::str('return') === 'pos' ? '/pos' : '/cash';
        $this->attempt(fn() => CashService::open((int) $u['register_id'], Request::num('opening_amount')), $back);
        flash('success', 'Caisse ouverte.');
        redirect($back);
    }

    public function close(): void
    {
        $u = $this->user();
        $session = $u['register_id'] ? CashService::openSessionForRegister((int) $u['register_id']) : null;
        if (!$session) {
            $this->fail('Aucune session ouverte.', '/cash');
        }
        // Comptage par coupures (billets/pièces FCFA) ou montant global
        $counted = Request::num('counted_amount');
        $denoms = Request::input('denom', []);
        if (is_array($denoms) && array_filter($denoms)) {
            $counted = 0.0;
            foreach ($denoms as $value => $n) {
                $counted += (int) $value * max(0, (int) $n);
            }
        }
        $r = $this->attempt(fn() => CashService::close((int) $session['id'], $counted, Request::str('note') ?: null), '/cash');
        flash(abs($r['difference']) < 1 ? 'success' : 'warning', sprintf('Caisse clôturée. Théorique : %s · Compté : %s · Écart : %s', money($r['expected']), money($r['counted']), money($r['difference'])));
        redirect('/cash/sessions/' . $session['id']);
    }

    public function movement(): void
    {
        $u = $this->user();
        $session = $u['register_id'] ? CashService::openSessionForRegister((int) $u['register_id']) : null;
        if (!$session) {
            $this->fail('Aucune session ouverte.', '/cash');
        }
        $dir = Request::str('direction') === 'in' ? 'in' : 'out';
        $this->attempt(fn() => CashService::manual((int) $session['id'], $dir, Request::num('amount'), Request::str('reason')), '/cash');
        flash('success', 'Mouvement de caisse enregistré.');
        redirect('/cash');
    }

    public function sessions(): void
    {
        [$from, $to] = date_range('-30 days');
        $p = paginate('FROM cash_sessions cs JOIN cash_registers r ON r.id = cs.register_id JOIN stores s ON s.id = r.store_id JOIN users u ON u.id = cs.user_id WHERE cs.opened_at BETWEEN ? AND ?',
            [$from . ' 00:00:00', $to . ' 23:59:59'], 'cs.*, r.name AS register, s.name AS store, u.full_name', 'cs.id DESC');
        view('cash/sessions', ['title' => 'Sessions de caisse', 'p' => $p, 'from' => $from, 'to' => $to]);
    }

    public function show(int $id): void
    {
        $s = DB::one('SELECT cs.*, r.name AS register, st.name AS store, u.full_name AS opener, c.full_name AS closer FROM cash_sessions cs JOIN cash_registers r ON r.id = cs.register_id JOIN stores st ON st.id = r.store_id JOIN users u ON u.id = cs.user_id LEFT JOIN users c ON c.id = cs.closed_by WHERE cs.id = ?', [$id]);
        if (!$s) {
            throw new HttpException(404);
        }
        $u = $this->user();
        if (!can('cash.view_all') && (int) $s['register_id'] !== (int) $u['register_id']) {
            throw new HttpException(403);
        }
        view('cash/show', [
            'title' => 'État de caisse #' . $id, 's' => $s, 'summary' => CashService::summary($id), 'bodyClass' => 'a4', 'backUrl' => url('/cash'),
            'movements' => DB::all('SELECT m.*, u.full_name FROM cash_movements m LEFT JOIN users u ON u.id = m.user_id WHERE m.cash_session_id = ? AND m.type NOT IN (\'sale\') ORDER BY m.id', [$id]),
        ], 'layouts/print');
    }
}
