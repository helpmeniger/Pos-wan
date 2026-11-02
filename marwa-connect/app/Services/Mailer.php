<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Envoi d'e-mails par SMTP (STARTTLS / SSL / sans chiffrement, AUTH LOGIN) sans bibliothèque externe.
 */
final class Mailer
{
    /** @param array{host:string,port:int|string,encryption?:string,username?:string,password?:string,from:string,from_name?:string} $cfg */
    public static function send(array $cfg, string $to, string $subject, string $body): void
    {
        if (empty($cfg['host']) || empty($cfg['from'])) {
            throw new RuntimeException('SMTP non configuré.');
        }
        $enc = $cfg['encryption'] ?? 'tls';
        $port = (int) ($cfg['port'] ?? 587);
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $port;
        $s = @stream_socket_client($remote, $errno, $errstr, 15);
        if (!$s) {
            throw new RuntimeException("SMTP injoignable : {$errstr}");
        }
        stream_set_timeout($s, 15);
        $read = static function () use ($s): string {
            $data = '';
            while (($line = fgets($s, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = static function (string $c, array $ok) use ($s, $read): string {
            fwrite($s, $c . "\r\n");
            $r = $read();
            if (!in_array((int) substr($r, 0, 3), $ok, true)) {
                throw new RuntimeException('SMTP : ' . trim($r));
            }
            return $r;
        };
        $read();
        $cmd('EHLO marwa-connect', [250]);
        if ($enc === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Échec STARTTLS.');
            }
            $cmd('EHLO marwa-connect', [250]);
        }
        if (!empty($cfg['username'])) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode((string) $cfg['username']), [334]);
            $cmd(base64_encode((string) ($cfg['password'] ?? '')), [235]);
        }
        $cmd('MAIL FROM:<' . $cfg['from'] . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $fromName = '=?UTF-8?B?' . base64_encode((string) ($cfg['from_name'] ?? 'MARWA CONNECT')) . '?=';
        $headers = [
            'From: ' . $fromName . ' <' . $cfg['from'] . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $msg = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));
        $cmd($msg . "\r\n.", [250]);
        fwrite($s, "QUIT\r\n");
        fclose($s);
    }
}
