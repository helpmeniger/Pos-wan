<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers\Terminals;

/** Terminal joignable sur le réseau local (Ethernet, Wi-Fi) en TCP/IP, messages JSON d'une ligne. */
final class TcpTerminalDriver extends LineTerminalDriver
{
    public static function info(): array
    {
        return ['code' => 'terminal_tcp', 'label' => 'TPE réseau (TCP/IP, JSON par ligne)', 'kind' => 'terminal', 'transports' => ['tcp', 'ethernet', 'wifi'], 'verified' => false,
            'notes' => 'Applicable seulement si le fabricant documente une interface JSON compatible ; sinon un adaptateur ou un pilote dédié est nécessaire.'];
    }

    protected function exchange(string $json): array
    {
        $host = (string) ($this->target['ip_address'] ?? '');
        $port = (int) ($this->target['port'] ?? 0);
        $sent = "tcp://$host:$port\n$json";
        if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[a-z0-9.-]{1,253}$/i', $host) || $port < 1 || $port > 65535) {
            return ['line' => null, 'error' => 'Adresse IP ou port du terminal invalide.', 'timeout' => false, 'sent' => $sent];
        }
        $s = @stream_socket_client("tcp://$host:$port", $errno, $err, min(10, $this->timeout()));
        if (!$s) {
            return ['line' => null, 'error' => "Terminal injoignable ($host:$port) : $err", 'timeout' => false, 'sent' => $sent];
        }
        stream_set_timeout($s, $this->timeout());
        fwrite($s, $json . "\n");
        $line = fgets($s, 65536);
        $meta = stream_get_meta_data($s);
        fclose($s);
        if ($line === false || $line === '') {
            return ['line' => null, 'error' => !empty($meta['timed_out']) ? 'Le terminal n\'a pas répondu à temps.' : 'Aucune réponse du terminal.', 'timeout' => true, 'sent' => $sent];
        }
        return ['line' => $line, 'error' => null, 'timeout' => false, 'sent' => $sent];
    }
}
