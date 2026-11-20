<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Session;

/**
 * Empreinte de l'ordinateur qui fait tourner POS Marwa (« code du poste », ex. PC-1A2B-3C4D-5E6F).
 *
 * Source : identifiant de la machine attribué par le système, jamais copié avec le dossier du logiciel :
 *  - Windows : MachineGuid (registre HKLM\SOFTWARE\Microsoft\Cryptography), transmis par POS Marwa.exe
 *    (édition Bureau) ou lu avec « reg query » (édition web sous WampServer) ;
 *  - Linux : /etc/machine-id.
 * Seule une empreinte (SHA-256 tronquée) est utilisée : l'identifiant lui-même n'est ni affiché ni envoyé.
 * Indisponible (hébergement mutualisé qui interdit ces lectures) : null, la licence reste liée à l'installation.
 *
 * Limite honnête : un informaticien qui connaît l'identifiant de la machine d'origine, ou qui modifie le code,
 * peut contourner ce contrôle. Il empêche la copie simple du dossier sur un autre ordinateur.
 */
final class Poste
{
    private static ?string $code = null;
    private static bool $done = false;

    /** Tests : empreinte imposée (null = calcul normal). */
    public static ?string $forceSource = null;

    public static function code(): ?string
    {
        if (self::$forceSource !== null) {
            return self::format(self::$forceSource);
        }
        if (self::$done) {
            return self::$code;
        }
        self::$done = true;
        $src = self::source();
        return self::$code = $src === null ? null : self::format($src);
    }

    public static function reset(): void
    {
        self::$done = false;
        self::$code = null;
    }

    private static function format(string $src): string
    {
        $h = strtoupper(substr(hash('sha256', 'POS Marwa|poste|' . strtolower(trim($src))), 0, 12));
        return 'PC-' . implode('-', str_split($h, 4));
    }

    private static function source(): ?string
    {
        $env = getenv('POS_MACHINE');
        if (is_string($env) && preg_match('/^[A-Za-z0-9-]{16,64}$/', $env)) {
            return $env;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            // « reg query » coûte quelques dizaines de ms : résultat gardé dans la session (propre à ce navigateur)
            if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
                $c = Session::get('_poste_src');
                if (is_array($c) && ($c['t'] ?? 0) > time() - 3600 && is_string($c['v'] ?? null)) {
                    return $c['v'];
                }
            }
            $v = null;
            if (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
                $out = (string) @shell_exec('reg query "HKLM\SOFTWARE\Microsoft\Cryptography" /v MachineGuid 2>NUL');
                if (preg_match('/MachineGuid\s+REG_SZ\s+([A-Fa-f0-9-]{36})/', $out, $m)) {
                    $v = $m[1];
                }
            }
            if ($v !== null && PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
                Session::set('_poste_src', ['t' => time(), 'v' => $v]);
            }
            return $v;
        }
        foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $f) {
            $v = @is_readable($f) ? trim((string) @file_get_contents($f)) : '';
            if (preg_match('/^[a-f0-9]{32}$/', $v)) {
                return $v;
            }
        }
        return null;
    }
}
