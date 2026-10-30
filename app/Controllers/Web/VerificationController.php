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
use App\Services\Verification\VerificationService;

/** L'utilisateur envoie ses documents et suit leur vérification. */
final class VerificationController extends Controller
{
    public function __construct(View $view, Session $session, private readonly AuthService $auth, private readonly VerificationService $verifications)
    {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        return $this->render('verification/index', [
            'title' => 'Vérification de mon profil',
            'requests' => $this->verifications->forUser((int) $this->auth->id()),
            'docTypes' => VerificationService::DOC_TYPES,
            'user' => $this->auth->user(),
        ]);
    }

    public function submit(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'doc_type' => 'required|in:' . implode(',', array_keys(VerificationService::DOC_TYPES)),
            'number_last4' => 'nullable|string|max:40',
        ], ['doc_type' => 'type de document']);
        $this->verifications->submit((int) $this->auth->id(), (string) $data['doc_type'], $request->file('document'), $data['number_last4']);
        return $this->redirect('/verification', 'Document envoyé. L\'équipe le vérifie et vous prévient dès que c\'est fait.');
    }
}
