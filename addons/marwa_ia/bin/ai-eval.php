<?php
/**
 * MARWA AI — banc de tests en ligne de commande.
 *
 *   php addons/marwa_ia/bin/ai-eval.php [--domaine=Stock] [--limite=200] [--echecs=40] [--json=rapport.json] [--md=rapport.md]
 *
 * Rejoue les scénarios de la bibliothèque sur la base configurée (.env) et affiche les taux réels. Rien n'est modifié
 * dans les données de gestion ; seul le résultat de l'évaluation est enregistré (table ai_eval_runs).
 * Les rôles sont joués par les comptes « admin », « gerant », « caissier » et « magasin » : sur une base qui ne les a
 * pas, les scénarios correspondants sont comptés comme non joués.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 3) . '/app/bootstrap.php';
ini_set('session.use_cookies', '0');
ini_set('session.use_strict_mode', '0');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_id('marwa-ai-eval');
    @session_start();
}
App\Core\Addons::registerAutoload();

use Addons\MarwaIa\Brain\Dataset;
use Addons\MarwaIa\Brain\Evaluation;

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? '1';
    }
}
$r = Evaluation::run($opt['domaine'] ?? null, isset($opt['limite']) ? (int) $opt['limite'] : null, !isset($opt['sans-enregistrer']), $opt['libelle'] ?? 'ligne de commande');
$s = $r['summary'];
$pc = fn($v) => $v === null ? '—' : number_format((float) $v, 1, ',', ' ') . ' %';
echo "MARWA AI — évaluation (" . ($opt['domaine'] ?? 'tous les domaines') . ")\n";
echo str_repeat('=', 64) . "\n";
printf("Scénarios joués            : %d (%d entièrement réussis)%s\n", $s['scenarios'], $s['scenarios_ok'], $s['skipped'] ? " — {$s['skipped']} non joués" : '');
printf("Formulations testées       : %d\n", $s['phrases']);
printf("Réussite globale           : %s (%d / %d)\n", $pc($s['pass_rate']), $s['passed'], $s['phrases']);
printf("  formulations vues        : %s (%d)\n", $pc($s['seen_rate']), $s['seen_phrases']);
printf("  formulations JAMAIS vues : %s (%d)\n", $pc($s['held_rate']), $s['held_phrases']);
printf("Intention reconnue         : %s\n", $pc($s['intent_rate']));
printf("Paramètres bien extraits   : %s (%d formulations avec paramètres)\n", $pc($s['slot_rate']), $s['slot_phrases']);
printf("Issue correcte             : %s\n", $pc($s['outcome_rate']));
printf("Clarification appropriée   : %s (%d)\n", $pc($s['clarify_rate']), $s['clarify_phrases']);
printf("Refus approprié            : %s (%d)\n", $pc($s['refuse_rate']), $s['refuse_phrases']);
printf("Taux d'erreur              : %s\n", $pc($s['error_rate']));
printf("Non comprises              : %d · erreurs techniques : %d\n", $s['not_understood'], $s['technical_errors']);
printf("Temps de réponse           : %s ms en moyenne, %s ms au maximum\n", $s['avg_ms'], $s['max_ms']);
printf("Bibliothèque               : %d exemples, %d intentions, %d mots · durée %s s\n", $s['library']['examples'], $s['library']['intents'], $s['library']['vocabulary'], $s['duration_s']);
echo "\nPar domaine\n";
foreach ($r['domains'] as $d => $v) {
    printf("  %-46s %4d scén. %5d form. %s  (jamais vues : %s)\n", mb_substr($d, 0, 46), $v['scenarios'], $v['phrases'], $pc($v['phrases'] ? $v['pass'] / $v['phrases'] * 100 : null), $pc($v['held_n'] ? $v['held_ok'] / $v['held_n'] * 100 : null));
}
$n = (int) ($opt['echecs'] ?? 25);
if ($n > 0 && $r['failures']) {
    echo "\nÉchecs (" . min($n, count($r['failures'])) . ' sur ' . count($r['failures']) . ")\n";
    foreach (array_slice($r['failures'], 0, $n) as $f) {
        printf("  %s [%s/%s] « %s » → %s : %s\n", $f['code'], $f['role'], $f['kind'] === 'held' ? 'test' : 'vue', $f['q'], $f['got'] ?? 'aucune', implode(' ; ', $f['why']));
    }
}
if (isset($opt['json'])) {
    file_put_contents($opt['json'], json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
if (isset($opt['export'])) {
    file_put_contents($opt['export'], json_encode(array_map([Dataset::class, 'expand'], Dataset::all()), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "\nBibliothèque exportée au format complet : {$opt['export']}\n";
}
exit($s['technical_errors'] > 0 ? 2 : 0);
