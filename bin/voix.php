<?php
/**
 * MARWA VOICE AI — voix locale Piper en ligne de commande.
 *   php bin/voix.php installer [siwis-medium|siwis-low]   télécharge Piper + une voix française (une seule fois, Internet requis)
 *   php bin/voix.php etat                                  état de l'installation
 *   php bin/voix.php test "Texte à lire"                   génère la phrase et indique le temps
 *   php bin/voix.php service                               service persistant (laisser tourner ; Ctrl+C pour arrêter)
 *   php bin/voix.php vider-cache                           retire les phrases déjà générées
 *
 * Fichiers téléchargés vérifiés par leur empreinte SHA-256 (refusés s'ils ne correspondent pas).
 * Piper : licence MIT. Voix « siwis » : jeu de données SIWIS, licence CC BY 4.0 (attribution).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/addons/marwa_voice/src/PiperService.php';

use Addons\MarwaVoice\PiperService as Piper;

const PIPER_REL = 'https://github.com/rhasspy/piper/releases/download/';
const PROGRAMS = [
    'Windows' => ['2023.11.14-2/piper_windows_amd64.zip', 'f3c58906402b24f3a96d92145f58acba6d86c9b5db896d207f78dc80811efcea'],
    'Linux' => ['2023.11.14-2/piper_linux_x86_64.tar.gz', 'a50cb45f355b7af1f6d758c1b360717877ba0a398cc8cbe6d2a7a3a26e225992'],
];
const VOICES = [
    'siwis-medium' => ['v0.0.2/voice-fr-siwis-medium.tar.gz', '0c9ecdf9ecac6de4a46be85a162bffe0db7145bd3a4175831cea6cab4b41eefd', 'Française, féminine, qualité moyenne (≈ 58 Mo à télécharger)'],
    'siwis-low' => ['v0.0.2/voice-fr-siwis-low.tar.gz', 'd3db8d47053e9b4108e1c1d29d5ea2b5b1a152183616c3134c222110ccde20f2', 'Française, féminine, légère pour les PC modestes (≈ 19 Mo à télécharger)'],
];

function out(string $s): void { echo $s . "\n"; }

function download(string $url, string $dest, string $sha): void
{
    if (is_file($dest) && hash_file('sha256', $dest) === $sha) {
        out("  déjà téléchargé : " . basename($dest));
        return;
    }
    out("  téléchargement : $url");
    $fh = fopen($dest . '.part', 'wb');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 1800, CURLOPT_FAILONERROR => true, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS]);
    if (getenv('SSL_CERT_FILE')) {
        curl_setopt($ch, CURLOPT_CAINFO, getenv('SSL_CERT_FILE'));
    }
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if (!$ok) {
        @unlink($dest . '.part');
        throw new RuntimeException("Téléchargement impossible ($err). Vérifiez la connexion Internet ; sinon téléchargez ce fichier avec le navigateur et placez-le dans storage/voix/telechargements/, puis relancez : il sera vérifié et utilisé.");
    }
    $got = hash_file('sha256', $dest . '.part');
    if (!hash_equals($sha, $got)) {
        @unlink($dest . '.part');
        throw new RuntimeException("Fichier refusé : empreinte SHA-256 inattendue ($got).");
    }
    rename($dest . '.part', $dest);
}

function extract_archive(string $file, string $to): void
{
    if (!is_dir($to)) {
        mkdir($to, 0775, true);
    }
    if (str_ends_with($file, '.zip')) {
        $z = new ZipArchive();
        if ($z->open($file) !== true || !$z->extractTo($to)) {
            throw new RuntimeException('Archive ZIP illisible : ' . basename($file));
        }
        $z->close();
    } elseif (PHP_OS_FAMILY !== 'Windows') {
        // tar du système : conserve les liens symboliques des bibliothèques (PharData les rend vides)
        $p = proc_open(['tar', '-xzf', $file, '-C', $to], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $err = is_resource($p) ? stream_get_contents($pipes[2]) : 'tar introuvable';
        if (!is_resource($p) || proc_close($p) !== 0) {
            throw new RuntimeException('Archive illisible : ' . basename($file) . ' ' . trim((string) $err));
        }
    } else {
        (new PharData($file))->extractTo($to, null, true);
    }
}

/** Service persistant : un Piper chargé, des phrases lues une par une (TCP local 127.0.0.1 uniquement). */
function service(): void
{
    $bin = Piper::binary();
    if (!$bin || !Piper::voices()) {
        throw new RuntimeException('Piper ou voix absents : lancez d\'abord « php bin/voix.php installer ».');
    }
    $port = Piper::port();
    $cache = Piper::dir('cache');
    if (!is_dir($cache)) {
        mkdir($cache, 0775, true);
    }
    $server = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $err);
    if (!$server) {
        throw new RuntimeException("Port $port indisponible ($err) : le service tourne peut-être déjà.");
    }
    $token = bin2hex(random_bytes(24));
    file_put_contents(Piper::dir('service.token'), $token);
    @chmod(Piper::dir('service.token'), 0600);
    $available = array_column(Piper::voices(), 'file');
    $proc = null; $pipes = []; $model = null;
    $start = function (string $voice) use ($bin, &$proc, &$pipes, &$model): void {
        if (is_resource($proc)) {
            @fclose($pipes[0]);
            proc_terminate($proc);
            proc_close($proc);
        }
        $proc = proc_open([$bin, '--model', Piper::dir('voix/' . $voice), '--json-input', '--quiet'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', Piper::dir('service.log'), 'a']], $pipes, dirname($bin));
        if (!is_resource($proc)) {
            throw new RuntimeException('Piper n\'a pas pu être lancé.');
        }
        $model = $voice;
        out(date('H:i:s') . " voix chargée : $voice");
    };
    out("Service vocal POS Marwa : 127.0.0.1:$port (Ctrl+C pour arrêter)");
    while (true) {
        $c = @stream_socket_accept($server, 3600);
        if (!$c) {
            continue;
        }
        stream_set_timeout($c, 5);
        $req = json_decode((string) fgets($c, 4096), true);
        $reply = 'ERR requête invalide';
        try {
            if (!is_array($req) || !hash_equals($token, (string) ($req['token'] ?? ''))) {
                throw new RuntimeException('jeton refusé');
            }
            $voice = (string) ($req['voice'] ?? '');
            $outName = (string) ($req['out'] ?? '');
            if (!in_array($voice, $available, true)) {
                $available = array_column(Piper::voices(), 'file'); // voix ajoutée depuis le démarrage ?
                if (!in_array($voice, $available, true)) {
                    throw new RuntimeException('voix inconnue');
                }
            }
            if (!preg_match('/^[a-f0-9]{64}\.part\.wav$/', $outName)) {
                throw new RuntimeException('nom de fichier refusé');
            }
            $text = Piper::clean((string) ($req['text'] ?? ''));
            if ($model !== $voice || !is_resource($proc) || !proc_get_status($proc)['running']) {
                $start($voice);
            }
            $target = $cache . '/' . $outName;
            fwrite($pipes[0], json_encode(['text' => $text, 'output_file' => $target], JSON_UNESCAPED_UNICODE) . "\n");
            fflush($pipes[0]);
            $r = [$pipes[1]]; $w = null; $e = null;
            if (!stream_select($r, $w, $e, 30)) {
                $start($voice); // bloqué : relance
                throw new RuntimeException('délai dépassé');
            }
            $line = trim((string) fgets($pipes[1], 4096));
            $reply = $line !== '' && is_file($target) ? 'OK' : 'ERR aucun son';
        } catch (Throwable $ex) {
            $reply = 'ERR ' . $ex->getMessage();
        }
        @fwrite($c, $reply . "\n");
        fclose($c);
    }
}

$cmd = $argv[1] ?? 'etat';
try {
    switch ($cmd) {
        case 'installer':
            $vk = $argv[2] ?? 'siwis-medium';
            if (!isset(VOICES[$vk])) {
                throw new RuntimeException('Voix inconnue. Choix : ' . implode(', ', array_keys(VOICES)));
            }
            $os = PHP_OS_FAMILY;
            if (!isset(PROGRAMS[$os]) || (PHP_OS_FAMILY === 'Linux' && php_uname('m') !== 'x86_64')) {
                throw new RuntimeException("Système non pris en charge par cet installateur ($os " . php_uname('m') . ').');
            }
            foreach (['curl', 'zip', 'phar'] as $ext) {
                if (!extension_loaded($ext)) {
                    throw new RuntimeException("Extension PHP « $ext » nécessaire (activez-la dans WampServer → PHP → Extensions).");
                }
            }
            $dl = Piper::dir('telechargements');
            @mkdir($dl, 0775, true);
            out('1/2 Programme Piper (' . $os . ')');
            [$rel, $sha] = PROGRAMS[$os];
            download(PIPER_REL . $rel, "$dl/" . basename($rel), $sha);
            extract_archive("$dl/" . basename($rel), Piper::dir());
            if ($os !== 'Windows') {
                @chmod(Piper::dir('piper/piper'), 0755);
            }
            out('2/2 Voix ' . $vk . ' — ' . VOICES[$vk][2]);
            [$rel, $sha] = VOICES[$vk];
            download(PIPER_REL . $rel, "$dl/" . basename($rel), $sha);
            extract_archive("$dl/" . basename($rel), Piper::dir('voix'));
            out('Installation terminée. Essai : php bin/voix.php test "Bonjour, je suis la voix de POS Marwa."');
            out('Les fichiers téléchargés (storage/voix/telechargements) peuvent être supprimés.');
            break;
        case 'etat':
            $s = Piper::status();
            out('Programme Piper : ' . ($s['binary'] ?? 'absent'));
            out('Voix installées : ' . ($s['voices'] ? implode(', ', array_column($s['voices'], 'name')) : 'aucune'));
            out('Voix utilisée   : ' . ($s['voice'] ?? '—'));
            out('Service         : ' . ($s['service'] ? 'actif sur 127.0.0.1:' . $s['port'] : 'arrêté (Piper sera lancé pour chaque phrase nouvelle)'));
            out('Cache           : ' . $s['cache_files'] . ' phrase(s), ' . round($s['cache_bytes'] / 1048576, 1) . ' Mo');
            break;
        case 'test':
            $r = Piper::synth($argv[2] ?? 'Bonjour, je suis la voix de POS Marwa.');
            out(($r['cached'] ? 'Depuis le cache' : 'Généré (' . $r['via'] . ') en ' . $r['ms'] . ' ms') . ' : ' . $r['path']);
            break;
        case 'service':
            service();
            break;
        case 'vider-cache':
            out(Piper::clearCache() . ' phrase(s) retirée(s).');
            break;
        default:
            fwrite(STDERR, "Commandes : installer [voix] | etat | test \"texte\" | service | vider-cache\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Erreur : ' . $e->getMessage() . "\n");
    exit(1);
}
