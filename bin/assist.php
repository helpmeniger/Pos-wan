<?php
/**
 * MARWA REMOTE ASSIST — serveur de rendez-vous et de relais (RustDesk Server) et outils.
 *   php bin/assist.php serveur            lance le serveur HTN (hbbs + hbbr, clé imposée) ; Ctrl+C pour arrêter
 *   php bin/assist.php etat               état du serveur local et du paquet
 *   php bin/assist.php cle                affiche la clé publique à donner aux boutiques
 *   php bin/assist.php deployer           reconstitue les programmes livrés (vérifiés SHA-256)
 *   php bin/assist.php preparer-paquet <rustdesk.exe> <serveur-windows.zip> <serveur-linux.zip>   (auteur)
 *
 * Le serveur n'a besoin d'aucun nom de domaine : une adresse IP joignable (VPS, ou box avec
 * redirection des ports TCP 21115-21117 et UDP 21116) suffit. Il ne voit que du trafic chiffré.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/addons/marwa_assist/src/Bundle.php';
require_once dirname(__DIR__) . '/addons/marwa_assist/src/RelayServer.php';

use Addons\MarwaAssist\Bundle;
use Addons\MarwaAssist\RelayServer;

const SOURCES = [
    'client' => ['RustDesk 1.5.0 (Windows x64)', '8555777215510d83d2d61c9dc984e4fcc838bd7e79f9d18a42585431f5e8bb47'],
    'srv-win' => ['RustDesk Server 1.1.16 (Windows x64)', 'b865a3a62fc8755b45480c508f1c4871c3338590408dda8c58c7e9c373b7adb0'],
    'srv-linux' => ['RustDesk Server 1.1.16 (Linux amd64)', '0565c41affe6c3f409b0bddfaf5a24ccbf3f64f5f8e3fec250b69a0d5f6bdbcf'],
];

function out(string $s): void { echo $s . "\n"; }

/** (Auteur) Paquet : fichiers officiels non modifiés, découpés en fragments ≤ 1 Mo. */
function prepare_bundle(string $client, string $srvWin, string $srvLinux): void
{
    foreach (['client' => $client, 'srv-win' => $srvWin, 'srv-linux' => $srvLinux] as $k => $f) {
        if (!is_file($f) || !hash_equals(SOURCES[$k][1], hash_file('sha256', $f))) {
            throw new RuntimeException('Fichier absent ou empreinte inattendue pour ' . SOURCES[$k][0] . ' : ' . $f);
        }
    }
    $files = ['client/rustdesk.exe' => $client];
    $tmp = sys_get_temp_dir() . '/paquet-assist-' . getmypid();
    foreach (['windows' => [$srvWin, 'x86_64', ['hbbs.exe', 'hbbr.exe']], 'linux' => [$srvLinux, 'amd64', ['hbbs', 'hbbr']]] as $os => [$zip, $dir, $names]) {
        $z = new ZipArchive();
        if ($z->open($zip) !== true || !$z->extractTo("$tmp/$os")) {
            throw new RuntimeException('Archive illisible : ' . $zip);
        }
        $z->close();
        foreach ($names as $n) {
            $files["serveur/$os/$n"] = "$tmp/$os/$dir/$n";
        }
    }
    $dest = Bundle::dir();
    foreach (['client', 'serveur'] as $d) {
        if (is_dir("$dest/$d")) {
            exec('rm -rf ' . escapeshellarg("$dest/$d"));
        }
    }
    $index = [];
    foreach ($files as $rel => $src) {
        $size = filesize($src);
        $parts = max(1, (int) ceil($size / 1000000));
        @mkdir(dirname("$dest/$rel"), 0775, true);
        $h = fopen($src, 'rb');
        for ($i = 0; $i < $parts; $i++) {
            file_put_contents(sprintf('%s/%s.part%03d', $dest, $rel, $i), (string) fread($h, 1000000));
        }
        fclose($h);
        $index[$rel] = ['size' => $size, 'sha256' => hash_file('sha256', $src), 'parts' => $parts];
    }
    file_put_contents("$dest/paquet.json", json_encode(['version' => 1, 'client' => SOURCES['client'][0], 'serveur' => SOURCES['srv-win'][0] . ' / Linux amd64',
        'files' => $index], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    exec('rm -rf ' . escapeshellarg($tmp));
    out(count($index) . ' programme(s), ' . round(array_sum(array_column($index, 'size')) / 1048576, 1) . ' Mo → ' . $dest);
}

$cmd = $argv[1] ?? 'etat';
try {
    switch ($cmd) {
        case 'serveur':
            RelayServer::run(fn(string $m) => out(date('H:i:s') . ' ' . $m));
            break;
        case 'etat':
            $s = RelayServer::status();
            out('Paquet        : ' . (Bundle::manifest() ? 'présent' : 'absent'));
            out('Serveur HTN   : ' . ($s['running'] ? 'actif (ports ' . implode(', ', array_keys(array_filter($s['ports']))) . ')' : 'arrêté sur ce poste'));
            out('Clé publique  : ' . ($s['key'] ?? '— (créée au premier démarrage du serveur)'));
            break;
        case 'cle':
            $k = RelayServer::publicKey();
            out($k ?? 'Aucune clé : démarrez d\'abord le serveur (php bin/assist.php serveur).');
            break;
        case 'deployer':
            $r = Bundle::deploy('', null, in_array('--forcer', $argv, true));
            out($r['done'] ? "Programmes prêts : {$r['deployed']} reconstitué(s) et vérifié(s), {$r['skipped']} déjà en place." : 'Déploiement en cours ailleurs, réessayez.');
            break;
        case 'preparer-paquet':
            prepare_bundle((string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''), (string) ($argv[4] ?? ''));
            break;
        default:
            fwrite(STDERR, "Commandes : serveur | etat | cle | deployer | preparer-paquet\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Erreur : ' . $e->getMessage() . "\n");
    exit(1);
}
