<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Adresses du serveur sur le réseau local (Wi-Fi / câble de la boutique), pour l'accès depuis
 * les smartphones et tablettes. L'adresse peut changer (box Internet, DHCP) : elle est
 * détectée à chaque affichage, jamais supposée fixe.
 */
final class NetworkService
{
    public static function isPrivate(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE)
            && !str_starts_with($ip, '127.') && !str_starts_with($ip, '169.254.');
    }

    /** Adresses IPv4 privées de cet ordinateur (la plus probable en premier). */
    public static function localIps(): array
    {
        $ips = [];
        // Interface utilisée pour sortir du réseau (aucun paquet n'est envoyé en UDP « connecté »)
        $s = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
        if ($s) {
            $name = (string) stream_socket_get_name($s, false);
            fclose($s);
            $ips[] = substr($name, 0, (int) strrpos($name, ':'));
        }
        $host = gethostname();
        foreach ($host ? (gethostbynamel($host) ?: []) : [] as $ip) {
            $ips[] = $ip;
        }
        $ips[] = (string) ($_SERVER['SERVER_ADDR'] ?? '');
        $host = (string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        if ($host !== '') {
            $ips[] = $host;
        }
        return array_values(array_unique(array_filter($ips, [self::class, 'isPrivate'])));
    }

    /** Adresse complète d'accès depuis le réseau pour une IP (port et sous-dossier inclus). */
    public static function urlFor(string $ip, string $path = '/m'): string
    {
        $port = (int) ($_SERVER['SERVER_PORT'] ?? 80);
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $scheme = $https ? 'https' : 'http';
        $portPart = ($https && $port === 443) || (!$https && $port === 80) ? '' : ':' . $port;
        return $scheme . '://' . $ip . $portPart . '/' . ltrim(url($path), '/');
    }
}
