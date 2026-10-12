<?php
declare(strict_types=1);
/**
 * Test de charge de VERIFIA (base dédiée, EFFACÉE au démarrage).
 *   php tests/load/loadtest.php --config=chemin/config_load.php [--posts=1000] [--sessions=4] [--workers=3] [--images=0.2]
 *
 * Mesure :
 *  1. ingestion concurrente : <sessions> processus transmettent les MÊMES <posts> publications en parallèle
 *     (situation de plusieurs sessions surveillant le même site) → vérifie la déduplication sous concurrence ;
 *  2. analyse : <workers> moteurs d'analyse traitent la file en parallèle → débit, absence de double traitement.
 * La configuration doit désigner une base dont le nom contient « load » ou « test ».
 */
$opt = getopt('', ['config:', 'posts::', 'sessions::', 'workers::', 'images::', 'child::', 'session-id::']);
$root = dirname(__DIR__, 2);
putenv('VERIFIA_CONFIG=' . $opt['config']);
require $root . '/src/bootstrap.php';

use Verifia\Database;
use Verifia\Ingest\PublicationIngestor;

$posts = (int) ($opt['posts'] ?? 1000);
$imageRatio = (float) ($opt['images'] ?? 0.2);

/** Génération déterministe des publications (texte varié, images pour une partie). */
function make_post(int $i, float $imageRatio): array
{
    mt_srand($i);
    $themes = ['Le ministre de la santé a annoncé', 'URGENT !!! Ce qu\'on vous cache :', 'Selon la mairie,', 'À mon avis,', 'Il paraît que le directeur', 'Le championnat reprendra'];
    $places = ['Niamey', 'Zinder', 'Maradi', 'Agadez', 'Tahoua', 'Dosso', 'Diffa', 'Tillabéri'];
    $text = $themes[$i % count($themes)] . ' que ' . mt_rand(2, 900) . ' personnes ont été concernées à ' . $places[$i % 8] . " ce matin (réf. $i). "
        . ($i % 7 === 0 ? 'Partagez avant que ce soit supprimé ! ' : '') . str_repeat('Information complémentaire sur la situation. ', mt_rand(1, 6));
    $p = ['key' => "load-$i", 'permalink' => "https://load.example/posts/$i", 'text' => $text, 'author' => 'Page ' . ($i % 50)];
    if (mt_rand(0, 1000) / 1000 < $imageRatio) {
        $img = imagecreatetruecolor(320, 220);
        mt_srand(intdiv($i, 3)); // une image sur trois est réutilisée par trois publications
        for ($k = 0; $k < 20; $k++) {
            imagefilledrectangle($img, mt_rand(0, 320), mt_rand(0, 220), mt_rand(0, 320), mt_rand(0, 220), imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
        ob_start();
        imagejpeg($img, null, 80);
        $p['images'] = [['data_base64' => base64_encode((string) ob_get_clean())]];
    }
    return $p;
}

if (isset($opt['child'])) {
    // Processus enfant : une « session » qui transmet toutes les publications (ordre propre à la session)
    $sid = (int) $opt['session-id'];
    $order = range(0, $posts - 1);
    mt_srand($sid * 7919);
    shuffle($order);
    $stats = ['created' => 0, 'duplicate' => 0, 'rejected' => 0, 'errors' => 0];
    foreach ($order as $i) {
        try {
            $r = PublicationIngestor::ingest(make_post($i, $imageRatio), $sid, 'https://load.example/feed');
            $stats[$r['status']]++;
        } catch (\Throwable $e) {
            $stats['errors']++;
            fwrite(STDERR, $e->getMessage() . "\n");
        }
    }
    echo json_encode($stats);
    exit;
}

$sessions = (int) ($opt['sessions'] ?? 4);
$workers = (int) ($opt['workers'] ?? 3);
$dsn = (string) Verifia\Config::get('db.dsn');
preg_match('/dbname=([A-Za-z0-9_]+)/', $dsn, $m);
if (!preg_match('/load|test/', $m[1])) {
    exit("Refus : la base « {$m[1]} » ne semble pas être une base de test.\n");
}
$server = new PDO(preg_replace('/dbname=[^;]+;?/', '', $dsn), (string) Verifia\Config::get('db.user'), (string) Verifia\Config::get('db.password'));
$server->exec("DROP DATABASE IF EXISTS `{$m[1]}`");
passthru(PHP_BINARY . ' ' . escapeshellarg($root . '/bin/install.php') . ' --config=' . escapeshellarg($opt['config']) . ' > ' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
Database::reset();
for ($s = 1; $s <= $sessions; $s++) {
    Verifia\Sessions\SessionService::create(['start_url' => 'https://load.example/feed', 'name' => "Charge $s"], null);
}
printf("== Test de charge : %d publications × %d sessions concurrentes, %d workers, %.0f %% avec image ==\n", $posts, $sessions, $workers, $imageRatio * 100);

function spawn_all(array $cmds): array
{
    $procs = [];
    foreach ($cmds as $k => $cmd) {
        $procs[$k] = [proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
    }
    $out = [];
    foreach ($procs as $k => [$p, $pipes]) {
        $out[$k] = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        if (trim($err) !== '') {
            fwrite(STDERR, "[$k] " . substr($err, 0, 2000) . "\n");
        }
        proc_close($p);
    }
    return $out;
}

$php = escapeshellarg(PHP_BINARY);
$self = escapeshellarg(__FILE__);
$cfg = escapeshellarg($opt['config']);
$t0 = microtime(true);
$cmds = [];
for ($s = 1; $s <= $sessions; $s++) {
    $cmds[$s] = "$php $self --config=$cfg --posts=$posts --images=$imageRatio --child=1 --session-id=$s";
}
$res = spawn_all($cmds);
$tIngest = microtime(true) - $t0;
$tot = ['created' => 0, 'duplicate' => 0, 'rejected' => 0, 'errors' => 0];
foreach ($res as $r) {
    foreach ((array) json_decode($r, true) as $k => $v) {
        $tot[$k] += $v;
    }
}
$pubs = (int) Database::value('SELECT COUNT(*) FROM detected_publications');
$occ = (int) Database::value('SELECT COUNT(*) FROM publication_occurrences');
$jobs = (int) Database::value('SELECT COUNT(*) FROM analysis_jobs');
printf("Ingestion : %d requêtes en %.1f s (%.0f/s) — créées %d, doublons %d, erreurs %d\n", $posts * $sessions, $tIngest, $posts * $sessions / $tIngest, $tot['created'], $tot['duplicate'], $tot['errors']);
printf("  publications uniques : %d (attendu %d) · occurrences : %d (attendu %d) · tâches : %d\n", $pubs, $posts, $occ, $posts * $sessions, $jobs);

$t0 = microtime(true);
$w = [];
for ($i = 0; $i < $workers; $i++) {
    $w[$i] = "$php " . escapeshellarg($root . '/bin/worker.php') . ' --once';
}
putenv('VERIFIA_CONFIG=' . $opt['config']);
spawn_all($w);
$tAnalyze = microtime(true) - $t0;
$q = Verifia\Queue\JobQueue::stats();
$dupResults = (int) Database::value('SELECT COUNT(*) FROM (SELECT publication_id FROM analysis_results GROUP BY publication_id HAVING COUNT(*) > 1) x');
printf("Analyse : %d tâches en %.1f s (%.1f/s) — terminées %d, échouées %d, en attente %d, analyses en double : %d\n", $jobs, $tAnalyze, $jobs / max(0.001, $tAnalyze), $q['done'], $q['failed'], $q['pending'], $dupResults);
printf("  alertes : %d · correspondances d'images : %d\n", Database::value('SELECT COUNT(*) FROM alerts'), Database::value('SELECT COUNT(*) FROM image_matches'));

$ok = $pubs === $posts && $occ === $posts * $sessions && $jobs === $posts && $tot['errors'] === 0 && $q['done'] === $jobs && $dupResults === 0;
echo $ok ? "RÉSULTAT : OK\n" : "RÉSULTAT : ÉCHEC\n";
exit($ok ? 0 : 1);
