<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Addons;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Request;
use App\Core\Session;

/**
 * Écrans d'affichage (écran client, cuisine, pressing, hôtel, clinique) accessibles par une
 * adresse directe : https://…/ecran/<écran>. Sur un téléviseur ou un écran connecté, on saisit
 * une seule fois l'identifiant et le mot de passe d'un utilisateur autorisé pour cet écran ;
 * l'appareil est ensuite mémorisé (jeton révocable).
 *
 * Une session d'écran est CANTONNÉE à son écran : elle n'ouvre aucune autre page du logiciel
 * (ni caisse, ni gestion), même si l'utilisateur a d'autres droits.
 */
final class Screens
{
    public const COOKIE = 'PMSCREEN';

    /** code => [libellé, adresse de l'écran, permission, préfixes d'adresses permis, disponible ?] */
    public static function catalog(): array
    {
        $addon = function (string $code): bool {
            try {
                return Addons::isActive($code);
            } catch (\Throwable) {
                return false;
            }
        };
        return [
            'client' => ['label' => 'Écran client : publicités, menus et panier', 'icon' => 'bi-display', 'path' => '/afficheur', 'perm' => 'pos.sell',
                'allow' => ['/afficheur'], 'available' => true],
            'cuisine' => ['label' => 'Écran cuisine / bar (commandes à préparer)', 'icon' => 'bi-fire', 'path' => '/kitchen', 'perm' => 'restaurant.kitchen',
                'allow' => ['/kitchen'], 'available' => feature('restaurant')],
            'pressing' => ['label' => 'Pressing : dépôts en cours', 'icon' => 'bi-basket2', 'path' => '/pressing/ecran', 'perm' => 'pressing.view',
                'allow' => ['/pressing/ecran'], 'available' => $addon('pressing')],
            'hotel' => ['label' => 'Hôtel : plan des chambres', 'icon' => 'bi-building', 'path' => '/hotel/plan', 'perm' => 'hotel.view',
                'allow' => ['/hotel/plan', '/hotel/chambres/'], 'available' => $addon('hotel')],
            'clinique' => ['label' => 'Clinique : supervision des malades hospitalisés', 'icon' => 'bi-heart-pulse', 'path' => '/clinique/supervision', 'perm' => 'clinique.nurse',
                'allow' => ['/clinique/supervision'], 'available' => $addon('clinique')],
        ];
    }

    public static function get(string $code): ?array
    {
        return self::catalog()[$code] ?? null;
    }

    /** Adresse complète à saisir sur l'écran (affichée dans les paramètres). */
    public static function url(string $code): string
    {
        $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return ($https ? 'https' : 'http') . '://' . $host . url('/ecran/' . $code);
    }

    // ------------------------------------------------------------------ connexion

    /** Connexion d'un écran : identifiant + mot de passe d'un utilisateur autorisé pour cet écran. */
    public static function login(string $code, string $username, string $password, bool $remember): array
    {
        $s = self::get($code) ?? throw new BusinessException('Écran inconnu.');
        if (!$s['available']) {
            throw new BusinessException('Cet écran n\'est pas activé sur ce logiciel.');
        }
        $r = Auth::attemptPassword($username, $password);
        if (!$r['ok']) {
            throw new BusinessException($r['error']);
        }
        $u = $r['user'];
        if (!isset(Auth::permissionsForRole((int) $u['role_id'])[$s['perm']])) {
            Auth::logout();
            Audit::log('screen.denied', 'user', (int) $u['id'], ['screen' => $code], (int) $u['id']);
            throw new BusinessException('Ce compte n\'a pas accès à cet écran. Utilisez le compte d\'une personne autorisée (droit « ' . $s['perm'] . ' »).');
        }
        self::scope($code);
        $device = null;
        if ($remember) {
            $device = self::remember($code, $u);
        }
        Audit::log('screen.login', 'user', (int) $u['id'], ['screen' => $code, 'device' => $device, 'ip' => Request::ip()], (int) $u['id']);
        return $s;
    }

    private static function scope(string $code): void
    {
        Session::set('screen_scope', $code);
        // Un écran reste allumé toute la journée : pas de déconnexion pour inactivité
        Session::set('screen_since', time());
    }

    /** Mémorise l'appareil : jeton aléatoire dans un cookie, seule son empreinte est en base. */
    private static function remember(string $code, array $u): int
    {
        $token = bin2hex(random_bytes(32));
        $id = DB::insert('screen_devices', ['screen' => $code, 'user_id' => (int) $u['id'], 'token_hash' => hash('sha256', $token), 'pwd_sig' => hash('sha256', Auth::pwdSig($u)),
            'label' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190) ?: null, 'ip' => Request::ip(), 'last_seen_at' => date('Y-m-d H:i:s')]);
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(self::COOKIE, $id . '.' . $token, ['expires' => time() + 400 * 86400, 'path' => (base_path_prefix() ?: '') . '/', 'httponly' => true, 'samesite' => 'Lax',
                'secure' => (($_SERVER['HTTPS'] ?? '') === 'on') || (bool) config('app.session.secure')]);
        }
        return $id;
    }

    /**
     * Reconnexion silencieuse d'un écran mémorisé (session expirée, écran redémarré).
     * Appelée par Auth::user() quand aucune session n'est ouverte. @return ?array utilisateur
     */
    public static function resume(): ?array
    {
        $c = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (!preg_match('/^(\d+)\.([a-f0-9]{64})$/', $c, $m)) {
            return null;
        }
        $d = DB::one('SELECT * FROM screen_devices WHERE id = ? AND revoked_at IS NULL', [(int) $m[1]]);
        if (!$d || !hash_equals((string) $d['token_hash'], hash('sha256', $m[2]))) {
            return null;
        }
        $s = self::get((string) $d['screen']);
        $u = DB::one('SELECT * FROM users WHERE id = ? AND active = 1', [(int) $d['user_id']]);
        // Mot de passe changé, compte désactivé ou droit retiré : l'écran doit être reconnecté
        if (!$s || !$s['available'] || !$u || !hash_equals((string) $d['pwd_sig'], hash('sha256', Auth::pwdSig($u)))
            || !isset(Auth::permissionsForRole((int) $u['role_id'])[$s['perm']])) {
            DB::update('screen_devices', ['revoked_at' => date('Y-m-d H:i:s')], 'id = ?', [$d['id']]);
            Audit::log('screen.token_invalidated', 'user', (int) $d['user_id'], ['device' => (int) $d['id']], (int) $d['user_id']);
            return null;
        }
        Auth::login($u);
        self::scope((string) $d['screen']);
        DB::update('screen_devices', ['last_seen_at' => date('Y-m-d H:i:s'), 'ip' => Request::ip()], 'id = ?', [$d['id']]);
        return $u;
    }

    /** Écran mémorisé par le cookie de ce navigateur (pour la page de connexion). */
    public static function cookieScreen(): ?string
    {
        if (!preg_match('/^(\d+)\./', (string) ($_COOKIE[self::COOKIE] ?? ''), $m)) {
            return null;
        }
        $code = DB::value('SELECT screen FROM screen_devices WHERE id = ? AND revoked_at IS NULL', [(int) $m[1]]);
        return $code ? (string) $code : null;
    }

    /**
     * Cantonnement : une session d'écran n'accède qu'aux adresses de son écran.
     * @return ?string adresse de l'écran si la page demandée doit être refusée, sinon null
     */
    public static function guard(string $path): ?string
    {
        $code = (string) Session::get('screen_scope', '');
        if ($code === '') {
            return null;
        }
        $s = self::get($code);
        if (!$s) {
            return '/login';
        }
        foreach (array_merge($s['allow'], ['/ecran/', '/media/', '/logout', '/session/ping', '/manifest.webmanifest']) as $p) {
            if ($path === rtrim($p, '/') || str_starts_with($path, rtrim($p, '/') . '/') || ($p === '/ecran/' && str_starts_with($path, '/ecran'))) {
                return null;
            }
        }
        return $s['path'];
    }

    public static function devices(): array
    {
        return DB::all('SELECT d.*, u.full_name, u.username FROM screen_devices d JOIN users u ON u.id = d.user_id ORDER BY d.revoked_at IS NULL DESC, d.last_seen_at DESC LIMIT 200');
    }

    public static function revoke(int $id): void
    {
        DB::update('screen_devices', ['revoked_at' => date('Y-m-d H:i:s')], 'id = ? AND revoked_at IS NULL', [$id]);
        Audit::log('screen.revoke', 'screen_device', $id);
    }
}
