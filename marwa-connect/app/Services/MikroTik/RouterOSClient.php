<?php
declare(strict_types=1);

namespace App\Services\MikroTik;

/**
 * Client natif de l'API RouterOS (protocole binaire, ports 8728 / 8729 TLS).
 *
 * Protocole : une « phrase » est une suite de « mots » préfixés par leur longueur
 * (encodage variable 1-5 octets) et terminée par un mot vide. Réponses : !re, !done,
 * !trap (erreur), !fatal (connexion fermée). Compatible RouterOS v6 (≥ et < 6.43) et v7.
 */
final class RouterOSClient
{
    /** @var resource|null */
    private $socket = null;
    private int $tagSeq = 0;

    public function __construct(
        private string $host,
        private int $port,
        private bool $ssl = false,
        private int $connectTimeout = 5,
        private int $readTimeout = 10
    ) {
    }

    public function connect(string $user, string $password): void
    {
        $scheme = $this->ssl ? 'tls' : 'tcp';
        $host = filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $this->host . ']' : $this->host;
        $context = stream_context_create(['ssl' => [
            'verify_peer' => (bool) config('mikrotik.ssl_verify_peer', false),
            'verify_peer_name' => (bool) config('mikrotik.ssl_verify_peer', false),
            'allow_self_signed' => true,
            // Sans certificat, RouterOS n'accepte que les suites ADH (anonymes).
            'ciphers' => 'DEFAULT:ADH:@SECLEVEL=0',
        ]]);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("{$scheme}://{$host}:{$this->port}", $errno, $errstr, $this->connectTimeout, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            throw new RouterOSException("Connexion impossible à {$this->host}:{$this->port} — " . ($errstr ?: 'délai dépassé') . ($errno ? " ({$errno})" : ''));
        }
        stream_set_timeout($socket, $this->readTimeout);
        $this->socket = $socket;

        // RouterOS ≥ 6.43 : identifiants directement. Sinon, défi MD5.
        $reply = $this->talk('/login', ['name' => $user, 'password' => $password]);
        $done = end($reply) ?: [];
        if (isset($done['ret'])) {
            $challenge = pack('H*', $done['ret']);
            $response = '00' . md5(chr(0) . $password . $challenge);
            $this->talk('/login', ['name' => $user, 'response' => $response]);
        }
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Exécute une commande et renvoie les enregistrements (!re).
     * @param array $attrs  attributs (=nom=valeur) ; clés commençant par « . » acceptées (.proplist, .id)
     * @param array $queries requêtes (?nom=valeur) pour les commandes print
     */
    public function query(string $command, array $attrs = [], array $queries = []): array
    {
        $rows = $this->talk($command, $attrs, $queries);
        // Le dernier élément représente !done ; on ne garde que les !re.
        $out = [];
        foreach ($rows as $r) {
            if (($r['!type'] ?? '') === '!re') {
                unset($r['!type'], $r['.tag']);
                $out[] = $r;
            }
        }
        return $out;
    }

    /** Exécute une commande d'écriture ; renvoie la valeur =ret= (ex. .id créé) si présente. */
    public function exec(string $command, array $attrs = []): ?string
    {
        $rows = $this->talk($command, $attrs);
        foreach ($rows as $r) {
            if (($r['!type'] ?? '') === '!done' && isset($r['ret'])) {
                return (string) $r['ret'];
            }
        }
        return null;
    }

    /**
     * Envoi en rafale de commandes étiquetées (.tag) : idéal pour créer des centaines
     * de tickets sans attendre chaque réponse.
     * @param array<int, array{0:string,1:array}> $commands
     * @return array<int, array{ok:bool, ret:?string, error:?string}>
     */
    public function batch(array $commands, int $chunk = 50): array
    {
        $results = [];
        foreach (array_chunk($commands, $chunk, true) as $part) {
            $tags = [];
            foreach ($part as $index => [$cmd, $attrs]) {
                $tag = 't' . (++$this->tagSeq);
                $tags[$tag] = $index;
                $words = [$cmd];
                foreach ($attrs as $k => $v) {
                    $words[] = '=' . $k . '=' . $this->stringify($v);
                }
                $words[] = '.tag=' . $tag;
                $this->writeSentence($words);
                $results[$index] = ['ok' => false, 'ret' => null, 'error' => null];
            }
            $pending = count($tags);
            while ($pending > 0) {
                $s = $this->readSentence();
                $tag = $s['.tag'] ?? null;
                if ($s['!type'] === '!fatal') {
                    throw new RouterOSException('RouterOS a fermé la connexion : ' . ($s['message'] ?? ''));
                }
                if ($tag === null || !isset($tags[$tag])) {
                    continue;
                }
                $idx = $tags[$tag];
                if ($s['!type'] === '!trap') {
                    $results[$idx]['error'] = $s['message'] ?? 'Erreur RouterOS';
                } elseif ($s['!type'] === '!done') {
                    if ($results[$idx]['error'] === null) {
                        $results[$idx]['ok'] = true;
                        $results[$idx]['ret'] = $s['ret'] ?? null;
                    }
                    $pending--;
                }
            }
        }
        return $results;
    }

    private function talk(string $command, array $attrs = [], array $queries = []): array
    {
        $words = [$command];
        foreach ($attrs as $k => $v) {
            $words[] = '=' . $k . '=' . $this->stringify($v);
        }
        foreach ($queries as $k => $v) {
            $words[] = (str_starts_with((string) $k, '?') ? $k : '?' . $k) . '=' . $this->stringify($v);
        }
        $this->writeSentence($words);

        $rows = [];
        $trap = null;
        while (true) {
            $s = $this->readSentence();
            $type = $s['!type'];
            if ($type === '!trap') {
                $trap = $s['message'] ?? 'Erreur RouterOS';
                continue;
            }
            if ($type === '!fatal') {
                $this->disconnect();
                throw new RouterOSException('RouterOS a fermé la connexion : ' . ($s['message'] ?? $trap ?? ''));
            }
            $rows[] = $s;
            if ($type === '!done') {
                break;
            }
        }
        if ($trap !== null) {
            throw new RouterOSException($trap);
        }
        return $rows;
    }

    private function stringify(mixed $v): string
    {
        return is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v;
    }

    private function writeSentence(array $words): void
    {
        if (!$this->socket) {
            throw new RouterOSException('Non connecté au routeur.');
        }
        $data = '';
        foreach ($words as $w) {
            $data .= self::encodeLength(strlen($w)) . $w;
        }
        $data .= "\x00";
        $len = strlen($data);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($this->socket, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new RouterOSException('Écriture impossible vers le routeur (connexion perdue).');
            }
            $written += $n;
        }
    }

    /** @return array<string, string> avec la clé spéciale « !type » */
    private function readSentence(): array
    {
        $sentence = [];
        while (true) {
            $word = $this->readWord();
            if ($word === '') {
                if ($sentence === []) {
                    continue;
                }
                return $sentence;
            }
            if ($sentence === []) {
                $sentence['!type'] = $word;
                continue;
            }
            if ($word[0] === '=') {
                $pos = strpos($word, '=', 1);
                if ($pos === false) {
                    $sentence[substr($word, 1)] = '';
                } else {
                    $sentence[substr($word, 1, $pos - 1)] = substr($word, $pos + 1);
                }
            } elseif (str_starts_with($word, '.tag=')) {
                $sentence['.tag'] = substr($word, 5);
            }
        }
    }

    private function readWord(): string
    {
        $len = self::decodeLength(fn (int $n) => $this->readBytes($n));
        return $len === 0 ? '' : $this->readBytes($len);
    }

    private function readBytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = @fread($this->socket, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = $this->socket ? stream_get_meta_data($this->socket) : [];
                if (!empty($meta['timed_out'])) {
                    throw new RouterOSException('Délai de réponse du routeur dépassé.');
                }
                if (!$this->socket || feof($this->socket)) {
                    throw new RouterOSException('Connexion fermée par le routeur.');
                }
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    public static function encodeLength(int $len): string
    {
        return match (true) {
            $len < 0x80 => chr($len),
            $len < 0x4000 => pack('n', $len | 0x8000),
            $len < 0x200000 => substr(pack('N', $len | 0xC00000), 1),
            $len < 0x10000000 => pack('N', $len | 0xE0000000),
            default => "\xF0" . pack('N', $len),
        };
    }

    public static function decodeLength(callable $read): int
    {
        $b = ord($read(1));
        if (($b & 0x80) === 0x00) {
            return $b;
        }
        if (($b & 0xC0) === 0x80) {
            return (($b & 0x3F) << 8) + ord($read(1));
        }
        if (($b & 0xE0) === 0xC0) {
            return (($b & 0x1F) << 16) + unpack('n', $read(2))[1];
        }
        if (($b & 0xF0) === 0xE0) {
            $r = $read(3);
            return (($b & 0x0F) << 24) + (ord($r[0]) << 16) + (ord($r[1]) << 8) + ord($r[2]);
        }
        return unpack('N', $read(4))[1];
    }

    /** Convertit une durée RouterOS (« 1w2d3h4m5s » ou « 01:02:03 ») en secondes. */
    public static function parseDuration(?string $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (preg_match('/^(?:(\d+)d\s*)?(\d+):(\d+):(\d+)/', $value, $m)) {
            return ((int) $m[1]) * 86400 + (int) $m[2] * 3600 + (int) $m[3] * 60 + (int) $m[4];
        }
        $total = 0;
        preg_match_all('/(\d+)(w|d|h|ms|m|s)/', $value, $all, PREG_SET_ORDER);
        foreach ($all as [, $n, $u]) {
            $total += (int) $n * match ($u) { 'w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1, default => 0 };
        }
        return $total;
    }

    /** Secondes → durée RouterOS (ex. 90061 → « 1d1h1m1s »). */
    public static function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }
        $out = '';
        foreach (['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1] as $u => $s) {
            if ($seconds >= $s) {
                $out .= intdiv($seconds, $s) . $u;
                $seconds %= $s;
            }
        }
        return $out;
    }
}
