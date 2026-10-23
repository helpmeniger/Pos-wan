<?php
/**
 * Clinique — imagerie : réception automatique des images des appareils.
 *   php bin/imagerie.php serveur        récepteur DICOM (port et titre AE des réglages) + surveillance du dossier d'arrivée
 *   php bin/imagerie.php dossier        traite une fois le dossier storage/imagerie/arrivee
 *   php bin/imagerie.php importer <chemin>   importe un fichier ou un dossier (CD/DVD, clé USB) ; rattachement automatique
 *   php bin/imagerie.php etat           état du récepteur et derniers examens
 *
 * À lancer sur le PC qui héberge POS Marwa (windows\imagerie-serveur.bat). Le port DICOM ne doit
 * être ouvert qu'au réseau local de la clinique, jamais à Internet.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use Addons\Clinique\DicomServer;
use Addons\Clinique\ImagingService as IS;
use App\Core\DB;

function out(string $s): void
{
    echo date('H:i:s') . ' ' . $s . "\n";
    App\Core\Logger::info('Imagerie : ' . $s);
}

/** Exécute $fn ; après une coupure MySQL (attente prolongée), reconnexion puis nouvel essai. */
function withDb(callable $fn): mixed
{
    try {
        return $fn();
    } catch (PDOException $e) {
        DB::setConnection(null);
        return $fn();
    }
}

if (($argv[1] ?? '') === 'port') {
    echo (int) setting('clinique_imagerie_port', '11112'), "\n"; // utilisé par windows\imagerie-serveur.bat (pare-feu)
    exit(0);
}
if (!App\Core\Addons::isActive('clinique')) {
    fwrite(STDERR, "Le module Clinique n'est pas actif.\n");
    exit(1);
}
$cmd = $argv[1] ?? 'etat';

if ($cmd === 'dossier') {
    out(IS::scanFolder(fn($m) => out($m)) . ' fichier(s) traité(s).');
    exit(0);
}

if ($cmd === 'importer') {
    $src = $argv[2] ?? '';
    if ($src === '' || !file_exists($src)) {
        fwrite(STDERR, "Chemin introuvable.\n");
        exit(1);
    }
    $files = is_dir($src) ? iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS))) : [new SplFileInfo($src)];
    $ok = $ko = 0;
    foreach ($files as $f) {
        if (!$f->isFile() || strtoupper($f->getFilename()) === 'DICOMDIR') {
            continue;
        }
        try {
            $r = IS::ingest($f->getPathname(), ['source' => 'import']);
            $ok++;
            out($f->getFilename() . ($r['duplicate'] ? ' : déjà présent' : ' → examen n° ' . $r['study_id'] . ($r['matched'] ? '' : ' (à rattacher)')));
        } catch (Throwable $e) {
            $ko++;
            out($f->getFilename() . ' ignoré : ' . $e->getMessage());
        }
    }
    out("$ok fichier(s) importé(s), $ko ignoré(s).");
    exit($ko && !$ok ? 1 : 0);
}

if ($cmd === 'etat') {
    $s = IS::receiverStatus();
    $cfg = IS::settings();
    echo 'Récepteur : ' . ($s['running'] ? 'actif (pid ' . ($s['pid'] ?? '?') . ', ' . ($s['received'] ?? 0) . ' image(s) reçue(s) depuis ' . ($s['started'] ?? '?') . ')' : 'arrêté') . "\n";
    echo 'Réglages des appareils : titre AE ' . $cfg['aet'] . ', port ' . $cfg['port'] . ', adresse IP de ce PC : ' . implode(' / ', App\Services\NetworkService::localIps() ?: ['?']) . "\n";
    foreach (DB::all('SELECT id, status, modality, images, COALESCE(dicom_patient_name, \'\') AS n, created_at FROM clinic_imaging_studies ORDER BY id DESC LIMIT 10') as $r) {
        echo "  n° {$r['id']} {$r['created_at']} {$r['modality']} {$r['images']} image(s) {$r['status']} {$r['n']}\n";
    }
    exit(0);
}

if ($cmd !== 'serveur') {
    fwrite(STDERR, "Commande inconnue. Voir l'en-tête de bin/imagerie.php.\n");
    exit(1);
}

$cfg = IS::settings();
$listen = @stream_socket_server('tcp://0.0.0.0:' . $cfg['port'], $errno, $errstr);
if (!$listen) {
    fwrite(STDERR, "Port {$cfg['port']} indisponible : $errstr (un autre récepteur tourne déjà ?)\n");
    exit(1);
}
$state = ['pid' => getmypid(), 'port' => $cfg['port'], 'aet' => $cfg['aet'], 'started' => date('Y-m-d H:i:s'), 'received' => 0, 'last' => null, 'last_error' => null];
$beat = function () use (&$state) {
    $state['beat'] = date('Y-m-d H:i:s');
    @file_put_contents(IS::root() . '/recepteur.json', json_encode($state, JSON_UNESCAPED_UNICODE));
};
$tmp = IS::root('reception-en-cours');
$server = new DicomServer($cfg['aet'],
    function (string $file, array $ctx) use (&$state) {
        try {
            $dev = withDb(fn() => IS::deviceFor($ctx['ae'], $ctx['ip'], true));
            $r = withDb(fn() => IS::ingest($file, ['source' => 'reseau', 'device_id' => $dev['id'] ?? null]));
            $state['received']++;
            $state['last'] = date('Y-m-d H:i:s');
            out('Image reçue de ' . $ctx['ae'] . ' (' . $ctx['ip'] . ')' . ($r['duplicate'] ? ' : déjà présente' : ' → examen n° ' . $r['study_id'] . ($r['matched'] ? '' : ' (à rattacher)')));
        } finally {
            @unlink($file);
        }
    },
    fn(string $m) => out($m),
    function (string $ae, string $ip) {
        return withDb(fn() => IS::deviceFor($ae, $ip, false)) !== null;
    },
    $tmp
);
out("Récepteur d'images DICOM : titre AE {$cfg['aet']}, port {$cfg['port']} ; dossier d'arrivée : " . IS::root('arrivee'));
out('À régler dans chaque appareil (destination « PACS / DICOM Send ») : AE ' . $cfg['aet'] . ', port ' . $cfg['port'] . ', IP ' . implode(' ou ', App\Services\NetworkService::localIps() ?: ['de ce PC']));
$beat();
$lastScan = 0;
while (true) {
    $r = [$listen];
    $w = $e = null;
    if (@stream_select($r, $w, $e, 5) > 0) {
        $conn = @stream_socket_accept($listen, 5, $peer);
        if ($conn) {
            $server->handle($conn, (string) $peer);
        }
    }
    if (time() - $lastScan >= 5) {
        $lastScan = time();
        try {
            $n = withDb(fn() => IS::scanFolder(fn($m) => out($m)));
            $state['received'] += $n;
        } catch (Throwable $ex) {
            $state['last_error'] = $ex->getMessage();
            out('Dossier d\'arrivée : ' . $ex->getMessage());
        }
        $beat();
    }
}
