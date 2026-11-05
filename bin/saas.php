<?php
/**
 * POS Marwa — outil de l'exploitant du service en ligne (mode SaaS).
 *
 *   php bin/saas.php key                 génère une clé SAAS_KEY à copier dans .env
 *   php bin/saas.php init                crée / met à jour les tables de la base centrale
 *   php bin/saas.php admin [identifiant] crée un compte de la console (mot de passe demandé)
 *   php bin/saas.php tenants             liste des espaces clients et de leur état
 *   php bin/saas.php migrate             met à jour la base de CHAQUE espace (après une mise à jour des fichiers)
 *   php bin/saas.php backup              sauvegarde de chaque espace (dans storage/tenants/<espace>/backups)
 *   php bin/saas.php remind [jours]      échéances proches (à appeler ou relancer ; aucun SMS n'est envoyé)
 *   php bin/saas.php pool-add <base> <utilisateur>   ajoute une base vide à la réserve (mot de passe demandé)
 *   POSWAN_TENANT=<espace> php bin/saas.php provision   termine la création d'un espace
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$cmd = $argv[1] ?? '';
if ($cmd === 'key') {
    echo 'SAAS_KEY=' . base64_encode(random_bytes(32)) . PHP_EOL;
    echo "Copiez cette ligne dans .env. Conservez-la : sans elle, les mots de passe des bases clients sont illisibles.\n";
    exit(0);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Saas\Central;
use App\Saas\Saas;
use App\Saas\Subscription;

if (!config('saas.enabled')) {
    fwrite(STDERR, "Le mode SaaS n'est pas activé (SAAS_MODE=true dans .env). Voir SAAS.md.\n");
    exit(1);
}

function ask(string $label, bool $hidden = false): string
{
    echo $label;
    if ($hidden && DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        @shell_exec('stty -echo 2>/dev/null');
        $v = (string) fgets(STDIN);
        @shell_exec('stty echo 2>/dev/null');
        echo PHP_EOL;
    } else {
        $v = (string) fgets(STDIN);
    }
    return trim($v);
}

/** Lance une commande dans le contexte d'un espace (processus séparé : constantes propres). */
function inTenant(string $slug, string $script): int
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/bin/' . $script);
    $p = proc_open($cmd, [1 => STDOUT, 2 => STDERR], $pipes, BASE_PATH, array_merge(getenv(), ['POSWAN_TENANT' => $slug]));
    return is_resource($p) ? proc_close($p) : 1;
}

$readyTenants = fn() => Central::all("SELECT * FROM saas_tenants WHERE state = 'ready' ORDER BY id");

switch ($cmd) {
    case 'init':
        $applied = Central::migrate();
        echo $applied ? 'Base centrale : ' . implode(', ', $applied) . " appliqué(s).\n" : "Base centrale déjà à jour.\n";
        if (!(int) Central::value('SELECT COUNT(*) FROM saas_admins')) {
            echo "Aucun compte de console : créez-le avec « php bin/saas.php admin ».\n";
        }
        if ((string) config('saas.key') === '') {
            echo "ATTENTION : SAAS_KEY est vide dans .env (clé dérivée de APP_KEY utilisée). Générez-en une : php bin/saas.php key\n";
        }
        break;

    case 'admin':
        Central::migrate();
        $u = $argv[2] ?? ask('Identifiant : ');
        $name = ask('Nom complet : ');
        $p1 = ask('Mot de passe : ', true);
        $p2 = ask('Confirmation : ', true);
        if ($p1 !== $p2) {
            fwrite(STDERR, "Les mots de passe ne correspondent pas.\n");
            exit(1);
        }
        try {
            App\Saas\ConsoleAuth::create($u, $name, $p1);
        } catch (App\Core\BusinessException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            exit(1);
        }
        echo "Compte « $u » créé. Console : " . 'https://' . config('saas.domain') . "/console\n";
        break;

    case 'tenants':
        foreach (Central::all('SELECT t.*, p.name plan FROM saas_tenants t JOIN saas_plans p ON p.id = t.plan_id ORDER BY t.id') as $t) {
            $s = $t['state'] === 'ready' ? Subscription::state($t) : ['state' => $t['state'], 'until' => '', 'days_left' => 0];
            printf("%-4d %-28s %-30s %-11s %-12s %-10s %s\n", $t['id'], $t['slug'], mb_strimwidth($t['company'], 0, 30), $t['plan'], $s['state'], (string) $s['until'], $t['phone']);
        }
        break;

    case 'migrate':
    case 'backup':
        $fail = 0;
        foreach ($readyTenants() as $t) {
            echo "== {$t['slug']}\n";
            $fail += inTenant($t['slug'], $cmd === 'migrate' ? 'migrate.php' : 'backup.php') === 0 ? 0 : 1;
        }
        echo $fail ? "$fail espace(s) en échec : voir les messages ci-dessus.\n" : "Terminé sans erreur.\n";
        exit($fail ? 1 : 0);

    case 'remind':
        $days = max(1, (int) ($argv[2] ?? 7));
        foreach ($readyTenants() as $t) {
            $s = Subscription::state($t);
            if (in_array($s['state'], ['trial', 'active'], true) && $s['days_left'] <= $days || $s['state'] === 'expired') {
                printf("%-12s %3d j  %-30s %-20s %s\n", $s['state'], $s['days_left'], mb_strimwidth($t['company'], 0, 30), $t['phone'], (string) $s['until']);
            }
        }
        break;

    case 'pool-add':
        [$db, $user] = [$argv[2] ?? '', $argv[3] ?? ''];
        if ($db === '' || $user === '') {
            fwrite(STDERR, "Usage : php bin/saas.php pool-add <base> <utilisateur>\n");
            exit(1);
        }
        $pass = ask('Mot de passe MySQL de ' . $user . ' : ', true);
        $c = config('saas.central');
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $db), $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ((int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn() > 0) {
            fwrite(STDERR, "La base n'est pas vide : refusée.\n");
            exit(1);
        }
        Central::insert('saas_db_pool', ['db_name' => $db, 'db_user' => $user, 'db_pass_enc' => Saas::encrypt($pass)]);
        Central::audit('pool.added', null, ['db' => $db], 0);
        echo "Base ajoutée à la réserve.\n";
        if ($w = Central::value("SELECT id FROM saas_tenants WHERE state = 'waiting_db' ORDER BY id LIMIT 1")) {
            App\Saas\Provisioner::allocate((int) $w);
            echo "Attribuée à l'espace en attente n° $w.\n";
        }
        break;

    case 'provision':
        if (!Saas::isTenant()) {
            fwrite(STDERR, "Indiquez l'espace : POSWAN_TENANT=<espace> php bin/saas.php provision\n");
            exit(1);
        }
        App\Saas\Provisioner::finish();
        echo App\Services\InstallService::isInstalled() ? "Espace prêt.\n" : "L'espace n'est pas prêt (état : " . Saas::tenant()['state'] . ").\n";
        break;

    default:
        echo preg_replace('/^.*?\/\*\*|\*\/.*$/s', '', (string) file_get_contents(__FILE__, false, null, 0, 1400)) . PHP_EOL;
        exit($cmd === '' ? 0 : 1);
}
