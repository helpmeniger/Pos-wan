<?php
declare(strict_types=1);

namespace Addons\MarwaVoice;

use App\Core\BusinessException;

/**
 * Synthèse vocale locale avec Piper (https://github.com/rhasspy/piper, licence MIT), sans Internet.
 *
 * - Fichiers : storage/voix/piper/ (programme), storage/voix/voix/ (voix .onnx + .onnx.json),
 *   storage/voix/cache/ (phrases déjà générées, réutilisées telles quelles).
 * - Service persistant (php bin/voix.php service) : un seul Piper chargé en mémoire, qui lit les
 *   phrases l'une après l'autre (≈ 0,1 s par phrase). Sans lui, Piper est lancé pour chaque phrase
 *   nouvelle (≈ 1 à 2 s), un seul à la fois.
 * - Aucune commande système n'est construite avec le texte : il est transmis à Piper par son
 *   entrée standard (JSON), le programme est lancé sans interpréteur de commandes.
 */
final class PiperService
{
    public const MAX_TEXT = 400;
    /** Pause entre deux phrases (s) : listes de plats plus faciles à suivre en cuisine. */
    public const SENTENCE_SILENCE = '0.35';
    public const CACHE_MAX_FILES = 2000;
    public const CACHE_MAX_BYTES = 300 * 1048576;

    public static function dir(string $sub = ''): string
    {
        return BASE_PATH . '/storage/voix' . ($sub !== '' ? '/' . $sub : '');
    }

    /** Paquet intégré au module (addons/marwa_voice/piper) : Piper pour Windows + voix française, en fragments ≤ 1 Mo. */
    public static function bundleDir(): string
    {
        return dirname(__DIR__) . '/piper';
    }

    public static function bundle(): ?array
    {
        $f = self::bundleDir() . '/paquet.json';
        $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        return is_array($d) && isset($d['files']) && is_array($d['files']) ? $d : null;
    }

    /**
     * Reconstitue les fichiers du paquet dans storage/voix (programme Piper : Windows uniquement ;
     * voix : tous systèmes). Chaque fichier est vérifié par son SHA-256 avant d'être utilisé.
     * Ne fait rien si c'est déjà fait (repère storage/voix/.paquet-<version>).
     * @return array{deployed:int, skipped:int, done:bool}
     */
    public static function deployBundled(?string $base = null, ?bool $windows = null, bool $force = false): array
    {
        $b = self::bundle();
        $out = ['deployed' => 0, 'skipped' => 0, 'done' => false];
        if (!$b) {
            return $out;
        }
        $base ??= self::dir();
        $windows ??= PHP_OS_FAMILY === 'Windows';
        $marker = $base . '/.paquet-' . substr(hash('sha256', json_encode($b['files'])), 0, 16) . ($windows ? '-win' : '');
        if (!$force && is_file($marker)) {
            return ['done' => true] + $out;
        }
        if (!is_dir($base) && !@mkdir($base, 0775, true) && !is_dir($base)) {
            throw new BusinessException('Dossier ' . $base . ' impossible à créer.');
        }
        $lock = fopen($base . '/paquet.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return $out; // déploiement déjà en cours (autre requête)
        }
        try {
            foreach ($b['files'] as $rel => $f) {
                if (!preg_match('#^(piper|voix)/[A-Za-z0-9_./-]+$#', $rel) || str_contains($rel, '..')) {
                    throw new BusinessException('Paquet vocal invalide : ' . $rel);
                }
                if (str_starts_with($rel, 'piper/') && !$windows) {
                    continue;
                }
                $target = $base . '/' . $rel;
                if (!$force && is_file($target) && filesize($target) === (int) $f['size'] && hash_file('sha256', $target) === $f['sha256']) {
                    $out['skipped']++;
                    continue;
                }
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0775, true);
                }
                $tmp = $target . '.tmp';
                $w = fopen($tmp, 'wb');
                $parts = (int) $f['parts'];
                for ($i = 0; $i < max(1, $parts); $i++) {
                    $src = self::bundleDir() . '/' . $rel . ($parts > 0 ? sprintf('.part%03d', $i) : '');
                    $r = @fopen($src, 'rb');
                    if (!$r) {
                        fclose($w);
                        @unlink($tmp);
                        throw new BusinessException('Fragment manquant dans le paquet vocal : ' . basename($src) . ' (recopiez le dossier addons/marwa_voice).');
                    }
                    stream_copy_to_stream($r, $w);
                    fclose($r);
                }
                fclose($w);
                if (hash_file('sha256', $tmp) !== $f['sha256']) {
                    @unlink($tmp);
                    throw new BusinessException('Fichier vocal altéré (empreinte SHA-256 différente) : ' . $rel . '. Recopiez le dossier addons/marwa_voice.');
                }
                if (!@rename($tmp, $target)) {
                    @unlink($tmp);
                    throw new BusinessException('Impossible de remplacer ' . $rel . ' (fichier utilisé ?) : fermez la fenêtre « POS Marwa - service vocal » puis réessayez.');
                }
                if (str_ends_with($rel, '.exe') || $rel === 'piper/piper') {
                    @chmod($target, 0755);
                }
                $out['deployed']++;
            }
            file_put_contents($marker, date('c'));
            $out['done'] = true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $out;
    }

    /** Déploiement automatique au premier usage (silencieux : une erreur sera visible dans le Diagnostic). */
    private static function autoDeploy(): void
    {
        static $tried = false;
        if ($tried) {
            return;
        }
        $tried = true;
        try {
            self::deployBundled();
        } catch (\Throwable $e) {
            error_log('MARWA VOICE : ' . $e->getMessage());
        }
    }

    public static function binary(): ?string
    {
        self::autoDeploy();
        foreach (['piper/piper.exe', 'piper/piper'] as $f) {
            $p = self::dir($f);
            if (is_file($p) && (PHP_OS_FAMILY === 'Windows' ? str_ends_with($p, '.exe') : is_executable($p))) {
                return $p;
            }
        }
        return null;
    }

    /** Voix installées : fichier .onnx accompagné de sa configuration .onnx.json. */
    public static function voices(): array
    {
        self::autoDeploy();
        $out = [];
        foreach (glob(self::dir('voix') . '/*.onnx') ?: [] as $f) {
            if (!is_file($f . '.json')) {
                continue;
            }
            $cfg = json_decode((string) file_get_contents($f . '.json'), true) ?: [];
            $lang = (string) ($cfg['language']['code'] ?? ($cfg['espeak']['voice'] ?? ''));
            $out[] = ['file' => basename($f), 'name' => preg_replace('/\.onnx$/', '', basename($f)), 'lang' => $lang,
                'quality' => (string) ($cfg['audio']['quality'] ?? ''), 'rate' => (int) ($cfg['audio']['sample_rate'] ?? 0), 'size' => filesize($f)];
        }
        return $out;
    }

    /** Voix choisie dans les réglages, sinon la première voix française installée. */
    public static function voice(): ?string
    {
        $voices = self::voices();
        $want = (string) setting('voix_piper_voix', '');
        foreach ($voices as $v) {
            if ($v['file'] === $want) {
                return $v['file'];
            }
        }
        foreach ($voices as $v) {
            if (str_starts_with($v['lang'], 'fr')) {
                return $v['file'];
            }
        }
        return $voices[0]['file'] ?? null;
    }

    public static function available(): bool
    {
        return self::binary() !== null && self::voice() !== null;
    }

    public static function port(): int
    {
        return max(1024, min(65535, (int) setting('voix_piper_port', '59125')));
    }

    public static function token(): ?string
    {
        $f = self::dir('service.token');
        $t = is_file($f) ? trim((string) file_get_contents($f)) : '';
        return preg_match('/^[a-f0-9]{48}$/', $t) ? $t : null;
    }

    /** Texte accepté par la synthèse : sans caractères de contrôle, longueur limitée. */
    public static function clean(string $text): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text)));
        if ($t === '') {
            throw new BusinessException('Texte vide.');
        }
        if (mb_strlen($t) > self::MAX_TEXT) {
            throw new BusinessException('Texte trop long pour une annonce (' . self::MAX_TEXT . ' caractères au plus).');
        }
        return $t;
    }

    /**
     * Fichier WAV de la phrase (cache, sinon service persistant, sinon Piper lancé directement).
     * @return array{path:string, cached:bool, via:string, ms:int}
     */
    public static function synth(string $text): array
    {
        $text = self::clean($text);
        $bin = self::binary();
        $voice = self::voice();
        if (!$bin || !$voice) {
            throw new BusinessException('Piper n\'est pas installé sur le serveur (voir Assistant vocal → Diagnostic).');
        }
        $cache = self::dir('cache');
        if (!is_dir($cache) && !@mkdir($cache, 0775, true) && !is_dir($cache)) {
            throw new BusinessException('Dossier storage/voix/cache impossible à créer.');
        }
        $key = hash('sha256', $voice . "\n" . self::SENTENCE_SILENCE . "\n" . $text);
        $path = "$cache/$key.wav";
        $t0 = hrtime(true);
        if (is_file($path) && filesize($path) > 44) {
            @touch($path);
            return ['path' => $path, 'cached' => true, 'via' => 'cache', 'ms' => 0];
        }
        $tmp = "$cache/$key.part.wav";
        $via = self::viaService($text, $voice, $tmp) ? 'service' : 'direct';
        if ($via === 'direct') {
            self::direct($bin, $voice, $text, $tmp);
        }
        if (!is_file($tmp) || filesize($tmp) <= 44 || file_get_contents($tmp, false, null, 0, 4) !== 'RIFF') {
            @unlink($tmp);
            throw new BusinessException('Piper n\'a pas produit de son pour cette phrase.');
        }
        self::normalizeVolume($tmp);
        rename($tmp, $path);
        if (random_int(1, 40) === 1) {
            self::trimCache();
        }
        return ['path' => $path, 'cached' => false, 'via' => $via, 'ms' => (int) ((hrtime(true) - $t0) / 1e6)];
    }

    /** Demande au service persistant (bin/voix.php service) ; false s'il ne répond pas. */
    private static function viaService(string $text, string $voice, string $out): bool
    {
        $token = self::token();
        if (!$token) {
            return false;
        }
        $s = @stream_socket_client('tcp://127.0.0.1:' . self::port(), $errno, $err, 0.4);
        if (!$s) {
            return false;
        }
        stream_set_timeout($s, 30);
        fwrite($s, json_encode(['token' => $token, 'text' => $text, 'voice' => $voice, 'out' => basename($out)], JSON_UNESCAPED_UNICODE) . "\n");
        $line = (string) fgets($s, 1024);
        fclose($s);
        return str_starts_with($line, 'OK');
    }

    /** Piper lancé pour une phrase (un seul à la fois sur le serveur : verrou de fichier). */
    private static function direct(string $bin, string $voice, string $text, string $out): void
    {
        $lock = fopen(self::dir('piper.lock'), 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new BusinessException('Synthèse vocale occupée.');
        }
        try {
            $cmd = [$bin, '--model', self::dir('voix/' . $voice), '--output_file', $out, '--sentence_silence', self::SENTENCE_SILENCE, '--quiet'];
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($bin));
            if (!is_resource($proc)) {
                throw new BusinessException('Piper n\'a pas pu être lancé.');
            }
            fwrite($pipes[0], $text . "\n");
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + 30;
            $errOut = '';
            do {
                usleep(20000);
                $errOut .= (string) stream_get_contents($pipes[2]);
                stream_get_contents($pipes[1]);
                $st = proc_get_status($proc);
            } while ($st['running'] && microtime(true) < $deadline);
            if ($st['running']) {
                proc_terminate($proc);
                throw new BusinessException('Piper n\'a pas répondu à temps.');
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            if (($st['exitcode'] ?? 0) !== 0) {
                throw new BusinessException('Piper a échoué : ' . mb_substr(trim($errOut), -200));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Volume normalisé : le son est amplifié jusqu'à un niveau crête de 90 % (sans saturation),
     * pour des annonces bien audibles en cuisine ou en boutique. WAV PCM 16 bits uniquement.
     */
    public static function normalizeVolume(string $file, float $target = 0.9, float $maxGain = 4.0): float
    {
        $data = (string) file_get_contents($file);
        $pos = strpos($data, 'data');
        if (strlen($data) < 48 || substr($data, 0, 4) !== 'RIFF' || $pos === false || unpack('v', substr($data, 34, 2))[1] !== 16) {
            return 1.0;
        }
        $start = $pos + 8;
        $samples = unpack('s*', substr($data, $start));
        $peak = 1;
        foreach ($samples as $v) {
            $a = $v < 0 ? -$v : $v;
            if ($a > $peak) {
                $peak = $a;
            }
        }
        $gain = min($maxGain, $target * 32767 / $peak);
        if ($gain <= 1.02) {
            return 1.0;
        }
        foreach ($samples as $k => $v) {
            $samples[$k] = max(-32768, min(32767, (int) round($v * $gain)));
        }
        file_put_contents($file, substr($data, 0, $start) . pack('s*', ...$samples));
        return round($gain, 2);
    }

    /** Cache limité (nombre de phrases et taille) : les moins récemment utilisées sont retirées, puis regénérées au besoin. */
    public static function trimCache(int $maxFiles = self::CACHE_MAX_FILES, int $maxBytes = self::CACHE_MAX_BYTES): int
    {
        $files = glob(self::dir('cache') . '/*.wav') ?: [];
        $sizes = array_map('filesize', $files);
        $total = array_sum($sizes);
        if (count($files) <= $maxFiles && $total <= $maxBytes) {
            return 0;
        }
        $order = array_keys($files);
        usort($order, fn($a, $b) => filemtime($files[$a]) <=> filemtime($files[$b]));
        $left = count($files);
        $n = 0;
        foreach ($order as $i) {
            if ($left <= $maxFiles && $total <= $maxBytes) {
                break;
            }
            if (@unlink($files[$i])) {
                $total -= $sizes[$i];
                $left--;
                $n++;
            }
        }
        return $n;
    }

    public static function clearCache(): int
    {
        $n = 0;
        foreach (glob(self::dir('cache') . '/*.wav') ?: [] as $f) {
            if (@unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    /** État pour la page de diagnostic. */
    public static function status(): array
    {
        $files = glob(self::dir('cache') . '/*.wav') ?: [];
        $s = @stream_socket_client('tcp://127.0.0.1:' . self::port(), $errno, $err, 0.3);
        if ($s) {
            fclose($s);
        }
        return ['binary' => self::binary(), 'voices' => self::voices(), 'voice' => self::voice(), 'service' => (bool) $s && self::token() !== null,
            'port' => self::port(), 'cache_files' => count($files), 'cache_bytes' => array_sum(array_map('filesize', $files)),
            'proc_open' => function_exists('proc_open')];
    }
}
