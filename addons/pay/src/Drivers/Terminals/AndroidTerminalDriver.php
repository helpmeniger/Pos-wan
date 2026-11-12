<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers\Terminals;

use Addons\Pay\Drivers\Generic\RestApiGateway;

/**
 * Terminal Android (SmartPOS) exposant une API HTTP locale (application du fabricant ou du prestataire).
 * Même configuration que le connecteur REST (endpoints, response, status_map). Le SDK Android lui-même
 * s'exécute sur le terminal : POS Marwa ne peut le piloter que si une API locale documentée existe.
 * En réseau local, HTTP n'est accepté que vers une adresse privée (192.168.x, 10.x, 172.16-31.x).
 */
final class AndroidTerminalDriver extends RestApiGateway
{
    public static function info(): array
    {
        return ['code' => 'terminal_android', 'label' => 'Terminal Android (API HTTP locale)', 'kind' => 'terminal', 'transports' => ['http_local', 'android_sdk', 'wifi', 'ethernet'], 'verified' => false,
            'notes' => 'Applicable si le terminal expose une API locale documentée par son fabricant ou son prestataire.'];
    }

    public static function capabilities(): array
    {
        return ['testConnection', 'initiate', 'getStatus', 'cancel', 'refund', 'getRefundStatus'];
    }

    protected function certificates(): array
    {
        return []; // pas de certificat client pour un terminal du réseau local
    }

    protected function url(string $path, array $vars): ?string
    {
        $base = rtrim((string) ($this->config['base_url'] ?? ''), '/');
        if ($base === '' && !empty($this->target['ip_address']) && !empty($this->target['port'])) {
            $base = 'http://' . $this->target['ip_address'] . ':' . (int) $this->target['port'];
        }
        $rel = ltrim(self::render($path, $vars, 'url'), '/');
        $url = $rel === '' ? $base : $base . '/' . $rel;
        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $private = filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($scheme === 'https' || ($scheme === 'http' && ($private || !$this->prod()))) {
            return $url;
        }
        return null;
    }
}
