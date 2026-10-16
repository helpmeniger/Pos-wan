<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cookie;
use App\Core\Database;
use App\Core\Request;
use App\Core\Settings;

/**
 * « Se souvenir de moi » : cookie longue durée « sélecteur:vérificateur ».
 * Seule l'empreinte SHA-256 du vérificateur est stockée ; le jeton change à chaque utilisation
 * (un vol détecté invalide tous les jetons du compte) et il est lié au navigateur.
 */
final class RememberService
{
    public const COOKIE = 'mw_remember';

    public static function issue(int $userId, Request $r): void
    {
        $days = max(1, min(90, Settings::int('auth.remember_days', 30)));
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + $days * 86400;
        Database::insert('remember_tokens', ['user_id' => $userId, 'selector' => $selector, 'validator_hash' => hash('sha256', $validator),
            'user_agent_hash' => hash('sha256', $r->userAgent()), 'expires_at' => date('Y-m-d H:i:s', $expires)]);
        Cookie::set(self::COOKIE, $selector . ':' . $validator, $expires);
    }

    /** Retourne l'identifiant de l'utilisateur si le cookie est valide (et le renouvelle), sinon null. */
    public static function consume(Request $r): ?int
    {
        $raw = (string) ($r->cookies[self::COOKIE] ?? '');
        if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) {
            return null;
        }
        $row = Database::first('SELECT * FROM remember_tokens WHERE selector = :s', ['s' => $m[1]]);
        if (!$row) {
            Cookie::forget(self::COOKIE);
            return null;
        }
        if (!hash_equals($row['validator_hash'], hash('sha256', $m[2]))) {
            // Sélecteur connu mais vérificateur faux : jeton probablement volé et déjà réutilisé
            Database::execute('DELETE FROM remember_tokens WHERE user_id = :u', ['u' => $row['user_id']]);
            \App\Core\Audit::log('auth.remember_theft_suspected', 'user', (int) $row['user_id'], null, ['ip' => $r->ip()], (int) $row['user_id']);
            Cookie::forget(self::COOKIE);
            return null;
        }
        if ($row['expires_at'] < date('Y-m-d H:i:s') || !hash_equals($row['user_agent_hash'], hash('sha256', $r->userAgent()))) {
            Database::execute('DELETE FROM remember_tokens WHERE id = :id', ['id' => $row['id']]);
            Cookie::forget(self::COOKIE);
            return null;
        }
        // Rotation : même sélecteur, nouveau vérificateur (l'ancien cookie devient un signal de vol)
        $validator = bin2hex(random_bytes(32));
        Database::update('remember_tokens', ['validator_hash' => hash('sha256', $validator), 'last_used_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $row['id']]);
        Cookie::set(self::COOKIE, $row['selector'] . ':' . $validator, strtotime((string) $row['expires_at']));
        return (int) $row['user_id'];
    }

    public static function forgetCurrent(Request $r): void
    {
        $raw = (string) ($r->cookies[self::COOKIE] ?? '');
        if (preg_match('/^([a-f0-9]{24}):/', $raw, $m)) {
            Database::execute('DELETE FROM remember_tokens WHERE selector = :s', ['s' => $m[1]]);
        }
        Cookie::forget(self::COOKIE);
    }

    public static function revokeAll(int $userId): void
    {
        Database::execute('DELETE FROM remember_tokens WHERE user_id = :u', ['u' => $userId]);
    }
}
