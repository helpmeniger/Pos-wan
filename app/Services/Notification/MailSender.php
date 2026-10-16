<?php
declare(strict_types=1);

namespace App\Services\Notification;

use App\Core\Config;
use RuntimeException;

/** Envoi d'e-mails : log (développement), mail() de PHP, ou SMTP authentifié (TLS/SSL). */
final class MailSender
{
    public static function send(string $to, string $subject, string $body): string
    {
        $cfg = Config::get('services.mail');
        $driver = (string) $cfg['driver'];
        if ($driver === 'log') {
            Outbox::write('EMAIL', $to, $subject . "\n" . $body);
            return 'log';
        }
        $headers = [
            'From' => sprintf('"%s" <%s>', $cfg['from_name'], $cfg['from']),
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
        ];
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        if ($driver === 'mail') {
            if (!mail($to, $encodedSubject, $body, $headers)) {
                throw new RuntimeException('mail() a échoué (vérifiez la configuration SMTP de php.ini).');
            }
            return 'mail';
        }
        if ($driver === 'smtp') {
            self::smtp($cfg, $to, $encodedSubject, $body, $headers);
            return 'smtp';
        }
        throw new RuntimeException('Pilote e-mail inconnu : ' . $driver);
    }

    private static function smtp(array $cfg, string $to, string $subject, string $body, array $headers): void
    {
        $host = (string) $cfg['smtp_host'];
        if ($host === '') {
            throw new RuntimeException('SMTP_HOST non configuré.');
        }
        $enc = (string) $cfg['smtp_encryption'];
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . (int) $cfg['smtp_port'];
        $fp = @stream_socket_client($remote, $errno, $errstr, 8);
        if (!$fp) {
            throw new RuntimeException("Connexion SMTP impossible : $errstr");
        }
        stream_set_timeout($fp, 8);
        $read = static function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = static function (string $c, array $ok) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            $r = $read();
            if (!in_array((int) substr($r, 0, 3), $ok, true)) {
                throw new RuntimeException('SMTP : réponse inattendue ' . trim($r));
            }
            return $r;
        };
        $read();
        $cmd('EHLO marwa', [250]);
        if ($enc === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                throw new RuntimeException('Échec de la négociation TLS.');
            }
            $cmd('EHLO marwa', [250]);
        }
        if ($cfg['smtp_user'] !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode((string) $cfg['smtp_user']), [334]);
            $cmd(base64_encode((string) $cfg['smtp_password']), [235]);
        }
        $cmd('MAIL FROM:<' . $cfg['from'] . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $h = '';
        foreach ($headers + ['To' => $to, 'Subject' => $subject, 'Date' => date('r')] as $k => $v) {
            $h .= "$k: $v\r\n";
        }
        $safeBody = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], "\r\n", $body));
        $cmd($h . "\r\n" . $safeBody . "\r\n.", [250]);
        $cmd('QUIT', [221]);
        fclose($fp);
    }
}
