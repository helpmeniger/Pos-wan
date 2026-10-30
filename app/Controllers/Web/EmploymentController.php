<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Services\Auth\AuthService;
use App\Services\Employment\EmploymentService;
use App\Services\Employment\PayrollService;
use App\Services\Employment\PayslipService;

/** Contrats (employeur et employé) : consultation, acceptation, fin, impression. */
final class EmploymentController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AuthService $auth,
        private readonly EmploymentService $employments,
        private readonly PayrollService $payroll,
        private readonly PayslipService $payslips,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $userId = (int) $this->auth->id();
        $this->payroll->generateDue(null, $userId);
        return $this->render('employment/index', [
            'title' => 'Mes emplois et contrats',
            'employments' => $this->employments->forUser($userId, ['EN_ATTENTE_ACCEPTATION', 'ACTIF']),
            'payslips' => array_filter($this->payslips->forUser($userId), fn ($p) => !in_array($p['status'], ['RECUE', 'ANNULEE'], true)),
            'userId' => $userId,
        ]);
    }

    public function show(Request $request): Response
    {
        $userId = (int) $this->auth->id();
        $this->payroll->generateDue(null, $userId);
        $e = $this->employments->findForParty((string) $request->param('uuid'), $userId);
        return $this->render('employment/show', [
            'title' => $e['position_title'],
            'e' => $e,
            'terms' => json_decode((string) $e['terms_snapshot'], true) ?: [],
            'isEmployer' => (int) $e['employer_user_id'] === $userId,
            'payslips' => $this->payslips->forUser($userId, (string) $e['uuid']),
        ]);
    }

    public function print(Request $request): Response
    {
        $e = $this->employments->findForParty((string) $request->param('uuid'), (int) $this->auth->id());
        return $this->render('employment/print', ['title' => 'Contrat ' . $e['reference'], 'e' => $e, 'terms' => json_decode((string) $e['terms_snapshot'], true) ?: []]);
    }

    public function accept(Request $request): Response
    {
        if ($request->input('confirm') !== '1') {
            return $this->back('/emplois/' . $request->param('uuid'), 'Cochez la case pour confirmer que vous avez lu et acceptez les clauses.');
        }
        $this->employments->accept((int) $this->auth->id(), (string) $request->param('uuid'), $request->ip());
        return $this->redirect('/emplois/' . $request->param('uuid'), 'Contrat accepté : il est maintenant actif. Vos fiches de paie seront générées automatiquement.');
    }

    public function decline(Request $request): Response
    {
        $this->employments->decline((int) $this->auth->id(), (string) $request->param('uuid'), mb_substr($request->str('reason'), 0, 500) ?: null);
        return $this->redirect('/emplois', 'Proposition refusée ; le recruteur est prévenu.');
    }

    public function end(Request $request): Response
    {
        $data = Validator::validate($request->all(), ['end_date' => 'required|date', 'reason' => 'required|string|min:5|max:500'], ['end_date' => 'date de fin', 'reason' => 'motif']);
        $this->employments->end((int) $this->auth->id(), (string) $request->param('uuid'), (string) $data['end_date'], (string) $data['reason']);
        $this->payroll->generateDue(null, (int) $this->auth->id());
        return $this->redirect('/emplois/' . $request->param('uuid'), 'Fin du contrat enregistrée. La dernière fiche de paie sera générée à la date de fin.');
    }
}
