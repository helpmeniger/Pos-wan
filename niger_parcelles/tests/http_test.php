<?php
/**
 * Tests HTTP « boîte noire » sur l'application installée (Apache/WampServer) :
 * authentification, sessions, CSRF, rôles, XSS, injection SQL, documents
 * privés, accès direct aux dossiers protégés, limitation des connexions.
 *
 *   php tests/http_test.php
 *
 * Variables : NP_TEST_URL (défaut http://localhost/niger_parcelles),
 *             NP_TEST_ADMIN_USER, NP_TEST_ADMIN_PASS (compte administrateur général).
 * Les comptes et parcelles créés sont désactivés / archivés en fin de test.
 */
declare(strict_types=1);

require __DIR__ . '/TestCase.php';

$BASE = rtrim(getenv('NP_TEST_URL') ?: 'http://localhost/niger_parcelles', '/');
$ADMIN = getenv('NP_TEST_ADMIN_USER') ?: 'admin';
$ADMIN_PASS = getenv('NP_TEST_ADMIN_PASS') ?: '';
if ($ADMIN_PASS === '') {
    fwrite(STDERR, "Définissez NP_TEST_ADMIN_PASS (mot de passe du compte administrateur de test).\n");
    exit(2);
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "Extension PHP curl requise.\n");
    exit(2);
}

final class Http
{
    public string $jar;
    public array $last = [];

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'npjar');
    }

    public function request(string $method, string $path, $body = null, array $headers = []): array
    {
        $ch = curl_init($this->base . '/' . ltrim($path, '/'));
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $this->last = ['status' => $status, 'headers' => substr($raw, 0, $hsize), 'body' => substr($raw, $hsize)];
        return $this->last;
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $fields): array
    {
        return $this->request('POST', $path, $fields);
    }

    public function json(string $path, array $data, ?string $csrf): array
    {
        $h = ['Content-Type: application/json', 'Accept: application/json'];
        if ($csrf !== null) {
            $h[] = 'X-CSRF-Token: ' . $csrf;
        }
        return $this->request('POST', $path, json_encode($data), $h);
    }

    /** Jeton CSRF extrait d'une page HTML. */
    public function csrf(string $page = 'login.php'): string
    {
        $r = $this->get($page);
        if (!preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $r['body'], $m)) {
            throw new RuntimeException("Jeton CSRF introuvable sur $page (HTTP {$r['status']})");
        }
        return $m[1];
    }

    public function login(string $user, string $pass): array
    {
        $t = $this->csrf('login.php');
        return $this->post('login.php', ['_csrf' => $t, 'username' => $user, 'password' => $pass]);
    }
}

function location(array $r): string
{
    return preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $m) ? $m[1] : '';
}

$admin = new Http($BASE);
$created = ['parcels' => [], 'users' => []];
$stamp = substr(bin2hex(random_bytes(3)), 0, 6);
$GLOBALS['ctx'] = compact('BASE', 'ADMIN', 'ADMIN_PASS', 'admin', 'stamp');

/** Crée un parcelle par l'API ; retourne [code, données]. */
function api_create_parcel(Http $h, string $csrf, array $attrs, float $lng, float $lat, bool $ack = false): array
{
    $d = 20 / 108000;
    $g = ['type' => 'Polygon', 'coordinates' => [[[$lng, $lat], [$lng + $d, $lat], [$lng + $d, $lat + $d], [$lng, $lat + $d], [$lng, $lat]]]];
    $r = $h->json('api/parcelles.php?action=create', ['attributes' => $attrs, 'geometry' => $g, 'acknowledge' => $ack], $csrf);
    return [$r['status'], json_decode($r['body'], true)];
}

suite('Accès non authentifié');

test('Pages protégées redirigées vers la connexion ; API en 401', function () {
    $h = new Http($GLOBALS['ctx']['BASE']);
    foreach (['dashboard.php', 'carte.php', 'parcelles.php', 'proprietaires.php', 'utilisateurs.php', 'parametres.php', 'parcelle_details.php?id=1'] as $p) {
        $r = $h->get($p);
        assert_same(302, $r['status'], $p);
        assert_contains('login.php', location($r), $p);
    }
    foreach (['api/parcelles.php?action=list', 'api/recherche.php?q=NIG', 'api/export.php?type=csv', 'api/documents.php?action=download&id=1'] as $p) {
        $r = $h->get($p);
        assert_same(401, $r['status'], $p);
        assert_contains('"ok":false', $r['body']);
    }
});

test('Accès direct aux dossiers privés et fichiers sensibles refusé', function () {
    $h = new Http($GLOBALS['ctx']['BASE']);
    foreach (['config/database.php', 'config/database.local.php', 'includes/functions.php', 'includes/services/ParcelService.php', 'database/schema.sql',
              'storage/', 'storage/logs/app.log', 'storage/installed.lock', 'storage/documents/', 'tests/run_tests.php', 'tools/generate_demo_data.php',
              'README.md', '.htaccess', '.gitignore'] as $p) {
        $r = $h->get($p);
        assert_true(in_array($r['status'], [403, 404], true), "$p => HTTP {$r['status']}");
    }
});

test('Installateur verrouillé après installation', function () {
    $r = (new Http($GLOBALS['ctx']['BASE']))->get('install.php');
    assert_same(403, $r['status']);
    assert_contains('verrouillé', $r['body']);
});

test('En-têtes de sécurité présents', function () {
    $r = (new Http($GLOBALS['ctx']['BASE']))->get('login.php');
    assert_contains('Content-Security-Policy:', $r['headers']);
    assert_contains("script-src 'self'", $r['headers']);
    assert_contains('X-Frame-Options: SAMEORIGIN', $r['headers']);
    assert_contains('X-Content-Type-Options: nosniff', $r['headers']);
});

suite('Authentification et sessions');

test('Identifiants incorrects refusés (message générique)', function () {
    $h = new Http($GLOBALS['ctx']['BASE']);
    $r = $h->login($GLOBALS['ctx']['ADMIN'], 'mauvais-mot-de-passe');
    assert_same(200, $r['status']);
    assert_contains('Identifiant ou mot de passe incorrect', $r['body']);
    $r = $h->login('utilisateur_inexistant_' . $GLOBALS['ctx']['stamp'], 'x');
    assert_contains('Identifiant ou mot de passe incorrect', $r['body'], 'même message pour un compte inexistant');
});

test('Connexion sans jeton CSRF refusée', function () {
    $h = new Http($GLOBALS['ctx']['BASE']);
    $r = $h->post('login.php', ['username' => $GLOBALS['ctx']['ADMIN'], 'password' => $GLOBALS['ctx']['ADMIN_PASS']]);
    assert_same(403, $r['status']);
    assert_contains('CSRF', $r['body']);
});

test('Connexion réussie : redirection, cookie HttpOnly/SameSite, identifiant de session régénéré', function () {
    $admin = $GLOBALS['ctx']['admin'];
    $admin->get('login.php');
    $before = preg_match('/NPSESSID\s+(\S+)/', (string) file_get_contents($admin->jar), $m) ? $m[1] : '';
    $r = $admin->login($GLOBALS['ctx']['ADMIN'], $GLOBALS['ctx']['ADMIN_PASS']);
    assert_same(302, $r['status']);
    assert_contains('dashboard.php', location($r));
    assert_contains('HttpOnly', $r['headers']);
    assert_contains('SameSite=Lax', $r['headers']);
    $after = preg_match('/NPSESSID\s+(\S+)/', (string) file_get_contents($admin->jar), $m) ? $m[1] : '';
    assert_true($before !== '' && $after !== '' && $before !== $after, 'session régénérée');
    $r = $admin->get('dashboard.php');
    assert_same(200, $r['status']);
});

test('Limitation des tentatives de connexion (blocage après 5 échecs)', function () {
    $h = new Http($GLOBALS['ctx']['BASE']);
    $user = 'bruteforce_' . $GLOBALS['ctx']['stamp'];
    for ($i = 0; $i < 5; $i++) {
        $h->login($user, 'essai' . $i);
    }
    $r = $h->login($user, 'essai6');
    assert_contains('Trop de tentatives', $r['body']);
});

suite('CSRF, XSS et injection SQL');

test('API : écriture refusée sans jeton CSRF (403) ou avec un jeton invalide', function () {
    $admin = $GLOBALS['ctx']['admin'];
    $r = $admin->json('api/parcelles.php?action=check', ['geometry' => null], null);
    assert_same(403, $r['status']);
    assert_contains('CSRF', $r['body']);
    $r = $admin->json('api/parcelles.php?action=check', ['geometry' => null], str_repeat('a', 64));
    assert_same(403, $r['status']);
    assert_contains('CSRF', $r['body']);
});

test('Création d\'une parcelle par l\'API puis affichage échappé (XSS)', function () use (&$created) {
    $admin = $GLOBALS['ctx']['admin'];
    $csrf = $admin->csrf('dashboard.php');
    $GLOBALS['ctx']['csrf'] = $csrf;
    [$code, $d] = api_create_parcel($admin, $csrf, ['category' => 'residentiel', 'locality_description' => '<script>alert("xss")</script>',
        'description' => '<img src=x onerror=alert(1)>'], 2.30 + mt_rand(0, 9999) / 1e6, 13.42, true);
    assert_same(201, $code, json_encode($d));
    $created['parcels'][] = $d['id'];
    $GLOBALS['ctx']['pid'] = $d['id'];
    $r = $admin->get('parcelle_details.php?id=' . $d['id']);
    assert_same(200, $r['status']);
    assert_not_contains('<script>alert("xss")</script>', $r['body']);
    assert_contains('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $r['body']);
    assert_not_contains('<img src=x onerror', $r['body']);
    $j = json_decode($admin->get('api/parcelles.php?action=get&id=' . $d['id'])['body'], true);
    assert_same($d['parcel_reference'], $j['parcel']['parcel_reference'], 'rechargement depuis MySQL');
});

test('Injections SQL dans les paramètres : aucune erreur, aucune fuite', function () {
    $admin = $GLOBALS['ctx']['admin'];
    foreach (["' OR '1'='1", "1' UNION SELECT username, password_hash FROM users -- ", "'; DROP TABLE parcels; --"] as $evil) {
        $q = rawurlencode($evil);
        foreach (["parcelles.php?q=$q&area_id=$q&sort=$q&dir=$q", "api/recherche.php?q=$q", "proprietaires.php?q=$q", "documents.php?q=$q",
                  "api/parcelles.php?action=list&status=$q&category=$q&zoom=$q", "rapports.php?report=$q&area_id=$q"] as $p) {
            $r = $admin->get($p);
            assert_true($r['status'] === 200, "$p => {$r['status']}");
            assert_not_contains('Une erreur interne', $r['body'], $p);
            assert_not_contains('$2y$', $r['body'], 'aucun hachage de mot de passe divulgué');
            assert_not_contains('SQLSTATE', $r['body'], 'aucune erreur SQL affichée');
        }
    }
    assert_same(200, $admin->get('parcelles.php')['status'], 'table parcels intacte');
});

suite('Rôles et autorisations côté serveur');

test('Création de comptes par l\'administrateur (consultation, agent)', function () use (&$created) {
    $admin = $GLOBALS['ctx']['admin'];
    foreach (['consultation' => 5, 'agent_terrain' => 3] as $role => $roleId) {
        $u = 'http_' . $role . '_' . $GLOBALS['ctx']['stamp'];
        $t = $admin->csrf('utilisateurs.php');
        $r = $admin->post('utilisateurs.php', ['_csrf' => $t, 'action' => 'create', 'username' => $u, 'full_name' => 'Test HTTP ' . $role,
            'role_id' => (string) $roleId, 'password' => 'Provisoire-2026-ok']);
        assert_same(302, $r['status']);
        $created['users'][] = $u;
        $GLOBALS['ctx']['user_' . $role] = $u;
    }
});

test('Premier accès : changement de mot de passe imposé', function () {
    $h = new Http($GLOBALS['ctx']['BASE']);
    $h->login($GLOBALS['ctx']['user_consultation'], 'Provisoire-2026-ok');
    $r = $h->get('dashboard.php');
    assert_same(302, $r['status']);
    assert_contains('profil.php', location($r));
    $t = $h->csrf('profil.php');
    $r = $h->post('profil.php', ['_csrf' => $t, 'current_password' => 'Provisoire-2026-ok', 'new_password' => 'Nouveau-Mdp-2026', 'confirm_password' => 'Nouveau-Mdp-2026']);
    assert_same(302, $r['status']);
    assert_same(200, $h->get('dashboard.php')['status']);
    $GLOBALS['ctx']['consult'] = $h;
});

test('Profil consultation : lecture seule, aucune donnée personnelle ni administration', function () {
    $h = $GLOBALS['ctx']['consult'];
    assert_same(200, $h->get('parcelles.php')['status']);
    assert_same(200, $h->get('carte.php')['status']);
    foreach (['utilisateurs.php', 'parametres.php', 'proprietaires.php', 'importation.php', 'documents.php'] as $p) {
        assert_same(403, $h->get($p)['status'], $p);
    }
    $csrf = $h->csrf('dashboard.php');
    [$code] = api_create_parcel($h, $csrf, ['category' => 'autre'], 2.31, 13.43);
    assert_same(403, $code, 'création de parcelle interdite');
    assert_same(403, $h->get('api/proprietaires.php?action=search&q=te')['status']);
    assert_same(403, $h->get('api/export.php?type=csv')['status']);
    $j = json_decode($h->get('api/parcelles.php?action=get&id=' . $GLOBALS['ctx']['pid'])['body'], true);
    assert_false(isset($j['parcel']['owners']), 'noms des propriétaires masqués');
});

test('Agent de terrain : crée une parcelle mais ne peut pas la valider', function () use (&$created) {
    $h = new Http($GLOBALS['ctx']['BASE']);
    $h->login($GLOBALS['ctx']['user_agent_terrain'], 'Provisoire-2026-ok');
    $t = $h->csrf('profil.php');
    $h->post('profil.php', ['_csrf' => $t, 'current_password' => 'Provisoire-2026-ok', 'new_password' => 'Agent-Mdp-2026', 'confirm_password' => 'Agent-Mdp-2026']);
    $csrf = $h->csrf('dashboard.php');
    [$code, $d] = api_create_parcel($h, $csrf, ['category' => 'agricole'], 2.32 + mt_rand(0, 9999) / 1e6, 13.44, true);
    assert_same(201, $code, json_encode($d));
    $created['parcels'][] = $d['id'];
    $r = $h->json('api/parcelles.php?action=status', ['id' => $d['id'], 'status' => 'verifiee'], $csrf);
    assert_same(403, $r['status']);
    $r = $h->json('api/parcelles.php?action=status', ['id' => $d['id'], 'status' => 'soumise'], $csrf);
    assert_same(200, $r['status']);
    $r = $h->json('api/parcelles.php?action=update', ['id' => $GLOBALS['ctx']['pid'], 'attributes' => ['category' => 'commercial']], $csrf);
    assert_same(403, $r['status'], 'modification de la parcelle d\'un autre utilisateur interdite');
    assert_same(403, $h->get('utilisateurs.php')['status']);
});

suite('Documents privés');

test('Envoi validé, téléchargement contrôlé, fichier exécutable refusé, accès direct impossible', function () {
    $admin = $GLOBALS['ctx']['admin'];
    $pid = $GLOBALS['ctx']['pid'];
    $pdf = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << >>\n%%EOF\n");
    $t = $admin->csrf('parcelle_details.php?id=' . $pid);
    $r = $admin->post('parcelle_details.php?id=' . $pid, ['_csrf' => $t, 'action' => 'upload', 'document_type' => 'plan', 'title' => 'Plan test',
        'file' => new CURLFile($pdf, 'application/pdf', 'plan.pdf')]);
    assert_same(302, $r['status']);
    $page = $admin->get('parcelle_details.php?id=' . $pid . '&tab=documents')['body'];
    assert_contains('Document ajouté', $page);
    assert_true((bool) preg_match('/api\/documents\.php\?action=download&amp;id=(\d+)/', $page, $m), 'lien de téléchargement');
    $docId = $m[1];
    $r = $admin->get('api/documents.php?action=download&id=' . $docId);
    assert_same(200, $r['status']);
    assert_contains('Content-Disposition: attachment', $r['headers']);
    assert_same('%PDF-1.4', substr($r['body'], 0, 8));
    assert_same(403, $GLOBALS['ctx']['consult']->get('api/documents.php?action=download&id=' . $docId)['status'], 'consultation : téléchargement interdit');

    $evil = tempnam(sys_get_temp_dir(), 'evil');
    file_put_contents($evil, '<?php system($_GET["c"]); ?>');
    $t = $admin->csrf('parcelle_details.php?id=' . $pid);
    $admin->post('parcelle_details.php?id=' . $pid, ['_csrf' => $t, 'action' => 'upload', 'document_type' => 'photo',
        'file' => new CURLFile($evil, 'image/jpeg', 'photo.jpg')]);
    $page = $admin->get('parcelle_details.php?id=' . $pid . '&tab=documents')['body'];
    assert_contains('ne correspond pas', $page, 'PHP déguisé en image refusé');
    @unlink($pdf);
    @unlink($evil);
    $r = (new Http($GLOBALS['ctx']['BASE']))->get('storage/documents/' . date('Y/m') . '/');
    assert_true(in_array($r['status'], [403, 404], true), 'listing du stockage interdit');
});

suite('Exports et déconnexion');

test('Exports CSV / Excel / GeoJSON / PDF (administrateur)', function () {
    $admin = $GLOBALS['ctx']['admin'];
    $r = $admin->get('api/export.php?type=csv');
    assert_same(200, $r['status']);
    assert_contains('text/csv', $r['headers']);
    assert_contains('Référence', $r['body']);
    $r = $admin->get('api/export.php?type=xlsx');
    assert_same('PK', substr($r['body'], 0, 2));
    $r = $admin->get('api/export.php?type=geojson&id=' . $GLOBALS['ctx']['pid']);
    $j = json_decode($r['body'], true);
    assert_same('FeatureCollection', $j['type']);
    assert_same('Polygon', $j['features'][0]['geometry']['type']);
    $r = $admin->get('api/export.php?type=fiche_pdf&id=' . $GLOBALS['ctx']['pid']);
    assert_same('%PDF-', substr($r['body'], 0, 5));
});

test('Nettoyage : parcelles de test archivées, comptes de test désactivés', function () use (&$created) {
    $admin = $GLOBALS['ctx']['admin'];
    $csrf = $admin->csrf('dashboard.php');
    foreach ($created['parcels'] as $pid) {
        $r = $admin->json('api/parcelles.php?action=archive', ['id' => $pid, 'reason' => 'Test automatisé HTTP'], $csrf);
        assert_same(200, $r['status']);
    }
    $page = $admin->get('utilisateurs.php')['body'];
    foreach ($created['users'] as $u) {
        if (preg_match('/<strong>' . preg_quote($u, '/') . '<\/strong>.*?name="id" value="(\d+)".*?name="role_id"[^>]*>.*?value="(\d)" selected/s', $page, $m)) {
            $t = $admin->csrf('utilisateurs.php');
            $admin->post('utilisateurs.php', ['_csrf' => $t, 'action' => 'update', 'id' => $m[1], 'full_name' => 'Test HTTP (désactivé)', 'role_id' => $m[2]]);
        }
    }
    assert_not_contains('Test HTTP consultation', $admin->get('utilisateurs.php')['body']);
});

test('Déconnexion : GET sans effet (CSRF), POST avec jeton => session détruite', function () {
    $admin = $GLOBALS['ctx']['admin'];
    $admin->get('logout.php');
    assert_same(200, $admin->get('dashboard.php')['status'], 'GET logout ignoré');
    $t = $admin->csrf('dashboard.php');
    $r = $admin->post('logout.php', ['_csrf' => $t]);
    assert_same(302, $r['status']);
    $r = $admin->get('dashboard.php');
    assert_same(302, $r['status']);
    assert_contains('login.php', location($r));
});

echo "Niger Parcelles - tests HTTP sur $BASE\n";
exit(run_tests() > 0 ? 1 : 0);
