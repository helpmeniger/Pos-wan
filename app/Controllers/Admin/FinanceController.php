<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Services\Admin\AdminService;
use App\Services\Auth\AuthService;
use App\Services\Auth\PermissionService;
use App\Services\Employment\PayrollService;
use App\Services\Employment\PayslipService;

/** Surveillance financière : salaires, fiches de paie, contestations, commissions, grand livre, export CSV. */
final class FinanceController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AdminService $admin,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly PayslipService $payslips,
        private readonly PayrollService $payroll,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $this->payroll->generateDue();
        $f = ['status' => $request->str('status'), 'q' => mb_substr($request->str('q'), 0, 100), 'from' => $request->str('from'), 'to' => $request->str('to')];
        foreach (['from', 'to'] as $k) {
            if ($f[$k] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) {
                $f[$k] = '';
            }
        }
        $userId = (int) $this->auth->id();
        return $this->render('admin/finance', [
            'title' => 'Finances', 'summary' => $this->admin->financeSummary(),
            'result' => $this->admin->payslips($f, (int) $request->query('page', 1)), 'f' => $f,
            'canCancel' => $this->permissions->allows($userId, 'finance.adjustments.create'),
            'canCollect' => $this->permissions->allows($userId, 'finance.reconciliation'),
            'canExport' => $this->permissions->allows($userId, 'finance.export'),
        ]);
    }

    public function payslip(Request $request): Response
    {
        $p = $this->payslips->find((string) $request->param('uuid')) ?? throw new HttpException(404);
        return $this->render('payslip/show', [
            'title' => 'Fiche de paie ' . $p['number'], 'p' => $p, 'isEmployee' => false, 'isEmployer' => false,
            'events' => $this->payslips->events((int) $p['id']), 'adminView' => true,
            'canCancel' => $this->permissions->allows((int) $this->auth->id(), 'finance.adjustments.create'),
        ]);
    }

    public function cancelPayslip(Request $request): Response
    {
        if (!$this->permissions->allows((int) $this->auth->id(), 'finance.adjustments.create')) {
            throw new HttpException(403);
        }
        $data = Validator::validate($request->all(), ['reason' => 'required|string|min:5|max:500'], ['reason' => 'motif']);
        $this->payslips->cancelByAdmin((int) $this->auth->id(), (string) $request->param('uuid'), (string) $data['reason'], $request->ip());
        return $this->redirect('/admin/finances', 'Fiche annulée (action journalisée). Les deux parties sont prévenues.');
    }

    public function collectCommission(Request $request): Response
    {
        if (!$this->permissions->allows((int) $this->auth->id(), 'finance.reconciliation')) {
            throw new HttpException(403);
        }
        $data = Validator::validate($request->all(), ['amount' => 'required|integer|min:1', 'reference' => 'required|string|max:120'], ['amount' => 'montant', 'reference' => 'référence']);
        $this->admin->collectCommission((int) $this->auth->id(), (string) $request->param('uuid'), (int) $data['amount'], (string) $data['reference']);
        return $this->redirect('/admin/finances', 'Encaissement de commission enregistré dans le grand livre.');
    }

    /** Export CSV (séparateur « ; », UTF-8 avec BOM : s'ouvre directement dans Excel). */
    public function export(Request $request): Response
    {
        if (!$this->permissions->allows((int) $this->auth->id(), 'finance.export')) {
            throw new HttpException(403);
        }
        $rows = [];
        $page = 1;
        do {
            $r = $this->admin->payslips(['status' => $request->str('status')], $page);
            $rows = array_merge($rows, $r['rows']);
            $page++;
        } while ($page <= $r['pages'] && $page <= 400);
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Numéro', 'Période', 'Employé', 'Employeur', 'Brut (FCFA)', 'Commission (FCFA)', 'Net (FCFA)', 'Statut', 'Mode', 'Émise le', 'Confirmée le'], ';');
        foreach ($rows as $p) {
            fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, [
                $p['number'], $p['period_label'], $p['employee'], $p['employer'], (int) $p['gross_amount'], (int) $p['commission_amount'],
                (int) $p['net_amount'], PayslipService::LABELS[$p['status']] ?? $p['status'], $p['payment_method'],
                date_fr($p['created_at']), $p['employee_confirmed_at'] ? date_fr($p['employee_confirmed_at']) : '',
            ]), ';');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        return (new Response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8']))
            ->withHeader('Content-Disposition', 'attachment; filename="fiches-de-paie-' . date('Y-m-d') . '.csv"');
    }
}
