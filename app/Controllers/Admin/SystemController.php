<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\SettingRepository;
use App\Services\Admin\AdminService;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AuthService;
use App\Services\Auth\PermissionService;

/** Journal d'audit, sécurité, paramètres et support. */
final class SystemController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AdminService $admin,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly SettingRepository $settings,
        private readonly AuditLogger $audit,
        private readonly Database $db,
    ) {
        parent::__construct($view, $session);
    }

    public function audit(Request $request): Response
    {
        return $this->render('admin/system/audit', [
            'title' => 'Journaux',
            'logs' => $this->admin->auditLogs((int) $request->query('page', 1)),
            'security' => $this->admin->securityEvents((int) $request->query('spage', 1), $request->str('tout') !== '1'),
            'chain' => $this->audit->verifyChain(),
        ]);
    }

    public function settings(Request $request): Response
    {
        return $this->render('admin/system/settings', [
            'title' => 'Paramètres',
            'editable' => AdminService::EDITABLE_SETTINGS,
            'values' => array_map(fn ($k) => (bool) $this->settings->get($k, false), array_combine(array_keys(AdminService::EDITABLE_SETTINGS), array_keys(AdminService::EDITABLE_SETTINGS))),
            'rate' => (string) ($this->db->fetchValue("SELECT worker_rate FROM commission_rules WHERE scope = 'GLOBAL' AND is_active = 1 ORDER BY priority DESC, id DESC LIMIT 1") ?? '0.00'),
            'canRate' => $this->permissions->allows((int) $this->auth->id(), 'finance.commission_rules.manage'),
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        $values = [];
        foreach (AdminService::EDITABLE_SETTINGS as $key => $_) {
            $values[$key] = $request->input(str_replace('.', '__', $key)) === '1';
        }
        $this->admin->updateSettings((int) $this->auth->id(), $values);
        return $this->redirect('/admin/parametres', 'Paramètres enregistrés.');
    }

    public function saveCommission(Request $request): Response
    {
        if (!$this->permissions->allows((int) $this->auth->id(), 'finance.commission_rules.manage')) {
            throw new HttpException(403);
        }
        $data = Validator::validate($request->all(), ['commission_rate' => 'required|numeric|between:0,50', 'reason' => 'required|string|min:5|max:500'], ['commission_rate' => 'taux', 'reason' => 'motif']);
        $this->admin->updateCommissionRate((int) $this->auth->id(), number_format((float) $data['commission_rate'], 2, '.', ''), (string) $data['reason']);
        return $this->redirect('/admin/parametres', 'Nouveau taux enregistré : il s\'applique aux nouveaux contrats (les contrats signés gardent leur taux).');
    }

    public function tickets(Request $request): Response
    {
        $status = $request->str('vue') === 'tous' ? 'all' : 'open';
        return $this->render('admin/system/tickets', ['title' => 'Support', 'result' => $this->admin->tickets($status, (int) $request->query('page', 1)), 'status' => $status]);
    }

    public function ticketStatus(Request $request): Response
    {
        $this->admin->setTicketStatus((int) $this->auth->id(), (int) $request->param('id'), $request->str('status'));
        return $this->redirect('/admin/support', 'Ticket mis à jour.');
    }
}
