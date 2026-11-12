<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers\Terminals;

/**
 * Terminal branché en USB, COM / RS232 ou Bluetooth (port série virtuel) sur le POSTE DE CAISSE.
 * PHP (serveur) ne peut pas ouvrir ce port : la demande passe par le pont local « Marwa Pay Bridge »
 * (addons/pay/bridge) installé sur ce poste, qui authentifie chaque requête (HMAC-SHA256 + horodatage).
 * Champs : bridge_url (ex. http://192.168.1.20:8765), device_path (COM3, /dev/ttyUSB0), config.serial{baud, data_bits, parity, stop_bits}.
 * Secret : bridge_secret (le même que dans la configuration du pont).
 */
final class UsbTerminalDriver extends LineTerminalDriver
{
    public static function info(): array
    {
        return ['code' => 'terminal_usb', 'label' => 'TPE USB / COM / Bluetooth (via le pont local)', 'kind' => 'terminal', 'transports' => ['usb', 'serial', 'bluetooth'], 'verified' => false,
            'notes' => 'Nécessite le pont local Marwa Pay Bridge sur le poste où le terminal est branché. Protocole JSON par ligne : à vérifier auprès du fabricant.'];
    }

    protected function exchange(string $json): array
    {
        $bridge = rtrim((string) ($this->target['bridge_url'] ?? ''), '/');
        $sent = "$bridge/v1/terminal\n$json";
        $secret = $this->secret('bridge_secret');
        if (!preg_match('#^https?://[^/\s]+$#i', $bridge) || !$secret) {
            return ['line' => null, 'error' => 'Pont local non configuré (adresse ou secret manquant).', 'timeout' => false, 'sent' => $sent];
        }
        $serial = (array) ($this->config['serial'] ?? []);
        $body = (string) json_encode(['device' => (string) ($this->target['device_path'] ?? ''), 'baud' => (int) ($serial['baud'] ?? 9600), 'data_bits' => (int) ($serial['data_bits'] ?? 8),
            'parity' => (string) ($serial['parity'] ?? 'none'), 'stop_bits' => (int) ($serial['stop_bits'] ?? 1), 'timeout' => $this->timeout(), 'payload' => $json], JSON_UNESCAPED_UNICODE);
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $sig = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $body, $secret);
        $ch = curl_init($bridge . '/v1/terminal');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $this->timeout() + 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-Bridge-Timestamp: $ts", "X-Bridge-Nonce: $nonce", "X-Bridge-Signature: $sig"]]);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($errno) {
            return ['line' => null, 'error' => 'Pont local injoignable : ' . $cerr, 'timeout' => $errno === CURLE_OPERATION_TIMEDOUT, 'sent' => $sent];
        }
        $j = json_decode((string) $resp, true);
        if ($code === 401) {
            return ['line' => null, 'error' => 'Pont local : authentification refusée (secret différent ou horloge décalée).', 'timeout' => false, 'sent' => $sent];
        }
        if (!is_array($j) || empty($j['ok'])) {
            return ['line' => null, 'error' => 'Pont local : ' . (is_array($j) ? (string) ($j['error'] ?? 'erreur') : 'réponse illisible'), 'timeout' => is_array($j) && !empty($j['timeout']), 'sent' => $sent];
        }
        return ['line' => (string) ($j['response'] ?? ''), 'error' => null, 'timeout' => false, 'sent' => $sent];
    }
}
