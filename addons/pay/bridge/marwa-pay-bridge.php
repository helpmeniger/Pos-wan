<?php
/**
 * MARWA PAY BRIDGE — pont local entre POS Marwa et un terminal de paiement branché sur CE poste
 * (USB, COM / RS232, Bluetooth via port série virtuel).
 *
 * Lancement (Windows) : demarrer-pont-paiement.bat   |   Linux : php marwa-pay-bridge.php
 * Configuration : bridge.json (copie de bridge.example.json) dans le même dossier.
 *
 * Sécurité :
 *  - chaque requête est signée : X-Bridge-Signature = HMAC-SHA256(horodatage + "\n" + nonce + "\n" + corps, secret) ;
 *  - horodatage à ± 60 s et nonce à usage unique (rejeu refusé) ;
 *  - seuls les ports déclarés dans bridge.json (allowed_devices) peuvent être ouverts ;
 *  - adresse d'écoute 127.0.0.1 par défaut ; n'ouvrez l'accès réseau (0.0.0.0) qu'avec un pare-feu limité au serveur POS Marwa ;
 *  - aucune donnée de carte n'est lue, écrite ni journalisée par le pont (il transmet des messages du terminal sans les conserver).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$dir = __DIR__;
$cfgFile = $argv[1] ?? $dir . '/bridge.json';
$cfg = is_file($cfgFile) ? json_decode((string) file_get_contents($cfgFile), true) : null;
if (!is_array($cfg) || strlen((string) ($cfg['secret'] ?? '')) < 32 || empty($cfg['allowed_devices'])) {
    fwrite(STDERR, "Configuration absente ou incomplète : copiez bridge.example.json en bridge.json, renseignez « secret » (32 caractères au moins, le même que dans POS Marwa) et « allowed_devices ».\n");
    exit(1);
}
$listen = (string) ($cfg['listen'] ?? '127.0.0.1:8765');
$secret = (string) $cfg['secret'];
$allowed = array_map('strval', (array) $cfg['allowed_devices']);
$server = @stream_socket_server('tcp://' . $listen, $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "Écoute impossible sur $listen : $errstr\n");
    exit(1);
}
$seen = []; // nonces récents
$log = function (string $m) use ($cfg): void {
    $line = date('c') . ' ' . $m . "\n";
    echo $line;
    if (!empty($cfg['log_file'])) {
        @file_put_contents((string) $cfg['log_file'], $line, FILE_APPEND);
    }
};
$log("Marwa Pay Bridge à l'écoute sur $listen (ports autorisés : " . implode(', ', $allowed) . ')');

/** Réponse HTTP minimale. */
$reply = function ($c, int $code, array $data): void {
    $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE);
    $txt = [200 => 'OK', 400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 413 => 'Payload Too Large'][$code] ?? 'Error';
    @fwrite($c, "HTTP/1.1 $code $txt\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
};

/** Ouvre le port, règle la vitesse, envoie une ligne et lit la réponse. */
$serialExchange = function (array $r) use ($allowed): array {
    $dev = (string) ($r['device'] ?? '');
    if (!in_array($dev, $allowed, true)) {
        return ['ok' => false, 'error' => 'Port non autorisé dans bridge.json : ' . $dev];
    }
    $win = PHP_OS_FAMILY === 'Windows';
    $baud = (int) ($r['baud'] ?? 9600);
    $bits = (int) ($r['data_bits'] ?? 8);
    $parity = (string) ($r['parity'] ?? 'none');
    $stop = (int) ($r['stop_bits'] ?? 1);
    if (!in_array($baud, [1200, 2400, 4800, 9600, 19200, 38400, 57600, 115200], true) || !in_array($bits, [7, 8], true) || !in_array($parity, ['none', 'even', 'odd'], true) || !in_array($stop, [1, 2], true)) {
        return ['ok' => false, 'error' => 'Paramètres série invalides.'];
    }
    if (preg_match('#^(COM[1-9][0-9]?|/dev/tty(USB|ACM|S|rfcomm)[0-9]{1,2})$#', $dev)) {
        $cmd = $win ? sprintf('mode %s BAUD=%d PARITY=%s DATA=%d STOP=%d', $dev, $baud, $parity[0], $bits, $stop)
            : sprintf('stty -F %s %d cs%d %s %s raw -echo 2>&1', escapeshellarg($dev), $baud, $bits, $parity === 'none' ? '-parenb' : ($parity === 'odd' ? 'parenb parodd' : 'parenb -parodd'), $stop === 2 ? 'cstopb' : '-cstopb');
        exec($cmd, $out, $rc);
        if ($rc !== 0) {
            return ['ok' => false, 'error' => 'Réglage du port impossible : ' . mb_substr(implode(' ', $out), 0, 150)];
        }
    }
    $h = @fopen($win && str_starts_with($dev, 'COM') ? '\\\\.\\' . $dev : $dev, 'r+b');
    if (!$h) {
        return ['ok' => false, 'error' => "Ouverture de $dev impossible (câble, droits, port occupé)."];
    }
    $payload = str_replace(["\r", "\n"], ' ', (string) ($r['payload'] ?? ''));
    $timeout = max(1, min(300, (int) ($r['timeout'] ?? 60)));
    stream_set_timeout($h, $timeout);
    fwrite($h, $payload . "\n");
    fflush($h);
    $line = fgets($h, 65536);
    $meta = stream_get_meta_data($h);
    fclose($h);
    if ($line === false || $line === '') {
        return ['ok' => false, 'timeout' => !empty($meta['timed_out']), 'error' => !empty($meta['timed_out']) ? 'Le terminal n\'a pas répondu à temps.' : 'Aucune réponse du terminal.'];
    }
    return ['ok' => true, 'response' => trim($line)];
};

while (true) {
    $c = @stream_socket_accept($server, -1, $peer);
    if (!$c) {
        continue;
    }
    stream_set_timeout($c, 10);
    $head = '';
    while (!str_contains($head, "\r\n\r\n") && strlen($head) < 16384 && ($chunk = fgets($c, 4096)) !== false) {
        $head .= $chunk;
    }
    [$requestLine] = explode("\r\n", $head, 2) + [''];
    $parts = explode(' ', $requestLine);
    $method = $parts[0] ?? '';
    $path = $parts[1] ?? '';
    $headers = [];
    foreach (array_slice(explode("\r\n", $head), 1) as $hl) {
        if (str_contains($hl, ':')) {
            [$k, $v] = explode(':', $hl, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    $len = (int) ($headers['content-length'] ?? 0);
    if ($len > 200000) {
        $reply($c, 413, ['ok' => false, 'error' => 'Requête trop volumineuse.']);
        fclose($c);
        continue;
    }
    $body = $len > 0 ? (string) stream_get_contents($c, $len) : '';
    // Authentification de chaque requête
    $ts = (string) ($headers['x-bridge-timestamp'] ?? '');
    $nonce = (string) ($headers['x-bridge-nonce'] ?? '');
    $sig = (string) ($headers['x-bridge-signature'] ?? '');
    $now = time();
    foreach ($seen as $n => $t) {
        if ($t < $now - 120) {
            unset($seen[$n]);
        }
    }
    $valid = ctype_digit($ts) && abs($now - (int) $ts) <= 60 && preg_match('/^[a-f0-9]{16,64}$/', $nonce) && !isset($seen[$nonce])
        && hash_equals(hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $body, $secret), $sig);
    if (!$valid) {
        $log("Requête refusée de $peer (signature, horodatage ou rejeu)");
        $reply($c, 401, ['ok' => false, 'error' => 'Authentification refusée.']);
        fclose($c);
        continue;
    }
    $seen[$nonce] = $now;
    if ($path === '/v1/ping' && $method === 'POST') {
        $reply($c, 200, ['ok' => true, 'bridge' => 'Marwa Pay Bridge 1.0', 'devices' => $allowed]);
    } elseif ($path === '/v1/terminal' && $method === 'POST') {
        $r = json_decode($body, true);
        $res = is_array($r) ? $serialExchange($r) : ['ok' => false, 'error' => 'Requête illisible.'];
        $log('Échange avec ' . (is_array($r) ? (string) ($r['device'] ?? '?') : '?') . ' : ' . ($res['ok'] ? 'réponse reçue' : $res['error']));
        $reply($c, 200, $res);
    } else {
        $reply($c, 404, ['ok' => false, 'error' => 'Route inconnue.']);
    }
    fclose($c);
}
