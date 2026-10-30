<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Services\Auth\AuthService;
use App\Services\Verification\VerificationService;

final class VerificationController extends Controller
{
    public function __construct(View $view, Session $session, private readonly VerificationService $verifications, private readonly AuthService $auth)
    {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $tab = $request->str('vue') === 'traitees' ? 'done' : 'pending';
        return $this->render('admin/verifications', [
            'title' => 'Vérification des documents', 'tab' => $tab,
            'rows' => $this->verifications->queue($tab), 'docTypes' => VerificationService::DOC_TYPES,
        ]);
    }

    public function decide(Request $request): Response
    {
        $data = Validator::validate($request->all(), ['decision' => 'required|in:approve,reject', 'note' => 'nullable|string|max:500'], ['note' => 'motif']);
        $this->verifications->decide((int) $this->auth->id(), (int) $request->param('id'), $data['decision'] === 'approve', $data['note']);
        return $this->redirect('/admin/verifications', $data['decision'] === 'approve' ? 'Document approuvé : l\'utilisateur est prévenu.' : 'Document refusé : l\'utilisateur est prévenu du motif.');
    }
}
