<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;

/** Validation des adresses de caméras IP (liste de réseaux autorisés, anti-SSRF). */
final class NetworkGuard
{
    public static function ipAllowed(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (preg_match('/^(127\.|0\.|169\.254\.|22[4-9]\.|23\d\.)/', $ip) || $ip === '::1') {
            return false;
        }
        $nets = Env::list('CAMERA_ALLOWED_NETWORKS') ?: ['192.168.0.0/16', '10.0.0.0/8', '172.16.0.0/12'];
        foreach ($nets as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton((string) $net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}
