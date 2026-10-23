<?php
declare(strict_types=1);

namespace Addons\Banques;

use App\Controllers\Controller;
use App\Core\BusinessException;
use App\Core\Export;
use App\Core\Request;

final class BankController extends Controller
{
    public function index(): void
    {
        $accounts = BankService::accounts();
        $pending = can('banques.operate') ? BankService::sessions(true, 300) : [];
        view('banques::index', ['title' => 'Banques et coffres', 'accounts' => $accounts,
            'total' => array_sum(array_map(fn($a) => (int) $a['active'] ? (float) $a['balance'] : 0, $accounts)),
            'pending' => $pending, 'pendingTotal' => array_sum(array_column($pending, 'remaining')),
            'recent' => BankService::history(['from' => date('Y-m-d', strtotime('-90 days')), 'to' => date('Y-m-d')], 15),
            'edit' => Request::query('edit') ? (int) Request::query('edit') : 0, 'tab' => (string) Request::query('op', 'depot'),
            'checks' => BankService::check()]);
    }

    public function sessions(): void
    {
        $all = Request::query('tout') === '1';
        view('banques::versements', ['title' => 'Clôtures de caisse à verser', 'sessions' => BankService::sessions(!$all, $all ? 300 : 500),
            'accounts' => BankService::accounts(true), 'all' => $all]);
    }

    public function depositSession(int $id): void
    {
        $tid = $this->attempt(fn() => BankService::depositSession($id, $_POST), '/banques/versements');
        flash('success', t('Versement enregistré.'));
        redirect('/banques/versements', ['recu' => $tid]);
    }

    public function operation(): void
    {
        $type = Request::str('type');
        $tid = $this->attempt(fn() => BankService::operation($type, $_POST), '/banques?op=' . rawurlencode($type));
        flash('success', t('Opération enregistrée.'));
        redirect('/banques', ['op' => $type, 'recu' => $tid]);
    }

    public function transfer(): void
    {
        $tid = $this->attempt(fn() => BankService::transfer($_POST), '/banques?op=virement');
        flash('success', t('Virement enregistré.'));
        redirect('/banques', ['op' => 'virement', 'recu' => $tid]);
    }

    public function adjust(): void
    {
        $this->attempt(fn() => BankService::adjust($_POST), '/banques?op=ajustement');
        flash('success', t('Solde ajusté.'));
        redirect('/banques/comptes/' . Request::int('account_id'));
    }

    public function accountStore(): void
    {
        $id = $this->attempt(fn() => BankService::saveAccount($_POST), '/banques?op=compte');
        flash('success', t('Compte créé.'));
        redirect('/banques/comptes/' . $id);
    }

    public function accountUpdate(int $id): void
    {
        $this->attempt(fn() => BankService::saveAccount($_POST, $id), '/banques?edit=' . $id);
        flash('success', t('Compte mis à jour.'));
        redirect('/banques/comptes/' . $id);
    }

    public function statement(int $id): void
    {
        [$from, $to] = date_range('first day of this month');
        $type = (string) Request::query('type', '');
        try {
            $st = BankService::statement($id, $from, $to, $type);
        } catch (BusinessException $e) {
            $this->fail($e->getMessage(), '/banques');
        }
        $a = $st['account'];
        if (Export::requested() && can('reports.export')) {
            Export::download('releve_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $a['name']) . '_' . $from . '_' . $to . '.csv', 'Relevé — ' . $a['name'],
                ['Date', 'N°', 'Opération', 'Mode', 'Référence', 'Personne', 'Libellé', 'Entrée', 'Sortie', 'Solde', 'État'],
                array_map(fn($r) => [fdate($r['op_date'], false), $r['number'], t(BankService::TYPES[$r['type']]), t(BankService::METHODS[$r['method']] ?? $r['method']), (string) $r['reference'], (string) $r['person'],
                    (string) $r['description'] . ($r['counter_name'] ? ' (' . $r['counter_name'] . ')' : ''),
                    (int) $r['direction'] > 0 ? money($r['amount'], false) : '', (int) $r['direction'] < 0 ? money($r['amount'], false) : '',
                    $r['cancelled_at'] ? '' : money($r['balance_after'], false), $r['cancelled_at'] ? t('Annulée') . ' : ' . $r['cancel_reason'] : ''], $st['rows']),
                ['subtitle' => Export::period($from, $to) . ' — ' . t('Solde au début') . ' : ' . money($st['opening']) . ' — ' . t('Solde à la fin') . ' : ' . money($st['closing']), 'landscape' => true,
                    'footer' => ['', '', t('Totaux'), '', '', '', '', money($st['in'], false), money($st['out'], false), money($st['closing'], false), '', '_bold' => 1]]);
        }
        view('banques::compte', ['title' => $a['name'], 'st' => $st, 'from' => $from, 'to' => $to, 'type' => $type, 'accounts' => BankService::accounts(true)]);
    }

    public function history(): void
    {
        [$from, $to] = date_range('first day of this month');
        $f = ['from' => $from, 'to' => $to, 'account_id' => Request::int('account_id'), 'type' => (string) Request::query('type', ''), 'q' => trim((string) Request::query('q', ''))];
        $rows = BankService::history($f, 3000);
        if (Export::requested() && can('reports.export')) {
            Export::download('operations_banques_' . $from . '_' . $to . '.csv', 'Banques et coffres — opérations',
                ['Date', 'N°', 'Compte', 'Opération', 'Mode', 'Référence', 'Personne', 'Libellé', 'Entrée', 'Sortie', 'Saisi par', 'État'],
                array_map(fn($r) => [fdate($r['op_date'], false), $r['number'], $r['account_name'], t(BankService::TYPES[$r['type']]), t(BankService::METHODS[$r['method']] ?? $r['method']),
                    (string) $r['reference'], (string) $r['person'], (string) $r['description'],
                    (int) $r['direction'] > 0 ? money($r['amount'], false) : '', (int) $r['direction'] < 0 ? money($r['amount'], false) : '', $r['user_name'],
                    $r['cancelled_at'] ? t('Annulée') . ' : ' . $r['cancel_reason'] : ''], $rows),
                ['subtitle' => Export::period($from, $to), 'landscape' => true]);
        }
        view('banques::historique', ['title' => 'Historique des opérations', 'rows' => $rows, 'f' => $f, 'accounts' => BankService::accounts()]);
    }

    public function receipt(int $id): void
    {
        try {
            $t = BankService::transaction($id);
        } catch (BusinessException $e) {
            $this->fail($e->getMessage(), '/banques');
        }
        $session = $t['cash_session_id'] ? \App\Core\DB::one('SELECT s.*, r.name AS register_name, u.full_name AS cashier FROM cash_sessions s JOIN cash_registers r ON r.id = s.register_id JOIN users u ON u.id = s.user_id WHERE s.id = ?', [$t['cash_session_id']]) : null;
        view('banques::recu', ['title' => 'Opération ' . $t['number'], 't' => $t, 'session' => $session, 'backUrl' => url('/banques/comptes/' . $t['account_id']),
            'bodyClass' => 'a5', 'autoPrint' => Request::query('auto') === '1'], 'layouts/print');
    }

    public function cancel(int $id): void
    {
        $t = $this->attempt(fn() => BankService::transaction($id), '/banques');
        $this->attempt(fn() => BankService::cancel($id, Request::str('reason')), '/banques/comptes/' . $t['account_id']);
        flash('success', t('Opération annulée : le solde a été rétabli.'));
        redirect('/banques/comptes/' . $t['account_id']);
    }
}
