<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Request;
use App\Core\Session;

/**
 * Appareils connectés (ordinateurs, tablettes, smartphones du réseau local).
 * Chaque navigateur reçoit un identifiant aléatoire (cookie « pm_device ») ; à la connexion,
 * l'appareil est enregistré pour l'utilisateur. L'administrateur peut :
 *  - exiger l'approbation des nouveaux appareils du réseau (le poste serveur reste toujours autorisé) ;
 *  - révoquer un appareil : sa session est coupée immédiatement et il ne peut plus se connecter.
 * Le QR code d'accès ne donne aucun droit : l'identifiant et le mot de passe restent exigés.
 */
final class DeviceService
{
    private const COOKIE = 'pm_device';

    public static function isLocal(?string $ip = null): bool
    {
        $ip ??= Request::ip();
        return in_array($ip, ['127.0.0.1', '::1'], true) || ($ip === ($_SERVER['SERVER_ADDR'] ?? '') && $ip !== '');
    }

    /** Identifiant de l'appareil (cookie) ; créé au besoin. */
    public static function key(): string
    {
        $k = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (!preg_match('/^[a-f0-9]{40}$/', $k)) {
            $k = bin2hex(random_bytes(20));
            if (PHP_SAPI !== 'cli' && !headers_sent()) {
                setcookie(self::COOKIE, $k, ['expires' => time() + 2 * 365 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => (bool) config('app.session.secure')]);
            }
            $_COOKIE[self::COOKIE] = $k;
        }
        return $k;
    }

    /** Description courte du navigateur (affichée à l'administrateur). */
    public static function label(string $ua): string
    {
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android', str_contains($ua, 'iPhone') => 'iPhone', str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'Linux') => 'Linux', default => 'Appareil',
        };
        $br = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') => 'Firefox', str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Safari/') => 'Safari', default => 'navigateur',
        };
        $mobile = preg_match('/Mobile|Android|iPhone|iPad/', $ua) ? ' (mobile)' : '';
        return $os . ' · ' . $br . $mobile;
    }

    /**
     * À la connexion : enregistre l'appareil et vérifie qu'il est autorisé.
     * @throws BusinessException appareil révoqué ou en attente d'approbation
     */
    public static function onLogin(array $user): int
    {
        $hash = hash('sha256', self::key());
        $ip = Request::ip();
        $row = DB::one('SELECT * FROM user_devices WHERE device_hash = ? AND user_id = ?', [$hash, $user['id']]);
        $local = self::isLocal($ip);
        $needApproval = setting('device_approval', '0') === '1' && !$local && ($user['role_code'] ?? '') !== 'admin';
        if (!$row) {
            $status = $needApproval ? 'pending' : 'approved';
            $id = DB::insert('user_devices', ['device_hash' => $hash, 'user_id' => $user['id'], 'label' => mb_substr(self::label((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 150),
                'ip' => $ip, 'status' => $status, 'last_seen_at' => date('Y-m-d H:i:s')]);
            Audit::log('device.new', 'device', $id, ['status' => $status, 'ip' => $ip], (int) $user['id']);
            $row = ['id' => $id, 'status' => $status];
        }
        if ($row['status'] === 'revoked') {
            Audit::log('device.refused', 'device', (int) $row['id'], ['ip' => $ip], (int) $user['id']);
            throw new BusinessException('Cet appareil a été bloqué par l\'administrateur.');
        }
        if ($row['status'] === 'pending') {
            throw new BusinessException('Cet appareil doit d\'abord être autorisé par l\'administrateur (Configuration → Appareils connectés).');
        }
        DB::update('user_devices', ['ip' => $ip, 'last_seen_at' => date('Y-m-d H:i:s')], 'id = ?', [$row['id']]);
        return (int) $row['id'];
    }

    /** À chaque requête : l'appareil de la session est-il toujours autorisé ? */
    public static function sessionAllowed(): bool
    {
        $id = (int) Session::get('device_id', 0);
        if (!$id) {
            return true;
        }
        $st = DB::value('SELECT status FROM user_devices WHERE id = ?', [$id]);
        if ($st === 'approved') {
            if ((int) Session::get('device_seen', 0) < time() - 300) {
                DB::update('user_devices', ['last_seen_at' => date('Y-m-d H:i:s'), 'ip' => Request::ip()], 'id = ?', [$id]);
                Session::set('device_seen', time());
            }
            return true;
        }
        return false;
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['approved', 'revoked'], true)) {
            throw new BusinessException('Statut invalide.');
        }
        $d = DB::one('SELECT * FROM user_devices WHERE id = ?', [$id]) ?? throw new BusinessException('Appareil introuvable.');
        DB::update('user_devices', ['status' => $status, 'approved_by' => $status === 'approved' ? \App\Core\Auth::id() : $d['approved_by']], 'id = ?', [$id]);
        Audit::log($status === 'revoked' ? 'device.revoke' : 'device.approve', 'device', $id, ['user' => $d['user_id'], 'label' => $d['label']]);
    }
}
