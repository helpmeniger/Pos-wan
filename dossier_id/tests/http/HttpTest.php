<?php
declare(strict_types=1);

/**
 * Tests HTTP de bout en bout : un serveur PHP intégré est lancé sur la base de test,
 * puis des requêtes réelles (cookies, CSRF, formulaires, envois de fichiers) sont émises.
 */

use App\Core\Database;

final class Http
{
    public static ?string $base = null;
    private static $proc = null;

    public static function start(): void
    {
        if (self::$base) {
            return;
        }
        $port = 18000 + random_int(0, 999);
        $env = array_merge(getenv(), ['DOSSIER_ID_CONFIG' => getenv('DOSSIER_ID_CONFIG')]);
        $log = App\Core\Config::get('storage.path') . '/logs/http-server.log';
        self::$proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', BASE_PATH . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes, BASE_PATH, $env
        );
        self::$base = "http://127.0.0.1:$port";
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $port)) {
                register_shutdown_function(fn () => proc_terminate(self::$proc));
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('Serveur de test non démarré.');
    }
}

final class Client
{
    private string $jar;
    public int $status = 0;
    public array $headers = [];
    public string $body = '';

    public function __construct()
    {
        Http::start();
        $this->jar = tempnam(sys_get_temp_dir(), 'did');
    }

    public function get(string $route, array $q = []): self
    {
        return $this->request('GET', $route, $q);
    }

    public function post(string $route, array $data = [], array $q = [], bool $csrf = true): self
    {
        if ($csrf && !isset($data['_csrf'])) {
            $data['_csrf'] = $this->csrf();
        }
        return $this->request('POST', $route, $q, $data);
    }

    public function csrf(): string
    {
        if (preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $this->body, $m)) {
            return $m[1];
        }
        $this->get('help.index');
        if (!preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $this->body, $m)) {
            $this->get('auth.login');
            preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $this->body, $m);
        }
        return $m[1] ?? '';
    }

    public function request(string $method, string $route, array $q = [], ?array $data = null): self
    {
        $ch = curl_init(Http::$base . '/index.php?' . http_build_query(['r' => $route] + $q));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
        ]);
        if ($method === 'POST') {
            $hasFile = (bool) array_filter($data, fn ($v) => $v instanceof CURLFile);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $hasFile ? $data : http_build_query($data));
        }
        $raw = (string) curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $this->headers = [];
        foreach (explode("\r\n", substr($raw, 0, $hsize)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $this->headers[strtolower(trim($k))][] = trim($v);
            }
        }
        $this->body = substr($raw, $hsize);
        return $this;
    }

    public function header(string $name): string
    {
        return implode("\n", $this->headers[strtolower($name)] ?? []);
    }

    public function follow(): self
    {
        $loc = $this->header('location');
        $q = [];
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
        $r = $q['r'] ?? 'dashboard';
        unset($q['r']);
        return $this->get($r, $q);
    }

    public function login(string $user, string $password = TEST_PASSWORD): self
    {
        $this->get('auth.login');
        return $this->post('auth.login.submit', ['username' => $user, 'password' => $password]);
    }
}

function person_form(array $over = []): array
{
    return $over + [
        'last_name' => 'Http', 'first_name' => 'Fictif', 'birth_date' => '1993-04-04', 'registration_date' => '2026-02-02',
        'registration_place' => 'Bureau HTTP', 'registering_service' => 'Service HTTP',
    ];
}

test('HTTP : en-têtes de sécurité et cookie de session protégé', function () {
    $c = (new Client())->get('auth.login');
    assert_same(200, $c->status);
    assert_contains("default-src 'self'", $c->header('content-security-policy'));
    assert_contains("frame-ancestors 'none'", $c->header('content-security-policy'));
    assert_same('DENY', $c->header('x-frame-options'));
    assert_same('nosniff', $c->header('x-content-type-options'));
    $cookie = $c->header('set-cookie');
    assert_contains('HttpOnly', $cookie);
    assert_contains('SameSite=Strict', $cookie);
    assert_not_contains('X-Powered-By', implode(',', array_keys($c->headers)));
});

test('HTTP : accès sans authentification redirigé, routes inconnues et mauvais verbe refusés', function () {
    $c = new Client();
    $c->get('persons.index');
    assert_same(303, $c->status);
    assert_contains('r=auth.login', $c->header('location'));
    $c->get('photos.show', ['id' => 1]);
    assert_same(303, $c->status, 'photo protégée');
    $c->get('route.inexistante');
    assert_same(404, $c->status);
    $c->get('auth.login.submit');
    assert_same(405, $c->status);
});

test('HTTP : formulaire sans jeton CSRF refusé (419)', function () {
    $c = new Client();
    $c->get('auth.login');
    $c->post('auth.login.submit', ['username' => 'x', 'password' => 'y', '_csrf' => str_repeat('0', 64)]);
    assert_same(419, $c->status);
    make_user('http.csrf', 'enregistrement');
    $c->login('http.csrf');
    $c->post('persons.store', person_form(), [], false);
    assert_same(419, $c->status);
});

test('HTTP : connexion, changement de mot de passe imposé, déconnexion', function () {
    make_user('http.first', 'consultation', ['must_change_password' => 1]);
    $c = (new Client())->login('http.first');
    assert_same(303, $c->status);
    $c->get('dashboard');
    assert_same(303, $c->status);
    assert_contains('r=auth.password', $c->header('location'));
    $c->get('auth.password');
    $c->post('auth.password.save', ['current_password' => TEST_PASSWORD, 'new_password' => 'court', 'confirm_password' => 'court']);
    assert_contains('au moins', $c->body);
    $c->post('auth.password.save', ['current_password' => TEST_PASSWORD, 'new_password' => 'Nouveau-Secret-2026', 'confirm_password' => 'Nouveau-Secret-2026']);
    assert_same(303, $c->status);
    $c->get('dashboard');
    assert_same(200, $c->status);
    $c->post('auth.logout');
    $c->get('dashboard');
    assert_same(303, $c->status, 'session détruite');
    $c->login('http.first', 'Nouveau-Secret-2026');
    $c->get('dashboard');
    assert_same(200, $c->status);
});

test('HTTP : création de fiche, détection de doublon et justification', function () {
    make_user('http.agent', 'enregistrement');
    $c = (new Client())->login('http.agent');
    $c->get('persons.create');
    $c->post('persons.store', person_form(['last_name' => 'Kabore', 'first_name' => 'Salif']));
    assert_same(303, $c->status);
    $c->follow();
    assert_contains('Fiche créée', $c->body);
    $c->get('persons.create');
    $c->post('persons.store', person_form(['last_name' => 'Kaboré', 'first_name' => 'Salif']));
    assert_same(200, $c->status);
    assert_contains('Doublons potentiels détectés', $c->body);
    assert_same(1, (int) Database::value("SELECT COUNT(*) FROM persons WHERE last_name_norm = 'kabore'"));
    $c->post('persons.store', person_form(['last_name' => 'Kaboré', 'first_name' => 'Salif', 'confirm_distinct' => '1', 'distinct_justification' => 'Pièces d\'identité différentes']));
    assert_same(303, $c->status);
    assert_same(2, (int) Database::value("SELECT COUNT(*) FROM persons WHERE last_name_norm = 'kabore'"));
    assert_contains('doublons_signales', (string) Database::value("SELECT details FROM audit_log WHERE action = 'person.create' ORDER BY id DESC LIMIT 1"));
});

test('HTTP : import de photographie, affichage protégé et journalisé', function () {
    $agent = make_user('http.photo', 'enregistrement');
    make_user('http.photo.consult', 'consultation');
    make_user('http.photo.ctrl', 'controleur');
    act_as($agent);
    $pid = App\Models\Person::create(person_data(['last_name' => 'Photo']), $agent);
    $c = (new Client())->login('http.photo');
    $c->get('persons.show', ['id' => $pid, 'tab' => 'photos']);
    $file = tmp_file(jpeg_bytes(500, 600, true), '.jpg');
    $c->post('photos.store', ['photo' => new CURLFile($file, 'image/jpeg', 'portrait.jpg'), 'authorization_ref' => 'AUT-1', 'consent_confirmed' => '1'], ['person_id' => $pid]);
    assert_same(303, $c->status);
    $photoId = (int) Database::value('SELECT id FROM photos WHERE person_id = :p', ['p' => $pid]);
    assert_true($photoId > 0, 'photo enregistrée');
    $c->get('photos.show', ['id' => $photoId]);
    assert_same(200, $c->status);
    assert_same('image/jpeg', $c->header('content-type'));
    assert_true(str_starts_with($c->body, "\xFF\xD8"), 'JPEG servi');
    assert_not_contains('GPS-SECRET', $c->body);
    assert_contains('no-store', $c->header('cache-control'));
    // Fichier non image
    $c->get('persons.show', ['id' => $pid]);
    $c->post('photos.store', ['photo' => new CURLFile(tmp_file('<?php phpinfo();', '.jpg'), 'image/jpeg', 'x.jpg'), 'authorization_ref' => 'AUT-2', 'consent_confirmed' => '1'], ['person_id' => $pid]);
    $c->follow();
    assert_contains('Format refusé', $c->body);
    // Autorisations
    $cc = (new Client())->login('http.photo.consult');
    $cc->get('photos.show', ['id' => $photoId]);
    assert_same(200, $cc->status, 'consultation autorisée');
    $cc->get('persons.show', ['id' => $pid]);
    $cc->post('photos.store', ['photo' => new CURLFile($file, 'image/jpeg', 'p.jpg'), 'authorization_ref' => 'X', 'consent_confirmed' => '1'], ['person_id' => $pid]);
    assert_same(403, $cc->status, 'import interdit en consultation');
    $ct = (new Client())->login('http.photo.ctrl');
    $ct->get('photos.show', ['id' => $photoId]);
    assert_same(403, $ct->status);
    assert_true((int) Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'photo.view' AND entity_id = :p", ['p' => $pid]) >= 2);
});

test('HTTP : utilisateur non autorisé et tentative d\'accès à une fiche restreinte', function () {
    $agent = make_user('http.r.agent', 'enregistrement');
    make_user('http.r.consult', 'consultation');
    act_as($agent);
    $pid = App\Models\Person::create(person_data(['last_name' => 'Restreinte', 'access_level' => 'restreint']), $agent);
    $c = (new Client())->login('http.r.agent');
    $c->get('persons.show', ['id' => $pid]);
    assert_same(403, $c->status);
    assert_contains('restriction', $c->body);
    $c->get('users.index');
    assert_same(403, $c->status);
    $c->get('audit.index');
    assert_same(403, $c->status);
    $cc = (new Client())->login('http.r.consult');
    $cc->get('persons.show', ['id' => $pid]);
    assert_same(200, $cc->status);
    $cc->get('persons.create');
    $cc->post('persons.store', person_form());
    assert_same(403, $cc->status);
    $cc->get('persons.edit', ['id' => $pid]);
    assert_same(403, $cc->status);
    assert_true((int) Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'access.denied' AND username IN ('http.r.agent','http.r.consult')") >= 4);
});

test('HTTP : rectification motivée, archivage et dossier clôturé non modifiable', function () {
    $agent = make_user('http.arch', 'enregistrement');
    make_user('http.arch.ctrl', 'controleur');
    act_as($agent);
    $pid = App\Models\Person::create(person_data(['last_name' => 'Archive']), $agent);
    $c = (new Client())->login('http.arch');
    $c->get('persons.edit', ['id' => $pid]);
    $c->post('persons.update', person_form(['last_name' => 'Archive', 'profession' => 'Maçon']), ['id' => $pid]);
    assert_contains('motif de la rectification', $c->body, 'motif exigé');
    $c->post('persons.update', person_form(['last_name' => 'Archive', 'profession' => 'Maçon', 'change_reason' => 'Déclaration du jour']), ['id' => $pid]);
    assert_same(303, $c->status);
    assert_same('Maçon', Database::value('SELECT profession FROM persons WHERE id = :id', ['id' => $pid]));
    $c->get('persons.show', ['id' => $pid]);
    $c->post('persons.archive', ['reason' => 'Fin de conservation'], ['id' => $pid]);
    assert_same('archive', Database::value('SELECT status FROM persons WHERE id = :id', ['id' => $pid]));
    $c->get('persons.edit', ['id' => $pid]);
    $c->post('persons.update', person_form(['change_reason' => 'Tentative']), ['id' => $pid]);
    assert_same(403, $c->status, 'modification d\'une fiche archivée refusée');
    $c->get('persons.index');
    assert_not_contains('ARCHIVE', $c->body, 'absente des consultations courantes');
});

test('HTTP : sauvegarde par l\'administrateur, restauration exigeant une confirmation', function () {
    make_user('http.admin', 'admin');
    $c = (new Client())->login('http.admin');
    $c->get('backups.index');
    $before = count(App\Services\BackupService::list());
    $c->post('backups.create');
    assert_same(303, $c->status);
    assert_same($before + 1, count(App\Services\BackupService::list()));
    $name = App\Services\BackupService::list()[0]['name'];
    $c->get('backups.index');
    $c->post('backups.restore', ['name' => $name, 'confirm' => 'oui']);
    $c->follow();
    assert_contains('RESTAURER', $c->body);
    $c->get('backups.download', ['name' => '../../config/config.php']);
    assert_same(303, $c->status, 'nom de fichier invalide refusé');
    $c->get('persons.index');
    assert_same(403, $c->status, 'administrateur sans accès aux dossiers');
});

test('HTTP : répertoire public limité au contrôleur frontal et aux ressources statiques', function () {
    $public = BASE_PATH . '/public';
    $php = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($public, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (preg_match('/\.(php|phtml|key|sql|didbak|bin)$/', $f->getFilename())) {
            $php[] = substr($f->getPathname(), strlen($public));
        }
    }
    assert_same(['/index.php'], $php);
    foreach (['app', 'config', 'database', 'storage', 'scripts', 'tests', 'docs'] as $d) {
        assert_contains('Require all denied', (string) @file_get_contents(BASE_PATH . "/$d/.htaccess"), "$d/.htaccess");
    }
});

test('HTTP : passages, procédures, documents, revue de doublon, comptes, paramètres et audit', function () {
    $agent = make_user('http.flow', 'enregistrement');
    make_user('http.flow.admin', 'admin');
    make_user('http.flow.ctrl', 'controleur');
    act_as($agent);
    $pid = App\Models\Person::create(person_data(['last_name' => 'Parcours', 'first_name' => 'Complet']), $agent);
    $pid2 = App\Models\Person::create(person_data(['last_name' => 'Parcours', 'first_name' => 'Complette']), $agent);
    $c = (new Client())->login('http.flow');

    $c->get('visits.create', ['person_id' => $pid]);
    $c->post('visits.store', ['visit_date' => '2026-05-05T10:30', 'location' => 'Bureau 2', 'service' => 'Accueil', 'reason' => 'Convocation',
        'reference' => 'R-HTTP-1', 'responsible_agent' => 'Agent Y'], ['person_id' => $pid]);
    assert_same(303, $c->status);
    $vid = (int) Database::value("SELECT id FROM visits WHERE reference = 'R-HTTP-1'");

    $c->get('procedures.create', ['person_id' => $pid]);
    $c->post('procedures.store', ['procedure_number' => 'P-HTTP-1', 'facts_nature' => 'Faits allégués', 'investigating_service' => 'Brigade', 'status' => 'condamnee'], ['person_id' => $pid]);
    assert_same(200, $c->status);
    assert_contains('exige', $c->body, 'condamnation non documentée refusée');
    $c->post('procedures.store', ['procedure_number' => 'P-HTTP-1', 'facts_nature' => 'Faits allégués', 'investigating_service' => 'Brigade', 'status' => 'mise_en_cause'], ['person_id' => $pid]);
    assert_same(303, $c->status);
    $prid = (int) Database::value("SELECT id FROM procedures WHERE procedure_number = 'P-HTTP-1'");
    $c->get('procedures.edit', ['id' => $prid]);
    $c->post('procedures.update', ['procedure_number' => 'P-HTTP-1', 'facts_nature' => 'Faits allégués', 'investigating_service' => 'Brigade', 'status' => 'cloturee',
        'status_justification' => 'Classement sans suite (fictif)', 'change_reason' => 'Décision du parquet'], ['id' => $prid]);
    assert_same(303, $c->status);
    assert_same('cloturee', Database::value('SELECT status FROM procedures WHERE id = :id', ['id' => $prid]));

    $pdf = tmp_file("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n", '.pdf');
    $c->get('persons.show', ['id' => $pid]);
    $c->post('documents.store', ['document' => new CURLFile($pdf, 'application/pdf', 'piece.pdf'), 'title' => 'Pièce', 'procedure_id' => (string) $prid], ['person_id' => $pid]);
    assert_same(303, $c->status);
    $did = (int) Database::value('SELECT id FROM documents WHERE person_id = :p', ['p' => $pid]);
    $c->get('documents.download', ['id' => $did]);
    assert_same(200, $c->status);
    assert_true(str_starts_with($c->body, '%PDF-'));
    assert_contains('attachment', $c->header('content-disposition'));
    $c->get('persons.show', ['id' => $pid]);
    $c->post('documents.store', ['document' => new CURLFile($pdf, 'application/pdf', 'x.pdf'), 'title' => 'X', 'visit_id' => '999999'], ['person_id' => $pid]);
    $c->follow();
    assert_contains('Passage lié invalide', $c->body);

    $c->get('persons.show', ['id' => $pid, 'tab' => 'visits']);
    assert_contains('R-HTTP-1', $c->body);
    assert_contains('Dossier clôturé', $c->body);
    $c->get('persons.print', ['id' => $pid]);
    assert_contains('Diffusion restreinte', $c->body);
    $c->get('visits.index', ['reference' => 'R-HTTP']);
    assert_contains('R-HTTP-1', $c->body);
    $c->get('procedures.index', ['status' => 'cloturee']);
    assert_contains('P-HTTP-1', $c->body);

    $c->get('duplicates.compare', ['a' => $pid, 'b' => $pid2]);
    assert_contains('Comparaison effectuée par l\'agent', $c->body);
    $c->post('duplicates.review', ['decision' => 'distinctes', 'comment' => 'Pièces différentes'], ['a' => $pid2, 'b' => $pid]);
    assert_same(303, $c->status);
    assert_same('distinctes', Database::value('SELECT decision FROM duplicate_reviews WHERE person_a_id = :a AND person_b_id = :b', ['a' => min($pid, $pid2), 'b' => max($pid, $pid2)]));

    $a = (new Client())->login('http.flow.admin');
    $a->get('users.create');
    $a->post('users.store', ['username' => 'http.nouveau', 'full_name' => 'Nouvel Agent', 'role_id' => (string) Database::value("SELECT id FROM roles WHERE code = 'consultation'")]);
    assert_same(303, $a->status);
    $a->follow();
    assert_contains('Mot de passe temporaire', $a->body);
    preg_match('#<code class="fs-5 user-select-all">([^<]+)</code>#', $a->body, $m);
    $temp = html_entity_decode($m[1] ?? '', ENT_QUOTES);
    $n = (new Client())->login('http.nouveau', $temp);
    $n->get('dashboard');
    assert_contains('r=auth.password', $n->header('location'), 'changement imposé');
    $a->get('settings.index');
    $a->post('settings.update', ['institution_name' => 'Service test', 'session_idle_minutes' => '999', 'session_max_hours' => '8', 'login_max_attempts' => '5',
        'lockout_minutes' => '15', 'account_inactivity_days' => '90', 'password_min_length' => '12']);
    $a->follow();
    assert_contains('Valeur invalide', $a->body, 'bornes contrôlées');
    $a->post('settings.permissions', ['perm' => []]);
    $admin = (int) Database::value("SELECT id FROM roles WHERE code = 'admin'");
    assert_true((int) Database::value("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :r AND p.code = 'users.manage'", ['r' => $admin]) === 1,
        'garde-fou : l\'administrateur conserve la gestion des comptes');

    // Rétablissement de la matrice par défaut
    Database::run('DELETE FROM role_permissions');
    Database::run("INSERT INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r JOIN permissions p
        ON (r.code = 'admin' AND p.code IN ('users.manage','backups.manage','settings.manage','stats.view'))
        OR (r.code = 'enregistrement' AND p.code IN ('persons.view','persons.view_archived','persons.create','persons.edit','persons.archive','photos.view','photos.manage','visits.view','visits.manage','procedures.view','procedures.manage','documents.view','documents.manage','duplicates.review','stats.view'))
        OR (r.code = 'consultation' AND p.code IN ('persons.view','persons.view_restricted','persons.view_archived','photos.view','visits.view','procedures.view','documents.view','export.csv','stats.view'))
        OR (r.code = 'controleur' AND p.code IN ('audit.view','stats.view','export.csv'))");

    $k = (new Client())->login('http.flow.ctrl');
    $k->get('audit.index', ['action' => 'permissions.']);
    assert_contains('Modification des permissions', $k->body);
    $k->post('audit.verify');
    $k->follow();
    assert_contains('Chaîne d\'intégrité valide', $k->body);
    $k->get('audit.export');
    assert_same(200, $k->status);
    assert_contains('text/csv', $k->header('content-type'));
    $k->get('dashboard');
    assert_contains('Alertes de sécurité', $k->body);

});
