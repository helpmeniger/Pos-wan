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

final class AuthController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AuthService $auth,
        private readonly RegistrationService $registration,
        private readonly CityRepository $cities,
        private readonly SettingRepository $settings,
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
            // Prestataires : date de naissance obligatoire (âge minimum, métiers à restriction d'âge)
            'birth_date' => ($type === 'WORKER' ? 'required' : 'nullable') . '|date',
            'password' => 'required|password|confirmed',
            'accept_terms' => 'accepted',
            'accept_privacy' => 'accepted',
        ];
        if ($type === 'BUSINESS') {
            $rules['business_name'] = 'required|string|min:2|max:160';
            $rules['business_kind'] = 'required|in:' . implode(',', array_keys(RegistrationService::BUSINESS_KINDS));
        }
        $labels = [
            'account_type' => 'type de compte', 'first_name' => 'prénom', 'last_name' => 'nom', 'phone' => 'téléphone',
            'email' => 'e-mail', 'city_id' => 'ville', 'birth_date' => 'date de naissance', 'password' => 'mot de passe',
            'accept_terms' => 'les conditions d\'utilisation', 'accept_privacy' => 'la politique de confidentialité',
            'business_name' => 'nom de l\'organisation', 'business_kind' => 'type d\'organisation',
        ];
        $data = Validator::validate($request->all(), $rules, $labels);
        $data['account_type'] = $type;
        $data['password'] = (string) $request->input('password');

        $userId = $this->registration->register($data, $request);
        $this->auth->loginById($userId);

        $next = $this->settings->get('auth.phone_otp_enabled', false) ? '/verifier-telephone' : $this->auth->homePath($userId);
        return $this->redirect($next, 'Votre compte a été créé. Bienvenue sur JOB NIGER !');
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($request);
        return $this->redirect('/', 'Vous êtes déconnecté(e). À bientôt !');
    }
}
