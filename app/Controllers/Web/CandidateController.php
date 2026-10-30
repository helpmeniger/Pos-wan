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
use App\Services\Jobs\ApplicationService;
use App\Services\Jobs\InterviewService;

/** Espace candidat : suivi des candidatures et réponses aux entretiens. */
final class CandidateController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AuthService $auth,
        private readonly ApplicationService $applications,
        private readonly InterviewService $interviews,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        return $this->render('candidate/applications', [
            'title' => 'Mes candidatures',
            'applications' => $this->applications->forWorker((int) $this->auth->id()),
        ]);
    }

    public function withdraw(Request $request): Response
    {
        $this->applications->withdraw((int) $this->auth->id(), (string) $request->param('uuid'));
        return $this->redirect('/candidatures', 'Candidature retirée.');
    }

    public function respondInterview(Request $request): Response
    {
        $data = Validator::validate($request->all(), ['action' => 'required|in:confirm,reschedule', 'note' => 'nullable|string|max:500']);
        if ($data['action'] === 'reschedule' && empty($data['note'])) {
            throw new \App\Core\ValidationException(['note' => ['Proposez un autre moment (ex : « mardi après 15 h »).']]);
        }
        $this->interviews->respond((int) $this->auth->id(), (string) $request->param('uuid'), (string) $data['action'], $data['note']);
        return $this->redirect('/candidatures', $data['action'] === 'confirm' ? 'Présence confirmée. Bonne chance pour l\'entretien !' : 'Votre demande de report a été transmise au recruteur.');
    }
}
