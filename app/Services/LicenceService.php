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
 * Données : {"n": n° de licence, "c": client, "i": identifiant d'installation, "m": code du poste (ordinateur),
 *            "p": nombre d'ordinateurs autorisés (poste principal compris, 1 par défaut), "e": date de fin | null,
 *            "t": date d'émission}
 *
 * Liaison à l'ordinateur : une clé portant « m » n'est valable que sur l'ordinateur dont l'empreinte est « m »
 * (App\Services\Poste). Dossier et base copiés sur un autre ordinateur : clé refusée, lecture seule. Les clés
 * plus anciennes (sans « m ») sont liées au premier ordinateur qui les vérifie.
 * Téléphones et tablettes du réseau : libres. Autres ordinateurs du réseau : licence multiposte (« p »),
 * voir App\Services\LicencePostes.
 *
 * Limite honnête : le code PHP est lisible ; cette protection empêche la copie simple d'une
 * installation et l'utilisation sans clé, mais un informaticien qui modifie le code peut la
 * retirer. Le contrat de licence (clauses d'interdiction) reste la protection juridique.
 */
final class LicenceService
{
    private static ?array $status = null;
    /** Tests : forcer l'implémentation PHP pure (comme sur un serveur sans extension sodium). */
    public static bool $forceCompat = false;

    /**
     * Fonctions Ed25519 : extension PHP « sodium » si elle est active, sinon bibliothèque PHP
     * pure sodium_compat (app/Lib) — l'extension n'est pas activée par défaut sous WampServer.
     */
    public static function sodium(string $fn, mixed ...$args): mixed
    {
        if (!self::$forceCompat && function_exists('sodium_' . $fn)) {
            return ('sodium_' . $fn)(...$args);
        }
        if (!class_exists('ParagonIE_Sodium_Compat', false)) {
            $base = BASE_PATH . '/app/Lib/sodium_compat/src/';
            spl_autoload_register(static function (string $class) use ($base): void {
                if (str_starts_with($class, 'ParagonIE_Sodium_')) {
                    $file = $base . str_replace('_', '/', substr($class, 17)) . '.php';
                    if (is_file($file)) {
                        require_once $file;
                    }
                }
            });
            require_once $base . 'SodiumException.php';
            require_once $base . 'Compat.php';
        }
        \ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = self::$forceCompat;
        return \ParagonIE_Sodium_Compat::{$fn}(...$args);
    }

    /** Chemins accessibles même en lecture seule (activation, sauvegardes, déconnexion…). */
    public const ALWAYS_ALLOWED = ['/login', '/logout', '/licence', '/about', '/backups', '/session/ping', '/account/password', '/update', '/abonnement'];

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
        if ($sk === false || strlen($sk) !== 64) {
            throw new BusinessException('Clé privée invalide.');
        }
        $data = self::b64u((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return 'PWL1.' . $data . '.' . self::b64u(self::sodium('crypto_sign_detached', 'PWL1.' . $data, $sk));
    }

    /** Code à communiquer à l'auteur pour obtenir une clé : installation + poste (ordinateur). */
    public static function requestCode(): string
    {
        $poste = Poste::code();
        return self::installId() . ($poste ? ' / ' . $poste : '');
    }

    /** Vérifie une clé pour CETTE installation et CET ordinateur. @return array données de la licence */
    public static function verify(string $key, ?string $installId = null, ?string $poste = null): array
    {
        $key = preg_replace('/\s+/', '', $key);
        $parts = explode('.', (string) $key);
        if (count($parts) !== 3 || $parts[0] !== 'PWL1') {
            throw new BusinessException('Clé de licence mal formée (elle commence par « PWL1. »).');
        }
        $pk = base64_decode((string) config('licence.public_key'), true);
        $sig = self::unb64u($parts[2]);
        $json = self::unb64u($parts[1]);
        try {
            $valid = $pk !== false && strlen($pk) === 32 && $sig !== false && $json !== false && strlen($sig) === 64
                && self::sodium('crypto_sign_verify_detached', $sig, 'PWL1.' . $parts[1], $pk);
        } catch (\Throwable) {
            $valid = false;
        }
        if (!$valid) {
            throw new BusinessException('Clé de licence non authentique (signature invalide).');
        }
        $p = json_decode($json, true);
        if (!is_array($p) || empty($p['c']) || empty($p['i'])) {
            throw new BusinessException('Contenu de licence invalide.');
        }
        if ($p['i'] !== ($installId ?? self::installId())) {
            throw new BusinessException('Cette clé a été délivrée pour une autre installation (' . $p['i'] . ').');
        }
        if (!empty($p['m'])) {
            $here = $poste ?? Poste::code();
            if ($here === null || !hash_equals((string) $p['m'], $here)) {
                throw new BusinessException('Cette licence est liée à un autre ordinateur (' . $p['m'] . ') : ce dossier a été copié, ou l\'ordinateur a changé. '
                    . 'Pour changer d\'ordinateur, demandez le transfert de la licence à l\'auteur.');
            }
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
        self::bindLegacy(true);
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
        // Édition Bureau : date du premier démarrage sur CET ordinateur, gardée par POS Marwa.exe hors du dossier
        // du logiciel (registre de Windows et dossier système caché) : réinstaller ne relance pas l'essai.
        $pc = getenv('POS_ESSAI_DEBUT');
        $pc = is_string($pc) && ctype_digit($pc) && (int) $pc <= time() ? (int) $pc : null;
        $first = DB::value("SELECT MIN(created_at) FROM audit_logs WHERE action = 'licence.trial_start'");
        if ($first) {
            $t = (int) strtotime((string) $first);
            return $pc !== null ? min($t, $pc) : $t;
        }
        Audit::log('licence.trial_start', null, null, ['install_id' => self::installId()]);
        return $pc ?? time();
    }

    /**
     * Clé sans code de poste (délivrée avant la 3.14) : liée au premier ordinateur qui la vérifie (empreinte
     * signée avec APP_KEY). @return bool false si la base a été copiée sur un autre ordinateur
     */
    private static function bindLegacy(bool $rebind = false): bool
    {
        $code = Poste::code();
        if ($code === null) {
            return true; // empreinte illisible sur ce serveur : liaison à l'installation seulement
        }
        $sig = hash_hmac('sha256', 'poste|' . $code . '|' . self::installId(), (string) \App\Core\Env::get('APP_KEY', 'poswan'));
        $saved = (string) setting('licence_poste_lie', '');
        if ($saved === '' || $rebind) {
            setting_set('licence_poste_lie', $sig);
            return true;
        }
        return hash_equals($saved, $sig);
    }

    /** @return array{state:string, client:?string, expires:?string, days_left:?int, install_id:string, number:?string} */
    public static function status(): array
    {
        if (self::$status !== null) {
            return self::$status;
        }
        if (\App\Saas\Saas::isTenant()) {
            return self::$status = \App\Saas\Subscription::licenceStatus(); // abonnement en ligne
        }
        $base = ['install_id' => self::installId(), 'poste' => Poste::code(), 'request_code' => self::requestCode(), 'client' => null, 'expires' => null,
            'days_left' => null, 'number' => null, 'error' => null, 'postes' => 1, 'bound' => false];
        $key = (string) setting('licence_key', '');
        if ($key !== '') {
            try {
                $p = self::verify($key);
                if (empty($p['m']) && !self::bindLegacy()) {
                    throw new BusinessException('Cette installation a été copiée depuis un autre ordinateur : la licence n\'est valable que sur l\'ordinateur d\'origine. '
                        . 'Pour changer d\'ordinateur, demandez le transfert de la licence à l\'auteur.');
                }
                return self::$status = ['state' => 'licensed', 'client' => $p['c'], 'expires' => $p['e'] ?? null, 'number' => $p['n'] ?? null,
                    'postes' => max(1, (int) ($p['p'] ?? 1)), 'bound' => !empty($p['m']),
                    'days_left' => !empty($p['e']) ? (int) floor((strtotime($p['e'] . ' 23:59:59') - time()) / 86400) : null] + $base;
            } catch (BusinessException $e) {
                $base['error'] = $e->getMessage(); // clé copiée d'une autre installation, expirée ou falsifiée
            }
        }
        $left = (int) config('licence.trial_days', 15) - (int) floor((time() - self::trialStart()) / 86400);
        // Essai : poste principal + 1 ordinateur du réseau, pour essayer le multiposte
        return self::$status = ['state' => $left > 0 ? 'trial' : 'expired', 'days_left' => max(0, $left), 'postes' => $left > 0 ? 2 : 1] + $base;
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
