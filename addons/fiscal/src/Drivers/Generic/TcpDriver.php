<?php
declare(strict_types=1);

namespace Addons\Fiscal\Drivers\Generic;

use Addons\Fiscal\DriverResult;

/** Dispositif joignable en TCP/IP (Ethernet, Wi-Fi). config_json : host, port, tls (false). */
final class TcpDriver extends StreamDriver
{
    public static function info(): array
    {
        return ['code' => 'generic_tcp', 'label' => 'Dispositif TCP/IP générique (JSON par ligne)', 'kind' => 'mcf', 'transports' => ['tcp'], 'verified' => false,
            'notes' => 'Point de départ pour un MCF réseau. Les trames propriétaires exigent un pilote dédié écrit d\'après la spécification du constructeur.'];
    }

    protected function open(): mixed
    {
        $host = (string) ($this->config['host'] ?? '');
        $port = (int) ($this->config['port'] ?? 0);
        if (!preg_match('/^[A-Za-z0-9.\-:\[\]]{1,253}$/', $host) || $port < 1 || $port > 65535) {
            return DriverResult::fail(DriverResult::CONFIG_ERROR, 'Adresse ou port du dispositif invalide.');
        }
        $scheme = !empty($this->config['tls']) ? 'tls' : 'tcp';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $s = @stream_socket_client("$scheme://$host:$port", $errno, $err, min(10, $this->timeout()), STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            return DriverResult::fail($errno === 110 || stripos((string) $err, 'timed out') !== false ? DriverResult::TIMEOUT : DriverResult::UNREACHABLE, "Dispositif injoignable ($host:$port) : " . $err);
        }
        return $s;
    }
}
