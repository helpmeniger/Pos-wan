<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Licence d'utilisation : chaque installation possède un identifiant ; l'auteur délivre une
 * clé d'activation signée (Ed25519) pour cet identifiant. Sans clé valide : période d'essai,
 * puis mode lecture seule (consultation, sauvegardes et activation restent possibles ;
 * aucune donnée n'est bloquée ni effacée).
 *
 * Format de clé : PWL1.<données base64url>.<signature base64url>
 * Données : {"n": n° de licence, "c": client, "i": identifiant d'installation, "e": date de fin | null, "t": date d'émission}
 *
 * Limite honnête : le code PHP est lisible ; cette protection empêche la copie simple d'une
 * installation et l'utilisation sans clé, mais un informaticien qui modifie le code peut la
 * retirer. Le contrat de licence (clauses d'interdiction) reste la protection juridique.
 */
final class LicenceService
{
    private static ?array $status = null;

    /** Chemins accessibles même en lecture seule (activation, sauvegardes, déconnexion…). */
    public const ALWAYS_ALLOWED = ['/login', '/logout', '/licence', '/about', '/backups', '/session/ping', '/account/password', '/update'];

    public static function installId(): string
    {
        $id = (string) setting('install_id', '');
        if (!preg_match('/^PW(-[A-F0-9]{4}){4}$/', $id)) {
            $id = 'PW-' . implode('-', str_split(strtoupper(bin2hex(random_bytes(8))), 4));
            setting_set('install_id', $id);
        }
        return $id;
    }

    private static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64u(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /** Création d'une clé (outil de l'auteur : bin/licence.php, avec la clé privée). */
    public static function sign(array $payload, string $secretKeyB64): string
    {
        $sk = base64_decode(trim($secretKeyB64), true);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new BusinessException('Clé privée invalide.');
        }
        $data = self::b64u((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return 'PWL1.' . $data . '.' . self::b64u(sodium_crypto_sign_detached('PWL1.' . $data, $sk));
    }

    /** Vérifie une clé pour CETTE installation. @return array données de la licence */
    public static function verify(string $key, ?string $installId = null): array
    {
        $key = preg_replace('/\s+/', '', $key);
        $parts = explode('.', (string) $key);
        if (count($parts) !== 3 || $parts[0] !== 'PWL1') {
            throw new BusinessException('Clé de licence mal formée (elle commence par « PWL1. »).');
        }
        $pk = base64_decode((string) config('licence.public_key'), true);
        $sig = self::unb64u($parts[2]);
        $json = self::unb64u($parts[1]);
        if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $sig === false || $json === false
            || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($sig, 'PWL1.' . $parts[1], $pk)) {
            throw new BusinessException('Clé de licence non authentique (signature invalide).');
        }
        $p = json_decode($json, true);
        if (!is_array($p) || empty($p['c']) || empty($p['i'])) {
            throw new BusinessException('Contenu de licence invalide.');
        }
        if ($p['i'] !== ($installId ?? self::installId())) {
            throw new BusinessException('Cette clé a été délivrée pour une autre installation (' . $p['i'] . ').');
        }
        if (!empty($p['e']) && $p['e'] < date('Y-m-d')) {
            throw new BusinessException('Licence expirée le ' . $p['e'] . '.');
        }
        return $p;
    }

    public static function activate(string $key): array
    {
        $p = self::verify($key);
        setting_set('licence_key', preg_replace('/\s+/', '', $key));
        self::$status = null;
        Audit::log('licence.activate', null, null, ['licence' => $p['n'] ?? null, 'client' => $p['c'], 'expires' => $p['e'] ?? null]);
        return $p;
    }

    /**
     * Début de la période d'essai : première exécution de cette fonctionnalité sur
     * l'installation (une mise à jour d'une ancienne version n'est donc pas bloquée d'emblée).
     * La date est inscrite au journal d'audit (non modifiable depuis le logiciel) : effacer le
     * paramètre ne relance pas l'essai.
     */
    public static function trialStart(): int
    {
        $first = DB::value("SELECT MIN(created_at) FROM audit_logs WHERE action = 'licence.trial_start'");
        if ($first) {
            return (int) strtotime((string) $first);
        }
        Audit::log('licence.trial_start', null, null, ['install_id' => self::installId()]);
        return time();
    }

    /** @return array{state:string, client:?string, expires:?string, days_left:?int, install_id:string, number:?string} */
    public static function status(): array
    {
        if (self::$status !== null) {
            return self::$status;
        }
        $base = ['install_id' => self::installId(), 'client' => null, 'expires' => null, 'days_left' => null, 'number' => null, 'error' => null];
        $key = (string) setting('licence_key', '');
        if ($key !== '') {
            try {
                $p = self::verify($key);
                return self::$status = ['state' => 'licensed', 'client' => $p['c'], 'expires' => $p['e'] ?? null, 'number' => $p['n'] ?? null,
                    'days_left' => !empty($p['e']) ? (int) floor((strtotime($p['e'] . ' 23:59:59') - time()) / 86400) : null] + $base;
            } catch (BusinessException $e) {
                $base['error'] = $e->getMessage(); // clé copiée d'une autre installation, expirée ou falsifiée
            }
        }
        $left = (int) config('licence.trial_days', 30) - (int) floor((time() - self::trialStart()) / 86400);
        return self::$status = ['state' => $left > 0 ? 'trial' : 'expired', 'days_left' => max(0, $left)] + $base;
    }

    public static function reset(): void
    {
        self::$status = null;
    }

    /** Lecture seule : les modifications sont refusées tant qu'aucune licence valide n'est active. */
    public static function blocks(string $method, string $path): bool
    {
        if ($method === 'GET' || $method === 'HEAD' || !InstallService::isInstalled()) {
            return false;
        }
        foreach (self::ALWAYS_ALLOWED as $p) {
            if ($path === $p || str_starts_with($path, $p . '/')) {
                return false;
            }
        }
        return self::status()['state'] === 'expired';
    }
}
