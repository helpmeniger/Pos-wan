<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\SettingRepository;
use App\Services\Auth\AuthService;
use App\Services\Auth\OtpService;

final class PhoneVerificationController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AuthService $auth,
        private readonly OtpService $otp,
        private readonly SettingRepository $settings,
    ) {
        parent::__construct($view, $session);
    }

    public function show(Request $request): Response
    {
        $user = $this->auth->user() ?? throw new HttpException(401);
        return $this->render('auth/verify-phone', [
            'title' => 'Vérifier mon téléphone',
            'user' => $user,
            'enabled' => (bool) $this->settings->get('auth.phone_otp_enabled', false),
        ]);
    }

    public function send(Request $request): Response
    {
        $user = $this->auth->user() ?? throw new HttpException(401);
        if (!$this->settings->get('auth.phone_otp_enabled', false)) {
            return $this->back('/verifier-telephone', 'La vérification par SMS n\'est pas encore activée sur la plateforme.');
        }
        if ($user['phone_verified_at'] !== null) {
            return $this->redirect('/tableau-de-bord', 'Votre numéro est déjà vérifié.');
        }
        $result = $this->otp->sendPhoneVerification((int) $user['id'], (string) $user['phone'], $request);
        if (!$result['ok']) {
            return $this->back('/verifier-telephone', (string) $result['error']);
        }
        $msg = 'Un code à 6 chiffres vient d\'être envoyé par SMS.';
        if (!empty($result['dev_delivery'])) {
            $msg .= ' (Mode développement : le SMS est écrit dans storage/logs/sms.log.)';
        }
        return $this->redirect('/verifier-telephone', $msg);
    }

    public function verify(Request $request): Response
    {
        $user = $this->auth->user() ?? throw new HttpException(401);
        $result = $this->otp->verifyPhone((int) $user['id'], preg_replace('/\D/', '', $request->str('code')) ?? '', $request);
        if (!$result['ok']) {
            return $this->back('/verifier-telephone', (string) $result['error']);
        }
        return $this->redirect('/tableau-de-bord', 'Votre numéro de téléphone est vérifié. Badge « Téléphone vérifié » obtenu !');
    }
}
