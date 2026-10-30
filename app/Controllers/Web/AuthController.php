<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\CityRepository;
use App\Repositories\SettingRepository;
use App\Services\Auth\AuthService;
use App\Services\Auth\RegistrationService;
use App\Services\Verification\VerificationService;

final class AuthController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AuthService $auth,
        private readonly RegistrationService $registration,
        private readonly CityRepository $cities,
        private readonly SettingRepository $settings,
        private readonly VerificationService $verification,
    ) {
        parent::__construct($view, $session);
    }

    public function showLogin(Request $request): Response
    {
        return $this->render('auth/login', ['title' => 'Connexion']);
    }

    public function login(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'login' => 'required|string|max:190',
            'password' => 'required|string|max:128',
        ], ['login' => 'téléphone ou e-mail', 'password' => 'mot de passe']);

        $result = $this->auth->attempt((string) $data['login'], (string) $request->input('password'), $request);
        if (!$result['ok']) {
            $this->session->flashInput(['login' => $data['login']]);
            return $this->back('/connexion', (string) $result['error']);
        }
        $user = $result['user'];
        $intended = $this->session->pull('_intended');
        $target = is_string($intended) && str_starts_with($intended, '/') ? $intended : $this->auth->homePath((int) $user['id']);
        return $this->redirect($target, 'Bonjour ' . $user['first_name'] . ', vous êtes connecté(e).');
    }

    public function showRegister(Request $request): Response
    {
        $type = (string) $request->query('type', '');
        return $this->render('auth/register', [
            'title' => 'Créer un compte',
            'cities' => $this->cities->active(),
            'accountTypes' => RegistrationService::ACCOUNT_TYPES,
            'businessKinds' => RegistrationService::BUSINESS_KINDS,
            'selectedType' => array_key_exists(strtoupper($type), RegistrationService::ACCOUNT_TYPES) ? strtoupper($type) : 'WORKER',
            'emailRequired' => (bool) $this->settings->get('auth.email_required', false),
            'docTypes' => VerificationService::DOC_TYPES,
        ]);
    }

    public function register(Request $request): Response
    {
        $type = strtoupper($request->str('account_type'));
        $rules = [
            'account_type' => 'required|in:' . implode(',', array_keys(RegistrationService::ACCOUNT_TYPES)),
            'first_name' => 'required|string|min:2|max:80',
            'last_name' => 'required|string|min:2|max:80',
            'phone' => 'required|phone',
            'email' => ($this->settings->get('auth.email_required', false) ? 'required' : 'nullable') . '|email',
            'city_id' => 'required|integer',
            'birth_date' => 'nullable|date',
            'password' => 'required|password',
            'accept_terms' => 'accepted',
            'doc_type' => 'nullable|in:' . implode(',', array_keys(VerificationService::DOC_TYPES)),
        ];
        if ($type === 'BUSINESS') {
            $rules['business_name'] = 'required|string|min:2|max:160';
            $rules['business_kind'] = 'required|in:' . implode(',', array_keys(RegistrationService::BUSINESS_KINDS));
        }
        $labels = [
            'account_type' => 'type de compte', 'first_name' => 'prénom', 'last_name' => 'nom', 'phone' => 'téléphone',
            'email' => 'e-mail', 'city_id' => 'ville', 'birth_date' => 'date de naissance', 'password' => 'mot de passe',
            'accept_terms' => 'les conditions d\'utilisation', 'business_name' => 'nom de l\'organisation',
            'business_kind' => 'type d\'organisation', 'doc_type' => 'type de document',
        ];
        $data = Validator::validate($request->all(), $rules, $labels);
        $data['account_type'] = $type;
        $data['password'] = (string) $request->input('password');

        $userId = $this->registration->register($data, $request);
        $this->auth->loginById($userId);

        // Document d'identité / professionnel facultatif dès l'inscription : vérifié ensuite par l'équipe
        $upload = $request->file('document');
        $message = 'Votre compte est créé. Bienvenue sur JOB NIGER !';
        if ($upload !== null && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $this->verification->submit($userId, (string) ($data['doc_type'] ?: ($type === 'BUSINESS' ? 'RCCM' : 'CNI')), $upload);
                $message .= ' Votre document a été envoyé : l\'équipe le vérifie rapidement.';
            } catch (\App\Core\ValidationException $e) {
                $this->session->flash('warning', 'Compte créé, mais le document n\'a pas été accepté : ' . (array_values($e->errors())[0][0] ?? 'fichier invalide') . ' Vous pouvez le renvoyer depuis « Vérification ».');
            }
        }
        $next = $this->settings->get('auth.phone_otp_enabled', false) ? '/verifier-telephone' : $this->auth->homePath($userId);
        return $this->redirect($next, $message);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($request);
        return $this->redirect('/', 'Vous êtes déconnecté(e). À bientôt !');
    }
}
