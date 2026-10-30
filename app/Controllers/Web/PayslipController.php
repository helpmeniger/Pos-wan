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
use App\Services\Employment\PayslipService;

/** Fiche de paie : consultation, impression / PDF, demande de paiement, versement, confirmation. */
final class PayslipController extends Controller
{
    public function __construct(View $view, Session $session, private readonly AuthService $auth, private readonly PayslipService $payslips)
    {
        parent::__construct($view, $session);
    }

    public function show(Request $request): Response
    {
        $userId = (int) $this->auth->id();
        $p = $this->payslips->findForParty((string) $request->param('uuid'), $userId);
        return $this->render('payslip/show', [
            'title' => 'Fiche de paie ' . $p['number'],
            'p' => $p,
            'isEmployee' => (int) $p['employee_user_id'] === $userId,
            'isEmployer' => (int) $p['employer_user_id'] === $userId,
            'events' => $this->payslips->events((int) $p['id']),
            'user' => $this->auth->user(),
        ]);
    }

    public function print(Request $request): Response
    {
        $p = $this->payslips->findForParty((string) $request->param('uuid'), (int) $this->auth->id());
        return $this->render('payslip/print', ['title' => 'Fiche de paie ' . $p['number'], 'p' => $p]);
    }

    public function requestPayment(Request $request): Response
    {
        $data = Validator::validate($request->all(), ['method' => 'required|in:MOBILE_MONEY,ESPECES', 'payout_phone' => 'nullable|string|max:20'], ['method' => 'mode de paiement', 'payout_phone' => 'numéro Mobile Money']);
        $this->payslips->requestPayment((int) $this->auth->id(), (string) $request->param('uuid'), (string) $data['method'], $data['payout_phone'], $request->ip());
        return $this->redirect('/fiches-de-paie/' . $request->param('uuid'), 'Demande de paiement envoyée à votre employeur.');
    }

    public function declarePaid(Request $request): Response
    {
        $data = Validator::validate($request->all(), ['reference' => 'nullable|string|max:120']);
        $this->payslips->declarePaid((int) $this->auth->id(), (string) $request->param('uuid'), $data['reference'], $request->ip());
        return $this->redirect('/fiches-de-paie/' . $request->param('uuid'), 'Paiement déclaré : l\'employé doit maintenant confirmer la réception.');
    }

    public function confirm(Request $request): Response
    {
        $this->payslips->confirmReceived((int) $this->auth->id(), (string) $request->param('uuid'), $request->ip());
        return $this->redirect('/fiches-de-paie/' . $request->param('uuid'), 'Merci ! Réception du paiement confirmée.');
    }

    public function dispute(Request $request): Response
    {
        $data = Validator::validate($request->all(), ['note' => 'required|string|min:5|max:1000'], ['note' => 'explication']);
        $this->payslips->dispute((int) $this->auth->id(), (string) $request->param('uuid'), (string) $data['note'], $request->ip());
        return $this->redirect('/fiches-de-paie/' . $request->param('uuid'), 'Votre signalement est transmis à l\'employeur et à l\'équipe JOB NIGER.');
    }
}
