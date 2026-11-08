<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Services\Screens;

/** Accès direct aux écrans d'affichage : /ecran/<écran> (téléviseur, écran connecté). */
final class ScreenController extends Controller
{
    private function code(): string
    {
        $code = substr(Request::path(), strlen('/ecran/'));
        if (!Screens::get($code)) {
            throw new HttpException(404);
        }
        return $code;
    }

    public function show(): void
    {
        $code = $this->code();
        $s = Screens::get($code);
        $u = Auth::user();
        if ($u && Auth::can($s['perm']) && $s['available']) {
            redirect($s['path']);
        }
        view('screens/login', ['s' => $s, 'code' => $code, 'flash' => Session::pullFlash(), 'company' => (string) setting('company_name', 'POS Marwa'),
            'logo' => upload_url(setting('company_logo')), 'loggedOther' => $u !== null], null);
    }

    public function login(): void
    {
        $code = $this->code();
        try {
            $s = Screens::login($code, Request::str('username'), (string) Request::input('password', ''), Request::input('remember') === '1');
        } catch (BusinessException $e) {
            Session::start();
            flash('danger', $e->getMessage());
            redirect('/ecran/' . $code);
        }
        redirect($s['path']);
    }

    /** Configuration → Écrans : adresses directes, appareils mémorisés, révocation. */
    public function index(): void
    {
        $local = Screens::isLoopbackHost();
        $ips = $local ? \App\Services\NetworkService::localIps() : [];
        $ip = in_array((string) Request::query('ip'), $ips, true) ? (string) Request::query('ip') : ($ips[0] ?? null);
        $list = [];
        foreach (Screens::catalog() as $code => $s) {
            $list[$code] = $s + ['url' => Screens::url($code, $ip)];
        }
        view('screens/index', ['title' => 'Écrans : accès direct', 'screens' => $list, 'devices' => Screens::devices(), 'local' => $local, 'ips' => $ips, 'ip' => $ip,
            'scripts' => ['vendor/qrcode/qrcode.js', 'js/qr.js']]);
    }

    public function revoke(int $id): void
    {
        Screens::revoke($id);
        flash('success', 'Écran déconnecté : il demandera de nouveau un identifiant et un mot de passe.');
        redirect('/screens');
    }
}
