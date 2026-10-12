<?php
/**
 * Installateur web sécurisé de Niger Parcelles.
 *
 * Procédure sûre :
 * - la base et son utilisateur MySQL dédié sont créés au préalable par
 *   l'administrateur dans phpMyAdmin (l'installateur ne crée ni ne supprime
 *   jamais de base de données) ;
 * - l'installateur vérifie les prérequis et la connexion (bouton « Tester ») ;
 * - les tables sont créées automatiquement si la base est vide ; si elles ont
 *   déjà été importées avec phpMyAdmin, seul l'administrateur est créé ;
 *   une base contenant déjà des données n'est jamais modifiée ;
 * - les paramètres de connexion sont écrits dans config/database.local.php
 *   (non versionné, inaccessible depuis le navigateur) ;
 * - Crée le compte administrateur avec le mot de passe choisi par l'utilisateur
 *   (jamais affiché ni enregistré en clair, uniquement haché).
 * - Se verrouille après installation (storage/installed.lock).
 */
declare(strict_types=1);

define('NP_INSTALLER', true);
require __DIR__ . '/includes/bootstrap.php';

if (app_is_installed()) {
    http_response_code(403);
    $pageTitle = 'Installation verrouillée';
    require __DIR__ . '/includes/header.php';
    echo '<div class="container py-5" style="max-width:720px"><div class="alert alert-info"><h1 class="h5">Application déjà installée</h1>'
        . '<p>L\'installateur est verrouillé. Pour réinstaller, supprimez manuellement le fichier <code>storage/installed.lock</code> '
        . '(opération réservée à l\'administrateur du serveur).</p><a class="btn btn-primary" href="' . e(url('login.php')) . '">Aller à la connexion</a></div></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}


// ---------------------------------------------------------------- Prérequis
$checks = [
    ['PHP 8.0 ou supérieur (actuel : ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.0.0', '>='), true],
    ['Extension pdo_mysql', extension_loaded('pdo_mysql'), true],
    ['Extension mbstring', extension_loaded('mbstring'), true],
    ['Extension fileinfo (contrôle des documents)', extension_loaded('fileinfo'), true],
    ['Extension json', extension_loaded('json'), true],
    ['Extension zip (exports Excel, import Shapefile, sauvegardes)', class_exists('ZipArchive'), false],
    ['Extension openssl / random_bytes', function_exists('random_bytes'), true],
];
foreach (['', 'documents', 'exports', 'logs', 'imports', 'backups'] as $dir) {
    $path = storage_path($dir);
    if (!is_dir($path)) {
        @mkdir($path, 0750, true);
    }
    $checks[] = ['Dossier storage/' . $dir . ' accessible en écriture', is_dir($path) && is_writable($path), true];
}
$localConfig = __DIR__ . '/config/database.local.php';
$checks[] = ['Dossier config/ accessible en écriture (enregistrement des paramètres de connexion)',
    is_file($localConfig) ? is_writable($localConfig) : is_writable(__DIR__ . '/config'), true];
$blocking = array_filter($checks, static fn ($c) => $c[2] && !$c[1]);

/** Tables créées par database/schema.sql. */
const NP_TABLES = ['roles', 'users', 'login_attempts', 'administrative_areas', 'owners', 'parcel_reference_sequences', 'parcels',
    'parcel_geometries', 'mutations', 'parcel_owners', 'documents', 'parcel_history', 'parcel_overlaps', 'import_batches', 'audit_logs', 'settings'];

/** Message compréhensible pour une erreur de connexion MySQL (sans détail technique sensible). */
function install_connection_error(PDOException $e, array $dbc): string
{
    $m = $e->getMessage();
    if (strpos($m, '1049') !== false || stripos($m, 'Unknown database') !== false) {
        return 'La base « ' . $dbc['database'] . ' » n\'existe pas. Créez-la d\'abord dans phpMyAdmin (voir l\'encadré « Avant de commencer »).';
    }
    if (strpos($m, '1044') !== false) {
        return 'L\'utilisateur « ' . $dbc['username'] . ' » n\'a pas accès à la base « ' . $dbc['database'] . ' » : vérifiez que la base existe '
            . 'et que cet utilisateur a tous les privilèges sur elle (phpMyAdmin › Comptes d\'utilisateurs).';
    }
    if (strpos($m, '1045') !== false || stripos($m, 'Access denied') !== false) {
        return 'Accès refusé : nom d\'utilisateur ou mot de passe MySQL incorrect.';
    }
    if (strpos($m, '2002') !== false || strpos($m, '2006') !== false || stripos($m, 'refused') !== false) {
        return 'Serveur MySQL injoignable sur ' . $dbc['host'] . ':' . $dbc['port'] . '. Vérifiez que l\'icône WampServer est verte et le port (3306 pour MySQL, souvent 3307 pour MariaDB).';
    }
    return 'Connexion à MySQL impossible. Le détail technique est enregistré dans storage/logs/app.log.';
}

/** Analyse la base : 'vide', 'tables_importees', 'deja_utilisee' ou 'incomplete'. */
function install_database_state(PDO $pdo): array
{
    $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $ours = array_values(array_intersect(NP_TABLES, $existing));
    if (!$ours) {
        return ['state' => 'vide', 'others' => count($existing)];
    }
    if (count($ours) < count(NP_TABLES)) {
        return ['state' => 'incomplete', 'missing' => array_values(array_diff(NP_TABLES, $ours))];
    }
    $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    return ['state' => $users > 0 ? 'deja_utilisee' : 'tables_importees'];
}

$defaults = require __DIR__ . '/config/database.php';
$form = [
    'db_host' => $defaults['host'] === '127.0.0.1' ? 'localhost' : $defaults['host'], 'db_port' => (string) $defaults['port'],
    'db_name' => $defaults['database'], 'db_user' => $defaults['username'] === 'root' ? '' : $defaults['username'],
    'admin_username' => 'admin', 'admin_full_name' => '', 'admin_email' => '', 'demo' => '',
];
$errors = [];
$notice = null;
$warnings = [];
$done = false;
$configWritten = false;

if (request_method() === 'POST' && !$blocking) {
    require_csrf();
    $action = ($_POST['action'] ?? '') === 'test' ? 'test' : 'install';
    foreach ($form as $k => $v) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $dbPass = (string) ($_POST['db_pass'] ?? '');

    // --- Validation des paramètres de connexion
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $form['db_name'])) {
        $errors[] = 'Nom de base invalide (lettres, chiffres et _ uniquement).';
    }
    if (!preg_match('/^[A-Za-z0-9.\-]{1,255}$/', $form['db_host'])) {
        $errors[] = 'Hôte MySQL invalide.';
    }
    $port = filter_var($form['db_port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($port === false) {
        $errors[] = 'Port MySQL invalide.';
    }
    if (!preg_match('/^[A-Za-z0-9_.\-]{1,80}$/', $form['db_user'])) {
        $errors[] = 'Nom d\'utilisateur MySQL invalide.';
    }
    if ($dbPass === '') {
        $errors[] = 'Le mot de passe MySQL est obligatoire : une installation sûre exige un utilisateur MySQL protégé par mot de passe.';
    }
    if (strtolower($form['db_user']) === 'root') {
        $warnings[] = 'Vous utilisez le compte « root ». Il est recommandé de créer un utilisateur MySQL dédié, limité à la base de l\'application.';
    }

    // --- Validation du compte administrateur (installation uniquement)
    if ($action === 'install') {
        $adminPass = (string) ($_POST['admin_password'] ?? '');
        $adminPass2 = (string) ($_POST['admin_password2'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.\-]{3,60}$/', $form['admin_username'])) {
            $errors[] = 'Identifiant administrateur invalide (3 à 60 caractères : lettres, chiffres, . _ -).';
        }
        if ($form['admin_full_name'] === '') {
            $errors[] = 'Le nom complet de l\'administrateur est obligatoire.';
        }
        if ($form['admin_email'] !== '' && !filter_var($form['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adresse e-mail de l\'administrateur invalide.';
        }
        if ($adminPass !== $adminPass2) {
            $errors[] = 'Les deux mots de passe administrateur ne correspondent pas.';
        } elseif (($pe = password_policy_error($adminPass)) !== null) {
            $errors[] = $pe;
        } elseif (hash_equals($dbPass, $adminPass)) {
            $errors[] = 'Le mot de passe de l\'administrateur doit être différent de celui de MySQL.';
        }
    }

    // --- Connexion à la base existante (jamais de CREATE / DROP DATABASE)
    $pdo = null;
    if (!$errors) {
        $dbc = ['host' => $form['db_host'], 'port' => (int) $port, 'database' => $form['db_name'],
                'username' => $form['db_user'], 'password' => $dbPass, 'charset' => 'utf8mb4'];
        try {
            $pdo = db_connect($dbc);
            $state = install_database_state($pdo);
            if ($state['state'] === 'deja_utilisee') {
                $errors[] = 'La base « ' . $form['db_name'] . ' » contient déjà une installation de Niger Parcelles avec des utilisateurs. '
                    . 'Par sécurité, l\'installateur ne modifie jamais une base déjà utilisée. Choisissez une base vide.';
            } elseif ($state['state'] === 'incomplete') {
                $errors[] = 'La base contient une installation incomplète (tables manquantes : ' . implode(', ', $state['missing']) . '). '
                    . 'Videz la base dans phpMyAdmin (onglet Opérations ou Structure) puis relancez l\'installation.';
            }
        } catch (PDOException $e) {
            log_error($e);
            $errors[] = install_connection_error($e, $dbc);
        }
    }

    if (!$errors && $action === 'test') {
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $notice = 'Connexion réussie à « ' . $form['db_name'] . ' » (serveur ' . $version . '). '
            . ($state['state'] === 'vide'
                ? 'La base est vide : les tables seront créées automatiquement lors de l\'installation.'
                : 'Les tables sont déjà présentes (import phpMyAdmin) : seul le compte administrateur sera créé.')
            . ' Re-saisissez le mot de passe MySQL puis complétez le compte administrateur.';
        if (($state['others'] ?? 0) > 0) {
            $warnings[] = 'La base contient ' . $state['others'] . ' autre(s) table(s) sans rapport avec Niger Parcelles. Elles ne seront pas modifiées, '
                . 'mais une base dédiée est recommandée.';
        }
    }

    // --- Installation
    if (!$errors && $action === 'install') {
        $createdTables = false;
        try {
            if ($state['state'] === 'vide') {
                SqlRunner::runFile($pdo, __DIR__ . '/database/schema.sql');
                $createdTables = true;
                if ($form['demo'] === '1') {
                    SqlRunner::runFile($pdo, __DIR__ . '/database/demo_data.sql');
                }
            }
            $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code = 'admin_general'")->fetchColumn();
            if ($roleId <= 0) {
                throw new RuntimeException('Rôle admin_general introuvable : schema.sql n\'a pas été importé entièrement.');
            }
            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, email, role_id, is_active) VALUES (?, ?, ?, ?, ?, 1)');
            $stmt->execute([$form['admin_username'], password_hash($adminPass, PASSWORD_DEFAULT), mb_substr($form['admin_full_name'], 0, 150),
                            $form['admin_email'] !== '' ? $form['admin_email'] : null, $roleId]);
            $adminId = (int) $pdo->lastInsertId();

            // Paramètres de connexion locaux (non versionnés, protégés par .htaccess)
            $local = "<?php\n// Généré par install.php le " . date('Y-m-d H:i') . ". Ne pas versionner ni partager.\nreturn "
                . var_export(['host' => $dbc['host'], 'port' => $dbc['port'], 'database' => $dbc['database'],
                              'username' => $dbc['username'], 'password' => $dbc['password']], true) . ";\n";
            if (@file_put_contents($localConfig, $local, LOCK_EX) === false) {
                throw new RuntimeException('Écriture de config/database.local.php impossible.');
            }
            @chmod($localConfig, 0640);
            $configWritten = true;

            $pdo->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, 'installation', 'user', ?, ?, ?)")
                ->execute([$adminId, $adminId, json_encode(['demo' => $form['demo'] === '1', 'tables_creees' => $createdTables]), client_ip()]);
            file_put_contents(storage_path('installed.lock'), 'Installé le ' . date('c') . "\n", LOCK_EX);
            $done = true;
        } catch (Throwable $e) {
            log_error($e);
            // Annulation : on supprime uniquement les tables créées pendant CETTE installation
            if ($createdTables) {
                try {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                    foreach (NP_TABLES as $t) {
                        $pdo->exec("DROP TABLE IF EXISTS `$t`");
                    }
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                } catch (Throwable $e2) {
                    log_error($e2);
                }
            }
            if ($configWritten) {
                @unlink($localConfig);
            }
            $errors[] = $e instanceof PDOException && strpos($e->getMessage(), '1142') !== false
                ? 'L\'utilisateur MySQL n\'a pas le droit de créer des tables dans cette base. Donnez-lui tous les privilèges sur la base dans phpMyAdmin.'
                : 'L\'installation a échoué et a été annulée (' . ($e instanceof RuntimeException ? $e->getMessage() : 'erreur de base de données')
                  . '). Le détail technique est dans storage/logs/app.log.';
        }
    }
}

$pageTitle = 'Installation';
require __DIR__ . '/includes/header.php';
?>
<div class="container py-4" style="max-width: 860px">
    <div class="d-flex align-items-center gap-3 mb-3">
        <img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="56" height="56">
        <div><h1 class="h3 mb-0">Installation de Niger Parcelles</h1><div class="text-muted">Assistant de configuration pour WampServer</div></div>
    </div>

<?php if ($done): ?>
    <div class="alert alert-success">
        <h2 class="h5"><i class="bi bi-check-circle"></i> Installation terminée</h2>
        <ul class="mb-2">
            <li>Connexion à la base « <?= e($form['db_name']) ?> » enregistrée dans <code>config/database.local.php</code> (fichier privé).</li>
            <li><?= $state['state'] === 'vide' ? 'Tables créées automatiquement.' : 'Tables existantes conservées.' ?></li>
            <li>Compte administrateur « <strong><?= e($form['admin_username']) ?></strong> » créé.</li>
            <?php if ($form['demo'] === '1' && $state['state'] === 'vide'): ?><li>Données de démonstration <strong>fictives</strong> chargées (à supprimer avant l'exploitation réelle).</li><?php endif; ?>
            <li>L'installateur est désormais verrouillé.</li>
        </ul>
        <a class="btn btn-primary" href="<?= e(url('login.php')) ?>">Se connecter</a>
    </div>
<?php else: ?>
    <div class="card mb-3 border-primary">
        <div class="card-header"><i class="bi bi-info-circle"></i> Avant de commencer : créer la base et son utilisateur dans phpMyAdmin</div>
        <div class="card-body small">
            <ol class="mb-2">
                <li>Clic gauche sur l'icône WampServer › <strong>phpMyAdmin</strong>, connectez-vous (par défaut : <code>root</code>, mot de passe vide).</li>
                <li>Onglet <strong>Comptes d'utilisateurs</strong> › <strong>Ajouter un compte d'utilisateur</strong>.</li>
                <li>Nom d'utilisateur : par exemple <code>niger_app</code> · Nom d'hôte : <strong>Local</strong> (<code>localhost</code>) · un <strong>mot de passe robuste</strong>.</li>
                <li>Cochez <strong>« Créer une base portant son nom et donner à cet utilisateur tous les privilèges »</strong>
                    (ou créez la base <code>niger_parcelles</code> en <code>utf8mb4_unicode_ci</code> et donnez à l'utilisateur tous les privilèges sur cette seule base).</li>
                <li>Ne cochez <strong>aucun</strong> privilège global, puis cliquez sur <strong>Exécuter</strong>.</li>
            </ol>
            Saisissez ensuite ci-dessous le nom de la base, l'utilisateur et son mot de passe : les tables seront créées automatiquement.
            L'installateur ne crée ni ne supprime jamais de base de données.
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">1. Vérification des prérequis</div>
        <ul class="list-group list-group-flush">
            <?php foreach ($checks as [$label, $ok, $required]): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <span><?= e($label) ?><?= $required ? '' : ' <span class="text-muted small">(recommandé)</span>' ?></span>
                    <?= $ok ? '<span class="text-success"><i class="bi bi-check-circle-fill"></i> OK</span>'
                            : ($required ? '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> Manquant</span>'
                                         : '<span class="text-warning"><i class="bi bi-exclamation-circle-fill"></i> Absent</span>') ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($blocking): ?>
            <div class="card-body text-danger">Corrigez les éléments manquants (menu WampServer &gt; PHP &gt; Extensions PHP, droits d'écriture des dossiers) puis rechargez la page.</div>
        <?php endif; ?>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($notice): ?><div class="alert alert-success"><i class="bi bi-plug"></i> <?= e($notice) ?></div><?php endif; ?>
    <?php foreach ($warnings as $w): ?><div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle"></i> <?= e($w) ?></div><?php endforeach; ?>

    <?php if (!$blocking): ?>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="card mb-3">
            <div class="card-header">2. Connexion à la base créée dans phpMyAdmin</div>
            <div class="card-body row g-3">
                <div class="col-md-6"><label class="form-label" for="db_name">Nom de la base *</label>
                    <input class="form-control" id="db_name" name="db_name" value="<?= e($form['db_name']) ?>" required pattern="[A-Za-z0-9_]{1,64}"></div>
                <div class="col-md-6"><label class="form-label" for="db_user">Utilisateur MySQL *</label>
                    <input class="form-control" id="db_user" name="db_user" value="<?= e($form['db_user']) ?>" required placeholder="ex. niger_app"></div>
                <div class="col-md-6"><label class="form-label" for="db_pass">Mot de passe MySQL *</label>
                    <input class="form-control" id="db_pass" name="db_pass" type="password" required autocomplete="new-password">
                    <div class="form-text">Enregistré uniquement dans le fichier privé <code>config/database.local.php</code>, jamais affiché.</div></div>
                <div class="col-md-4"><label class="form-label" for="db_host">Hôte</label>
                    <input class="form-control" id="db_host" name="db_host" value="<?= e($form['db_host']) ?>" required></div>
                <div class="col-md-2"><label class="form-label" for="db_port">Port</label>
                    <input class="form-control" id="db_port" name="db_port" value="<?= e($form['db_port']) ?>" required>
                    <div class="form-text">MariaDB : souvent 3307</div></div>
                <div class="col-12"><button class="btn btn-outline-primary" name="action" value="test" formnovalidate><i class="bi bi-plug"></i> Tester la connexion</button></div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">3. Compte administrateur général</div>
            <div class="card-body row g-3">
                <div class="col-md-6"><label class="form-label" for="admin_username">Identifiant *</label>
                    <input class="form-control" id="admin_username" name="admin_username" value="<?= e($form['admin_username']) ?>"></div>
                <div class="col-md-6"><label class="form-label" for="admin_full_name">Nom complet *</label>
                    <input class="form-control" id="admin_full_name" name="admin_full_name" value="<?= e($form['admin_full_name']) ?>"></div>
                <div class="col-md-12"><label class="form-label" for="admin_email">E-mail (facultatif)</label>
                    <input class="form-control" id="admin_email" name="admin_email" type="email" value="<?= e($form['admin_email']) ?>"></div>
                <div class="col-md-6"><label class="form-label" for="admin_password">Mot de passe *</label>
                    <input class="form-control" id="admin_password" name="admin_password" type="password" minlength="10" autocomplete="new-password"></div>
                <div class="col-md-6"><label class="form-label" for="admin_password2">Confirmation *</label>
                    <input class="form-control" id="admin_password2" name="admin_password2" type="password" minlength="10" autocomplete="new-password"></div>
                <div class="col-12 form-text">10 caractères minimum avec lettres et chiffres, différent du mot de passe MySQL. Haché (bcrypt), jamais enregistré en clair.</div>
                <div class="col-12 form-check ms-2">
                    <input class="form-check-input" type="checkbox" id="demo" name="demo" value="1" <?= $form['demo'] === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="demo">Charger aussi les données de démonstration <strong>fictives</strong> (facultatif)</label>
                </div>
            </div>
        </div>
        <button class="btn btn-primary btn-lg" name="action" value="install"
                data-confirm-click="Installer Niger Parcelles dans la base indiquée ? Les tables seront créées automatiquement."><i class="bi bi-gear"></i> Installer</button>
    </form>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
