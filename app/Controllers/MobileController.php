<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Services\DeviceService;
use App\Services\NetworkService;

/** Accès depuis les smartphones (QR code), contrôle du serveur, appareils connectés. */
final class MobileController extends Controller
{
    /** Configuration → Accès mobile (QR code). */
    public function index(): void
    {
        $ips = NetworkService::localIps();
        $chosen = in_array(Request::query('ip'), $ips, true) ? (string) Request::query('ip') : ($ips[0] ?? null);
        $last = (string) setting('mobile_last_ip', '');
        if ($chosen && $chosen !== $last) {
            setting_set('mobile_last_ip', $chosen);
        }
        view('mobile/index', ['title' => 'Accès mobile (QR code)', 'ips' => $ips, 'ip' => $chosen, 'url' => $chosen ? NetworkService::urlFor($chosen) : null,
            'changed' => $last !== '' && $chosen && $last !== $chosen ? $last : null, 'port' => (int) ($_SERVER['SERVER_PORT'] ?? 80),
            'scripts' => ['vendor/qrcode/qrcode.js', 'js/qr.js', 'js/mobile.js']]);
    }

    /** Page d'arrivée après le scan du QR code (publique : aucune donnée, connexion exigée ensuite). */
    public function landing(): void
    {
        view('mobile/landing', ['title' => 'POS Marwa'], null);
    }

    /** État du serveur (utilisé par le lanceur Windows et la page mobile). Aucune donnée sensible. */
    public function health(): void
    {
        $db = false;
        try {
            $db = (int) DB::value('SELECT 1') === 1;
        } catch (\Throwable) {
            $db = false;
        }
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-store');
        json_response(['ok' => $db, 'app' => 'POS Marwa', 'version' => config('app.version'), 'database' => $db, 'time' => date('c')], $db ? 200 : 503);
    }

    public function devices(): void
    {
        view('mobile/devices', ['title' => 'Appareils connectés',
            'rows' => DB::all('SELECT d.*, u.full_name, u.username, a.full_name AS approver FROM user_devices d JOIN users u ON u.id = d.user_id LEFT JOIN users a ON a.id = d.approved_by ORDER BY d.status = \'pending\' DESC, d.last_seen_at DESC LIMIT 300'),
            'current' => (int) \App\Core\Session::get('device_id', 0)]);
    }

    public function deviceStatus(int $id): void
    {
        $this->attempt(fn() => DeviceService::setStatus($id, Request::str('status')), '/devices');
        flash('success', Request::str('status') === 'revoked' ? 'Appareil bloqué : sa session est coupée et il ne peut plus se connecter.' : 'Appareil autorisé.');
        redirect('/devices');
    }

    public function devicePolicy(): void
    {
        setting_set('device_approval', Request::input('device_approval') === '1' ? '1' : '0');
        \App\Core\Audit::log('device.policy', null, null, ['approval' => setting('device_approval')]);
        flash('success', 'Règle enregistrée.');
        redirect('/devices');
    }
}
