<?php
/**
 * POS Marwa — tests du mode SaaS (multi-clients) : isolement, inscription, création des espaces,
 * limites des offres, paiements, lecture seule, suspension, réserve de bases, console.
 *
 * Usage : TEST_DB_USER=xxx TEST_DB_PASS=yyy php tests/saas.php
 * Le compte MySQL de test doit pouvoir créer des bases et des utilisateurs (CREATE, CREATE USER,
 * GRANT OPTION). Bases recréées : poswan_saas_test (centrale) et pmtest_* (espaces).
 * N'utilisez JAMAIS un serveur MySQL de production.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$dbHost = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('TEST_DB_USER') ?: 'root';
$dbPass = getenv('TEST_DB_PASS') ?: '';
$central = 'poswan_saas_test';
$prefix = 'pmtest_';
$port = (int) (getenv('TEST_SAAS_PORT') ?: 8121);
$domain = 'posmarwa.test';
$env = [
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => bin2hex(random_bytes(32)),
    'SAAS_MODE' => 'true', 'SAAS_DOMAIN' => $domain, 'SAAS_DB_HOST' => $dbHost, 'SAAS_DB_DATABASE' => $central,
    'SAAS_DB_USERNAME' => $dbUser, 'SAAS_DB_PASSWORD' => $dbPass, 'SAAS_PROVISION' => 'create', 'SAAS_DB_PREFIX' => $prefix,
    'SAAS_KEY' => base64_encode(random_bytes(32)), 'SAAS_TRIAL_DAYS' => '14', 'DB_HOST' => $dbHost, 'DB_USERNAME' => $dbUser, 'DB_PASSWORD' => $dbPass,
];
foreach ($env as $k => $v) {
    putenv("$k=$v");
}
$root = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$cleanup = function () use ($root, $central, $prefix) {
    foreach ($root->query("SHOW DATABASES LIKE '" . $prefix . "%'")->fetchAll(PDO::FETCH_COLUMN) as $db) {
        $root->exec("DROP DATABASE `$db`");
    }
    foreach ($root->query("SELECT User, Host FROM mysql.user WHERE User LIKE '" . $prefix . "%'")->fetchAll() as $u) {
        $root->exec('DROP USER ' . $root->quote($u['User']) . '@' . $root->quote($u['Host']));
    }
    $root->exec("DROP DATABASE IF EXISTS `$central`");
    foreach (glob(dirname(__DIR__) . '/storage/tenants/test-*', GLOB_ONLYDIR) ?: [] as $d) {
        exec('rm -rf ' . escapeshellarg($d));
    }
    foreach (glob(dirname(__DIR__) . '/public/uploads/t/test-*', GLOB_ONLYDIR) ?: [] as $d) {
        exec('rm -rf ' . escapeshellarg($d));
    }
};
$cleanup();
$root->exec("CREATE DATABASE `$central` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

require dirname(__DIR__) . '/app/bootstrap.php';
restore_error_handler();
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

use App\Core\Config;
use App\Saas\Billing;
use App\Saas\Central;
use App\Saas\Provisioner;
use App\Saas\Saas;
use App\Saas\Subscription;

$results = ['pass' => 0, 'fail' => 0, 'failures' => []];
function test(string $name, callable $fn): void
{
    global $results;
    try {
        $fn();
        $results['pass']++;
        echo "  \033[32m✔\033[0m $name\n";
    } catch (Throwable $e) {
        $results['fail']++;
        $results['failures'][] = "$name : " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        echo "  \033[31m✘ $name\033[0m\n      → " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}
function ok(bool $c, string $m = 'assertion échouée'): void
{
    if (!$c) {
        throw new RuntimeException($m);
    }
}
function eq(mixed $a, mixed $b, string $m = ''): void
{
    if ($a !== $b) {
        throw new RuntimeException(($m ? "$m : " : '') . 'attendu ' . var_export($a, true) . ', obtenu ' . var_export($b, true));
    }
}

/** Client HTTP : les noms d'hôte sont résolus vers le serveur local (cookies liés à chaque hôte, comme un navigateur). */
final class Web
{
    public string $jar;
    public function __construct(private int $port, private array $hosts)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'pmjar');
    }
    public function req(string $method, string $url, ?array $data = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 60, CURLOPT_RESOLVE => array_map(fn($h) => "$h:{$this->port}:127.0.0.1", $this->hosts)]);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
        return ['code' => $code, 'body' => substr($raw, $hs), 'location' => $m[1] ?? '', 'type' => $type];
    }
    public function token(string $url): string
    {
        preg_match('/name="_csrf" value="([^"]+)"/', $this->req('GET', $url)['body'], $m);
        return $m[1] ?? '';
    }
    public function post(string $formUrl, string $url, array $data): array
    {
        return $this->req('POST', $url, ['_csrf' => $this->token($formUrl)] + $data);
    }
    public function flash(string $url): string
    {
        preg_match_all('/class="alert alert-(?:danger|success|warning|info)[^"]*"[^>]*>(.*?)<(?:button|\/div)/s', $this->req('GET', $url)['body'], $m);
        return html_entity_decode(strip_tags(implode(' | ', $m[1])), ENT_QUOTES);
    }
}

Central::migrate();
$C = "http://$domain:$port";
$A = "http://test-alpha.$domain:$port";
$Bt = "http://test-beta.$domain:$port";
$hosts = [$domain, "test-alpha.$domain", "test-beta.$domain", "test-gamma.$domain", "inconnu.$domain", 'ailleurs.example'];
$cmd = sprintf('exec php -S 127.0.0.1:%d -t %s %s', $port, escapeshellarg(PUBLIC_PATH), escapeshellarg(__DIR__ . '/server-router.php'));
$proc = proc_open($cmd, [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, BASE_PATH, array_merge(getenv(), $env, ['PHP_CLI_SERVER_WORKERS' => '4']));
usleep(800000);
$plan = fn(string $code) => (int) Central::value('SELECT id FROM saas_plans WHERE code = ?', [$code]);
$tenant = fn(string $slug) => Central::one('SELECT * FROM saas_tenants WHERE slug = ?', [$slug]);
$signup = fn(Web $w, array $d) => $w->post("$C/inscription", "$C/inscription", $d + [
    'company' => 'Commerce test', 'business_type' => 'alimentation', 'city' => 'Niamey', 'contact_name' => 'Gérant Test', 'phone' => '+227 90 11 22 33',
    'admin_username' => 'gerant', 'admin_password' => 'Sahel-Test-2026', 'admin_password_confirm' => 'Sahel-Test-2026', 'accept' => '1',
]);
$PWD = 'Sahel-Test-2026';

echo "\n== Mode SaaS : règles ==\n";
test('Sous-domaine → espace ; domaine principal → site ; hôte étranger refusé', function () use ($domain) {
    eq('', Saas::slugFromHost($domain));
    eq('', Saas::slugFromHost('www.' . $domain . ':8080'));
    eq('boutique-awa', Saas::slugFromHost('Boutique-Awa.' . $domain));
    eq(null, Saas::slugFromHost('a.b.' . $domain), 'deux niveaux');
    eq(null, Saas::slugFromHost('ailleurs.example'));
    eq(null, Saas::slugFromHost('-x.' . $domain));
    ok(Saas::reserved('admin') && Saas::reserved('www') && !Saas::reserved('awa'));
});
test('Chiffrement des mots de passe de bases : aller-retour, falsification détectée', function () {
    $e = Saas::encrypt('S3cret!');
    ok($e !== 'S3cret!' && str_starts_with($e, 'S1.'));
    eq('S3cret!', Saas::decrypt($e));
    $raw = base64_decode(substr($e, 3));
    $raw[30] = chr(ord($raw[30]) ^ 1);
    try {
        Saas::decrypt('S1.' . base64_encode($raw));
        ok(false, 'falsification non détectée');
    } catch (RuntimeException) {
    }
});
test('État de l\'abonnement : essai, actif, expiré, suspendu', function () {
    $d = '2026-03-10';
    eq('trial', Subscription::state(['trial_ends' => '2026-03-10', 'paid_until' => null, 'suspended_at' => null], $d)['state']);
    eq(1, Subscription::state(['trial_ends' => '2026-03-10', 'paid_until' => null, 'suspended_at' => null], $d)['days_left'], 'dernier jour compté');
    eq('active', Subscription::state(['trial_ends' => '2026-01-01', 'paid_until' => '2026-04-09', 'suspended_at' => null], $d)['state']);
    eq('expired', Subscription::state(['trial_ends' => '2026-03-09', 'paid_until' => null, 'suspended_at' => null], $d)['state']);
    eq('suspended', Subscription::state(['trial_ends' => '2026-04-01', 'paid_until' => '2026-05-01', 'suspended_at' => '2026-03-01 10:00:00'], $d)['state']);
});
test('Périodes payées : fin de mois respectée', function () {
    eq('2026-02-14', Billing::addMonths('2026-01-15', 1));
    eq('2026-02-28', Billing::addMonths('2026-01-31', 1));
    eq('2026-12-31', Billing::addMonths('2026-01-01', 12));
    eq('2027-01-04', Billing::addMonths('2026-10-05', 3));
});

echo "\n== Site public et inscription ==\n";
$w = new Web($port, $hosts);
test('Site public : offres affichées ; adresse inconnue ou étrangère → 404', function () use ($w, $C, $domain, $port) {
    $r = $w->req('GET', "$C/");
    eq(200, $r['code']);
    ok(str_contains($r['body'], 'Essentiel') && str_contains($r['body'], 'Entreprise'), 'offres');
    eq(404, $w->req('GET', "http://inconnu.$domain:$port/login")['code']);
    eq(404, $w->req('GET', "http://ailleurs.example:$port/")['code']);
});
test('Inscription refusée : adresse réservée, mot de passe faible, conditions non acceptées', function () use ($w, $C, $signup, $plan) {
    $signup($w, ['slug' => 'admin', 'plan_id' => $plan('essentiel')]);
    ok(str_contains($w->flash("$C/inscription"), 'Adresse de l\'espace invalide'), 'adresse réservée');
    $signup($w, ['slug' => 'test-x', 'plan_id' => $plan('essentiel'), 'admin_password' => 'court', 'admin_password_confirm' => 'court']);
    ok(str_contains($w->flash("$C/inscription"), 'au moins 10'), 'mot de passe');
    $signup($w, ['slug' => 'test-x', 'plan_id' => $plan('essentiel'), 'accept' => '']);
    ok(str_contains($w->flash("$C/inscription"), 'conditions'), 'conditions');
    eq(0, (int) Central::value('SELECT COUNT(*) FROM saas_tenants'));
});
test('Champ piège rempli (robot) : aucune inscription', function () use ($w, $signup, $plan) {
    $signup($w, ['slug' => 'test-robot', 'plan_id' => $plan('essentiel'), 'website' => 'http://spam']);
    eq(0, (int) Central::value('SELECT COUNT(*) FROM saas_tenants'));
});
test('Inscription + première visite : base, utilisateur MySQL dédié, POS Marwa installé', function () use ($w, $C, $A, $signup, $plan, $tenant, $root, $PWD) {
    $r = $signup($w, ['slug' => 'test-alpha', 'company' => 'Alimentation Alpha', 'plan_id' => $plan('essentiel')]);
    eq(200, $r['code']);
    ok(str_contains($r['body'], 'http-equiv="refresh" content="2;url=' . $A . '/"') && str_contains($r['body'], 'href="' . $A . '/"'), 'page de bienvenue qui mène à l\'espace');
    $t = $tenant('test-alpha');
    eq('provisioning', $t['state']);
    ok($t['db_user'] === $t['db_name'] && str_starts_with($t['db_pass_enc'], 'S1.'), 'identifiants dédiés chiffrés');
    $r = $w->req('GET', "$A/");
    eq(302, $r['code']);
    ok(str_ends_with($r['location'], '/login'));
    $t = $tenant('test-alpha');
    eq('ready', $t['state']);
    eq(null, $t['pending_enc'], 'compte provisoire effacé');
    eq(date('Y-m-d', strtotime('+13 days')), $t['trial_ends']);
    $grants = implode("\n", $root->query("SHOW GRANTS FOR '{$t['db_user']}'@'localhost'")->fetchAll(PDO::FETCH_COLUMN));
    ok(str_contains($grants, "ON `{$t['db_name']}`.*") && !preg_match('/ALL PRIVILEGES ON \*\.\*/', $grants), 'droits limités à sa base');
    $hash = $root->query("SELECT password_hash FROM `{$t['db_name']}`.users WHERE username = 'gerant'")->fetchColumn();
    ok(password_verify($PWD, (string) $hash), 'mot de passe choisi à l\'inscription');
    ok(is_dir(BASE_PATH . '/storage/tenants/test-alpha/sessions') && is_dir(PUBLIC_PATH . '/uploads/t/test-alpha'), 'dossiers propres');
});
test('Deuxième client ; adresse déjà prise refusée', function () use ($w, $C, $Bt, $signup, $plan, $tenant) {
    $signup($w, ['slug' => 'test-beta', 'company' => 'Boutique Beta', 'plan_id' => $plan('pro'), 'admin_username' => 'patron']);
    eq(302, $w->req('GET', "$Bt/")['code']);
    eq('ready', $tenant('test-beta')['state']);
    Central::run('DELETE FROM saas_throttle'); // limite par IP testée à part
    $signup($w, ['slug' => 'test-beta', 'company' => 'Autre', 'plan_id' => $plan('pro')]);
    ok(str_contains($w->flash("$C/inscription"), 'déjà prise'));
});
test('Limitation : 5 inscriptions par heure et par adresse IP', function () use ($w, $C, $signup, $plan) {
    Central::run('DELETE FROM saas_throttle');
    for ($i = 0; $i < 5; $i++) {
        Central::run("INSERT INTO saas_throttle (kind, ip) VALUES ('signup', '127.0.0.1')");
    }
    $signup($w, ['slug' => 'test-zeta', 'plan_id' => $plan('pro')]);
    ok(str_contains($w->flash("$C/inscription"), 'Trop d\'inscriptions'));
    eq(null, Central::value("SELECT id FROM saas_tenants WHERE slug = 'test-zeta'"));
});

echo "\n== Isolement entre clients ==\n";
$wa = new Web($port, $hosts);
$wb = new Web($port, $hosts);
test('Connexion à son espace ; la session d\'un espace n\'ouvre pas l\'autre', function () use ($wa, $A, $Bt, $PWD) {
    $r = $wa->post("$A/login", "$A/login", ['username' => 'gerant', 'password' => $PWD]);
    eq(302, $r['code']);
    eq(200, $wa->req('GET', "$A/dashboard")['code']);
    $r = $wa->req('GET', "$Bt/dashboard");
    eq(302, $r['code'], 'espace beta sans connexion');
    ok(str_contains($r['location'], '/login'));
    // Vol du cookie : l'identifiant de session d'alpha présenté à beta n'y correspond à rien
    preg_match('/test-alpha\S*\s+\S+\s+\S+\s+\S+\s+\S+\s+POSWANSESSID\s+(\S+)/', (string) file_get_contents($wa->jar), $m);
    ok(!empty($m[1]), 'cookie alpha');
    $ch = curl_init("$Bt/dashboard");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'POSWANSESSID=' . $m[1], CURLOPT_RESOLVE => [parse_url($Bt, PHP_URL_HOST) . ':' . parse_url($Bt, PHP_URL_PORT) . ':127.0.0.1']]);
    curl_exec($ch);
    eq(302, (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'cookie volé refusé');
    curl_close($ch);
    eq(false, $wa->post("$Bt/login", "$Bt/login", ['username' => 'gerant', 'password' => $PWD])['location'] === "$Bt/", 'compte alpha inconnu chez beta');
});
test('Données séparées : un client d\'alpha n\'existe pas chez beta', function () use ($wa, $wb, $A, $Bt, $tenant, $root, $PWD) {
    $wa->post("$A/customers/create", "$A/customers", ['name' => 'Client secret Alpha', 'phone' => '90000001', 'type' => 'retail']);
    $a = $tenant('test-alpha')['db_name'];
    $b = $tenant('test-beta')['db_name'];
    eq(1, (int) $root->query("SELECT COUNT(*) FROM `$a`.customers WHERE name = 'Client secret Alpha'")->fetchColumn(), 'créé chez alpha');
    eq(0, (int) $root->query("SELECT COUNT(*) FROM `$b`.customers WHERE name = 'Client secret Alpha'")->fetchColumn(), 'absent chez beta');
    $wb->post("$Bt/login", "$Bt/login", ['username' => 'patron', 'password' => $PWD]);
    ok(!str_contains($wb->req('GET', "$Bt/customers")['body'], 'Client secret Alpha'), 'invisible depuis beta');
});
test('L\'utilisateur MySQL d\'alpha ne peut pas lire la base de beta', function () use ($tenant, $dbHost) {
    $a = $tenant('test-alpha');
    $b = $tenant('test-beta');
    $pdo = new PDO("mysql:host=$dbHost;dbname={$a['db_name']}", $a['db_user'], Saas::decrypt($a['db_pass_enc']), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    try {
        $pdo->query("SELECT COUNT(*) FROM `{$b['db_name']}`.users");
        ok(false, 'lecture croisée possible');
    } catch (PDOException $e) {
        ok(in_array((int) $e->errorInfo[1], [1044, 1142], true), 'accès refusé par MySQL : ' . $e->getMessage());
    }
    try {
        $pdo->query("SELECT COUNT(*) FROM `" . config('saas.central.database') . "`.saas_tenants");
        ok(false, 'lecture de la base centrale possible');
    } catch (PDOException) {
    }
});
test('Pages réservées à l\'exploitant : mise à jour, licence, installation', function () use ($wa, $A) {
    ok(str_ends_with($wa->req('GET', "$A/update")['location'], '/abonnement'));
    ok(str_ends_with($wa->req('GET', "$A/licence")['location'], '/abonnement'));
    eq(302, $wa->req('GET', "$A/install")['code']);
    $r = $wa->req('GET', "$A/dashboard");
    ok(str_contains($r['body'], 'Mon abonnement') && !str_contains($r['body'], 'Licence et activation') && !str_contains($r['body'], 'Mise à jour du logiciel'), 'menu adapté');
});

echo "\n== Offres : limites et modules ==\n";
test('Offre Essentiel : 1 caisse, 3 utilisateurs ; dépassement refusé avec message', function () use ($wa, $A, $tenant, $root) {
    $db = $tenant('test-alpha')['db_name'];
    $wa->post("$A/stores", "$A/registers", ['name' => 'Caisse 2', 'store_id' => 1, 'warehouse_id' => 1]);
    eq(1, (int) $root->query("SELECT COUNT(*) FROM `$db`.cash_registers")->fetchColumn(), 'caisse refusée');
    $role = (int) $root->query("SELECT id FROM `$db`.roles WHERE code = 'cashier'")->fetchColumn();
    for ($i = 1; $i <= 3; $i++) {
        $wa->post("$A/users/create", "$A/users", ['username' => "caissier$i", 'full_name' => "Caissier $i", 'role_id' => $role, 'password' => 'Caisse-Test-2026' . $i]);
    }
    eq(3, (int) $root->query("SELECT COUNT(*) FROM `$db`.users WHERE active = 1")->fetchColumn(), '3 utilisateurs maximum');
    ok(str_contains($wa->flash("$A/users"), 'permet 3 utilisateurs'), 'message de l\'offre');
});
test('Module hors offre : installation refusée ; module exclu du service masqué', function () use ($wa, $A, $tenant, $root) {
    $wa->post("$A/addons", "$A/addons", ['code' => 'hotel', 'do' => 'install']);
    $db = $tenant('test-alpha')['db_name'];
    eq(0, (int) $root->query("SELECT COUNT(*) FROM `$db`.addons WHERE code = 'hotel'")->fetchColumn());
    $page = $wa->req('GET', "$A/addons")['body'];
    ok(str_contains($page, 'Non inclus dans votre offre'), 'bouton remplacé');
    ok(!str_contains($page, 'value="marwa_assist"') && !str_contains($page, 'value="vision"'), 'modules exclus masqués');
});
test('Offre Pro : module MARWA IA installable', function () use ($wb, $Bt, $tenant, $root) {
    $wb->post("$Bt/addons", "$Bt/addons", ['code' => 'marwa_ia', 'do' => 'install']);
    eq(1, (int) $root->query("SELECT COUNT(*) FROM `{$tenant('test-beta')['db_name']}`.addons WHERE code = 'marwa_ia' AND active = 1")->fetchColumn());
});

echo "\n== Paiements, console, factures ==\n";
$wc = new Web($port, $hosts);
$payId = 0;
test('Client : déclaration de paiement (référence obligatoire, pas de doublon)', function () use ($wa, $A, $plan, &$payId) {
    $wa->post("$A/abonnement", "$A/abonnement/declarer", ['plan_id' => $plan('essentiel'), 'months' => 3, 'amount' => 22500, 'method' => 'mobile_money', 'reference' => '', 'paid_on' => date('Y-m-d')]);
    ok(str_contains($wa->flash("$A/abonnement"), 'référence'), 'référence exigée');
    $wa->post("$A/abonnement", "$A/abonnement/declarer", ['plan_id' => $plan('essentiel'), 'months' => 3, 'amount' => 22500, 'method' => 'mobile_money', 'reference' => 'MP261105.1234.A1', 'payer_phone' => '+227 90 11 22 33', 'paid_on' => date('Y-m-d')]);
    $p = Central::one("SELECT * FROM saas_payments WHERE reference = 'MP261105.1234.A1'");
    ok($p && $p['status'] === 'pending' && $p['declared_by'] === 'client', 'en attente');
    $payId = (int) $p['id'];
    $wa->post("$A/abonnement", "$A/abonnement/declarer", ['plan_id' => $plan('essentiel'), 'months' => 1, 'amount' => 7500, 'method' => 'mobile_money', 'reference' => 'MP261105.1234.A1', 'paid_on' => date('Y-m-d')]);
    ok(str_contains($wa->flash("$A/abonnement"), 'déjà été enregistrée'), 'doublon refusé');
});
test('Console : accès sans compte refusé ; aucun compte par défaut ; mauvais mot de passe refusé', function () use ($wc, $C) {
    ok(str_contains($wc->req('GET', "$C/console")['location'], '/console/login'));
    eq(0, (int) Central::value('SELECT COUNT(*) FROM saas_admins'));
    App\Saas\ConsoleAuth::create('exploitant', 'Exploitant Test', 'Console-Test-2026');
    $wc->post("$C/console/login", "$C/console/login", ['username' => 'exploitant', 'password' => 'mauvais']);
    ok(str_contains($wc->req('GET', "$C/console")['location'], '/console/login'));
    $wc->post("$C/console/login", "$C/console/login", ['username' => 'exploitant', 'password' => 'Console-Test-2026']);
    eq(200, $wc->req('GET', "$C/console")['code']);
});
test('Console : validation → abonnement prolongé de 3 mois, facture PDF numérotée', function () use ($wc, $C, $tenant, &$payId) {
    $wc->post("$C/console/paiements", "$C/console/paiements/$payId/valider", []);
    $p = Central::one('SELECT * FROM saas_payments WHERE id = ?', [$payId]);
    eq('validated', $p['status']);
    // Payé pendant l'essai : la période commence au lendemain de la fin de l'essai
    eq(Billing::addMonths(date('Y-m-d', strtotime($tenant('test-alpha')['trial_ends'] . ' +1 day')), 3), $tenant('test-alpha')['paid_until']);
    eq(date('Y-m-d', strtotime($tenant('test-alpha')['trial_ends'] . ' +1 day')), $p['period_from']);
    ok((bool) preg_match('/^AB\d{4}-00001$/', (string) $p['invoice_no']), 'n° ' . $p['invoice_no']);
    $r = $wc->req('GET', "$C/console/factures/$payId");
    ok($r['code'] === 200 && str_starts_with($r['body'], '%PDF') && str_contains($r['type'], 'pdf'), 'PDF');
    eq(7500.0, Billing::mrr(), 'revenu mensuel récurrent');
});
test('Facture : visible par son client, introuvable depuis un autre espace', function () use ($wa, $wb, $A, $Bt, &$payId) {
    $r = $wa->req('GET', "$A/abonnement/facture/$payId");
    ok($r['code'] === 200 && str_starts_with($r['body'], '%PDF'));
    eq(404, $wb->req('GET', "$Bt/abonnement/facture/$payId")['code']);
});
test('Paiement saisi par la console : prolonge à la suite de la période payée', function () use ($wc, $C, $tenant, $plan) {
    $t = $tenant('test-alpha');
    $wc->post("$C/console/clients/{$t['id']}", "$C/console/clients/{$t['id']}/paiement", ['plan_id' => $plan('pro'), 'months' => 1, 'amount' => 15000, 'method' => 'virement', 'reference' => 'VIR-0001', 'paid_on' => date('Y-m-d')]);
    $from = date('Y-m-d', strtotime($t['paid_until'] . ' +1 day'));
    eq(Billing::addMonths($from, 1), $tenant('test-alpha')['paid_until']);
    eq($plan('pro'), (int) $tenant('test-alpha')['plan_id'], 'offre payée appliquée');
});
test('Refus d\'une déclaration : motif visible par le client', function () use ($wb, $wc, $Bt, $C, $plan) {
    $wb->post("$Bt/abonnement", "$Bt/abonnement/declarer", ['plan_id' => $plan('pro'), 'months' => 1, 'amount' => 15000, 'method' => 'mobile_money', 'reference' => 'FAUX-REF-99', 'paid_on' => date('Y-m-d')]);
    $id = (int) Central::value("SELECT id FROM saas_payments WHERE reference = 'FAUX-REF-99'");
    $wc->post("$C/console/paiements", "$C/console/paiements/$id/refuser", ['reason' => 'Référence introuvable sur le relevé']);
    eq('rejected', Central::value('SELECT status FROM saas_payments WHERE id = ?', [$id]));
    ok(str_contains($wb->req('GET', "$Bt/abonnement")['body'], 'Référence introuvable sur le relevé'));
});

echo "\n== Lecture seule, suspension, réserve de bases ==\n";
test('Abonnement expiré : lecture seule (consultation possible, modification refusée)', function () use ($wb, $Bt, $tenant, $root) {
    $t = $tenant('test-beta');
    Central::update('saas_tenants', ['trial_ends' => date('Y-m-d', strtotime('-1 day')), 'paid_until' => null], 'id = ?', [$t['id']]);
    $r = $wb->req('GET', "$Bt/dashboard");
    eq(200, $r['code']);
    ok(str_contains($r['body'], 'Abonnement expiré'), 'bandeau');
    $wb->post("$Bt/customers/create", "$Bt/customers", ['name' => 'Client pendant expiration', 'phone' => '90000002', 'type' => 'retail']);
    eq(0, (int) $root->query("SELECT COUNT(*) FROM `{$t['db_name']}`.customers WHERE name = 'Client pendant expiration'")->fetchColumn());
    eq(200, $wb->req('GET', "$Bt/abonnement")['code'], 'page d\'abonnement accessible');
});
test('Suspension : accès bloqué (données conservées) ; réactivation', function () use ($wb, $wc, $Bt, $C, $tenant) {
    $id = (int) $tenant('test-beta')['id'];
    $wc->post("$C/console/clients/$id", "$C/console/clients/$id/suspendre", ['reason' => 'Impayé depuis 3 mois']);
    $r = $wb->req('GET', "$Bt/login");
    eq(403, $r['code']);
    ok(str_contains($r['body'], 'Impayé depuis 3 mois'));
    $wc->post("$C/console/clients/$id", "$C/console/clients/$id/reactiver", []);
    eq(200, $wb->req('GET', "$Bt/dashboard")['code'], 'réactivé, session conservée');
});
test('Geste commercial : 10 jours ajoutés à l\'essai expiré', function () use ($wc, $C, $tenant) {
    $id = (int) $tenant('test-beta')['id'];
    $wc->post("$C/console/clients/$id", "$C/console/clients/$id/prolonger", ['days' => 10, 'reason' => 'Retard de mise en route']);
    eq('trial', Subscription::state($tenant('test-beta'))['state']);
    eq(date('Y-m-d', strtotime('+9 days')), $tenant('test-beta')['trial_ends']);
});
test('Mode réserve (o2switch) : inscription en attente puis base vide déclarée et attribuée', function () use ($root, $prefix, $dbHost, $plan, $tenant, $port, $domain, $hosts) {
    Config::set('saas.provision', 'pool');
    $t = Provisioner::signup(['slug' => 'test-gamma', 'company' => 'Pressing Gamma', 'business_type' => 'boutique', 'contact_name' => 'Gérant Gamma', 'phone' => '+227 96 00 00 00',
        'admin_username' => 'gamma', 'admin_password' => 'Pressing-Test-2026', 'admin_password_confirm' => 'Pressing-Test-2026', 'plan_id' => $plan('essentiel'), 'accept' => '1']);
    eq('waiting_db', $t['state'], 'aucune base libre');
    eq(503, (new Web($port, $hosts))->req('GET', "http://test-gamma.$domain:$port/")['code'], 'espace en préparation');
    $db = $prefix . 'pool1';
    $pass = 'Pool-' . bin2hex(random_bytes(6));
    $root->exec("CREATE DATABASE `$db`");
    $root->exec("CREATE USER '{$prefix}pool1'@'localhost' IDENTIFIED BY '$pass'");
    $root->exec("GRANT ALL PRIVILEGES ON `$db`.* TO '{$prefix}pool1'@'localhost'");
    Central::insert('saas_db_pool', ['db_name' => $db, 'db_user' => $prefix . 'pool1', 'db_pass_enc' => Saas::encrypt($pass)]);
    ok(Provisioner::allocate((int) $t['id']), 'attribuée');
    eq($db, $tenant('test-gamma')['db_name']);
    $r = (new Web($port, $hosts))->req('GET', "http://test-gamma.$domain:$port/");
    ok(str_ends_with($r['location'], '/login'), 'installé à la première visite');
    eq('ready', $tenant('test-gamma')['state']);
    eq((int) $t['id'], (int) Central::value('SELECT tenant_id FROM saas_db_pool WHERE db_name = ?', [$db]));
    Config::set('saas.provision', 'create');
});
test('Ligne de commande : migration et sauvegarde de chaque espace', function () use ($env) {
    $out = [];
    $envStr = implode(' ', array_map(fn($k, $v) => $k . '=' . escapeshellarg($v), array_keys($env), $env));
    exec("$envStr php " . escapeshellarg(BASE_PATH . '/bin/saas.php') . ' backup 2>&1', $out, $code);
    eq(0, $code, implode("\n", $out));
    foreach (['test-alpha', 'test-beta', 'test-gamma'] as $s) {
        ok((bool) glob(BASE_PATH . "/storage/tenants/$s/backups/*.sql.gz"), "sauvegarde de $s dans son dossier");
    }
    exec("$envStr php " . escapeshellarg(BASE_PATH . '/bin/saas.php') . ' migrate 2>&1', $out, $code);
    eq(0, $code, implode("\n", $out));
    exec("$envStr php " . escapeshellarg(BASE_PATH . '/bin/migrate.php') . ' 2>&1', $out2, $code);
    ok($code !== 0 && str_contains(implode("\n", $out2), 'POSWAN_TENANT'), 'outil sans espace précisé refusé');
});
test('Journal de la console : opérations tracées', function () {
    $actions = array_column(Central::all('SELECT DISTINCT action FROM saas_audit'), 'action');
    foreach (['tenant.signup', 'tenant.provisioned', 'payment.declared', 'payment.validated', 'payment.rejected', 'tenant.suspended', 'tenant.extended', 'console.login_failed'] as $a) {
        ok(in_array($a, $actions, true), $a);
    }
});

proc_terminate($proc);
if (!getenv('TEST_SAAS_KEEP')) {
    $cleanup();
}
echo "\n" . str_repeat('─', 60) . "\n";
printf("Résultat SaaS : %d réussi(s), %d échec(s)\n", $results['pass'], $results['fail']);
foreach ($results['failures'] as $f) {
    echo "  - $f\n";
}
exit($results['fail'] ? 1 : 0);
