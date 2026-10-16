<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\ValidationException;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Services\Audit\SecurityLogger;
use App\Services\Auth\AuthService;
use App\Services\Auth\PasswordHasher;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\RateLimiter;

final class PasswordController extends Controller
{
    private const GENERIC = 'Si un compte correspond à ces informations, vous allez recevoir un lien de réinitialisation (par SMS ou e-mail).';

    public function __construct(
        View $view,
        Session $session,
        private readonly PasswordResetService $resets,
        private readonly RateLimiter $limiter,
        private readonly AuthService $auth,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly SecurityLogger $security,
    ) {
        parent::__construct($view, $session);
    }

    public function showForgot(Request $request): Response
    {
        return $this->render('auth/forgot', ['title' => 'Mot de passe oublié']);
    }

    public function sendForgot(Request $request): Response
    {
        $data = Validator::validate($request->all(), ['login' => 'required|string|max:190'], ['login' => 'téléphone ou e-mail']);
        $login = mb_strtolower((string) $data['login']);
        if ($this->limiter->attempt('password_reset:login:' . hash('sha256', $login), 'password_reset')) {
            $this->resets->request((string) $data['login'], $request);
        }
        // Réponse identique dans tous les cas (pas d'énumération des comptes)
        return $this->redirect('/mot-de-passe/oublie', self::GENERIC);
    }

    public function showReset(Request $request): Response
    {
        $token = (string) $request->param('token');
        if ($this->resets->findValid($token) === null) {
            return $this->back('/mot-de-passe/oublie', 'Ce lien est invalide ou a expiré. Faites une nouvelle demande.');
        }
        return $this->render('auth/reset', ['title' => 'Nouveau mot de passe', 'token' => $token]);
    }

    public function reset(Request $request): Response
    {
        $token = (string) $request->param('token');
        $data = Validator::validate($request->all(), ['password' => 'required|password|confirmed'], ['password' => 'mot de passe']);
        if (!$this->resets->reset($token, (string) $data['password'], $request)) {
            return $this->back('/mot-de-passe/oublie', 'Ce lien est invalide ou a expiré. Faites une nouvelle demande.');
        }
        return $this->redirect('/connexion', 'Votre mot de passe a été modifié. Vous pouvez vous connecter.');
    }

    public function showChange(Request $request): Response
    {
        $user = $this->auth->user() ?? throw new HttpException(401);
        return $this->render('auth/change-password', ['title' => 'Changer mon mot de passe', 'forced' => (int) $user['must_change_password'] === 1]);
    }

    public function change(Request $request): Response
    {
        $user = $this->auth->user() ?? throw new HttpException(401);
        $data = Validator::validate($request->all(), [
            'current_password' => 'required|string|max:128',
            'password' => 'required|password|confirmed',
        ], ['current_password' => 'mot de passe actuel', 'password' => 'nouveau mot de passe']);

        $row = $this->users->findWithHash((int) $user['id']);
        if ($row === null || !$this->hasher->verify((string) $data['current_password'], (string) $row['password_hash'])) {
            $this->security->log('password_change.bad_current', (int) $user['id'], $request, [], 'AVERTISSEMENT');
            throw new ValidationException(['current_password' => ['Le mot de passe actuel est incorrect.']]);
        }
        if (hash_equals((string) $data['current_password'], (string) $data['password'])) {
            throw new ValidationException(['password' => ['Le nouveau mot de passe doit être différent de l\'actuel.']]);
        }
        $this->users->updatePassword((int) $user['id'], $this->hasher->hash((string) $data['password']));
        $this->auth->refreshPasswordStamp();
        $this->security->log('password_change.completed', (int) $user['id'], $request);
        return $this->redirect($this->auth->homePath((int) $user['id']), 'Votre mot de passe a été modifié.');
    }
}
