<?php
/**
 * Installateur web sécurisé de Niger Parcelles.
 *
 * - Vérifie les prérequis et la connexion à la base.
 * - Crée les tables UNIQUEMENT après confirmation explicite.
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
$checks[] = ['Dossier config/ accessible en écriture (sinon créez config/database.local.php à la main)', is_writable(__DIR__ . '/config'), false];
$blocking = array_filter($checks, static fn ($c) => $c[2] && !$c[1]);

$defaults = require __DIR__ . '/config/database.php';
$form = [
    'db_host' => $defaults['host'], 'db_port' => (string) $defaults['port'], 'db_name' => $defaults['database'],
    'db_user' => $defaults['username'], 'mode' => 'create', 'admin_username' => 'admin', 'admin_full_name' => '',
    'admin_email' => '', 'demo' => '', 'create_db' => '1',
];
$errors = [];
$done = false;

if (request_method() === 'POST' && !$blocking) {
    require_csrf();
    foreach ($form as $k => $v) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $adminPass = (string) ($_POST['admin_password'] ?? '');
    $adminPass2 = (string) ($_POST['admin_password2'] ?? '');

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
    }
    if (!in_array($form['mode'], ['create', 'existing'], true)) {
        $errors[] = 'Mode d\'installation invalide.';
    }
    if ($form['mode'] === 'create' && empty($_POST['confirm_create'])) {
        $errors[] = 'Cochez la case de confirmation pour autoriser la création des tables.';
    }

    if (!$errors) {
        $dbc = ['host' => $form['db_host'], 'port' => (int) $port, 'database' => $form['db_name'],
                'username' => $form['db_user'], 'password' => $dbPass, 'charset' => 'utf8mb4'];
        try {
            $server = db_connect($dbc, false);
            if ($form['create_db'] === '1') {
                $server->exec('CREATE DATABASE IF NOT EXISTS `' . $form['db_name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            }
            $pdo = db_connect($dbc);
        } catch (PDOException $e) {
            log_error($e);
            $errors[] = 'Connexion à MySQL impossible : vérifiez que MySQL/MariaDB est démarré dans WampServer, '
                . 'ainsi que l\'hôte, le port (3306 ou 3307), l\'utilisateur, le mot de passe et le nom de la base.';
        }
    }

    if (!$errors) {
        try {
            $tables = $pdo->query("SHOW TABLES LIKE 'users'")->fetchAll();
            if ($form['mode'] === 'create') {
                if ($tables && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0 && empty($_POST['confirm_overwrite'])) {
                    $errors[] = 'La base contient déjà des utilisateurs Niger Parcelles. Cochez « J\'accepte d\'effacer les données existantes » '
                        . 'pour recréer les tables, ou choisissez le mode « Tables déjà importées ».';
                } else {
                    SqlRunner::runFile($pdo, __DIR__ . '/database/schema.sql');
                    if ($form['demo'] === '1') {
                        SqlRunner::runFile($pdo, __DIR__ . '/database/demo_data.sql');
                    }
                }
            } elseif (!$tables) {
                $errors[] = 'Les tables sont introuvables : importez d\'abord database/schema.sql dans phpMyAdmin ou choisissez le mode « Créer les tables ».';
            } elseif ((int) $pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin_general'")->fetchColumn() > 0) {
                $errors[] = 'Un administrateur général existe déjà dans cette base. L\'installateur ne peut pas en créer un second.';
            }

            if (!$errors) {
                $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code = 'admin_general'")->fetchColumn();
                $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, email, role_id, is_active) VALUES (?, ?, ?, ?, ?, 1)');
                $stmt->execute([$form['admin_username'], password_hash($adminPass, PASSWORD_DEFAULT), mb_substr($form['admin_full_name'], 0, 150),
                                $form['admin_email'] !== '' ? $form['admin_email'] : null, $roleId]);
                $adminId = (int) $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, 'installation', 'user', ?, ?, ?)")
                    ->execute([$adminId, $adminId, json_encode(['demo' => $form['demo'] === '1', 'mode' => $form['mode']]), client_ip()]);

                // Paramètres de connexion locaux (fichier non versionné, protégé par .htaccess)
                $local = "<?php\n// Généré par install.php le " . date('Y-m-d H:i') . ". Ne pas versionner.\nreturn "
                    . var_export(['host' => $dbc['host'], 'port' => $dbc['port'], 'database' => $dbc['database'],
                                  'username' => $dbc['username'], 'password' => $dbc['password']], true) . ";\n";
                $configWritten = @file_put_contents(__DIR__ . '/config/database.local.php', $local, LOCK_EX) !== false;
                file_put_contents(storage_path('installed.lock'), 'Installé le ' . date('c') . "\n", LOCK_EX);
                $done = true;
            }
        } catch (Throwable $e) {
            log_error($e);
            $errors[] = 'Erreur lors de la création des tables. Consultez storage/logs/app.log pour le détail technique.';
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
        <p class="mb-1">Les tables ont été configurées et le compte administrateur « <strong><?= e($form['admin_username']) ?></strong> » a été créé.</p>
        <?php if (empty($configWritten)): ?>
            <p class="text-danger mb-1">Le fichier config/database.local.php n'a pas pu être écrit : créez-le à partir de config/database.local.example.php.</p>
        <?php endif; ?>
        <?php if ($form['demo'] === '1'): ?>
            <p class="mb-1">Des <strong>données de démonstration FICTIVES</strong> ont été chargées. Elles sont signalées comme telles dans l'application.</p>
        <?php endif; ?>
        <p class="mb-2">L'installateur est désormais verrouillé.</p>
        <a class="btn btn-primary" href="<?= e(url('login.php')) ?>">Se connecter</a>
    </div>
<?php else: ?>
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
            <div class="card-body text-danger">Corrigez les éléments manquants (menu WampServer &gt; PHP &gt; Extensions PHP) puis rechargez la page.</div>
        <?php endif; ?>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?php if (!$blocking): ?>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="card mb-3">
            <div class="card-header">2. Connexion à MySQL / MariaDB</div>
            <div class="card-body row g-3">
                <div class="col-md-6"><label class="form-label" for="db_host">Hôte</label>
                    <input class="form-control" id="db_host" name="db_host" value="<?= e($form['db_host']) ?>" required></div>
                <div class="col-md-2"><label class="form-label" for="db_port">Port</label>
                    <input class="form-control" id="db_port" name="db_port" value="<?= e($form['db_port']) ?>" required></div>
                <div class="col-md-4"><label class="form-label" for="db_name">Nom de la base</label>
                    <input class="form-control" id="db_name" name="db_name" value="<?= e($form['db_name']) ?>" required></div>
                <div class="col-md-6"><label class="form-label" for="db_user">Utilisateur</label>
                    <input class="form-control" id="db_user" name="db_user" value="<?= e($form['db_user']) ?>" required></div>
                <div class="col-md-6"><label class="form-label" for="db_pass">Mot de passe MySQL</label>
                    <input class="form-control" id="db_pass" name="db_pass" type="password" autocomplete="new-password">
                    <div class="form-text">Vide par défaut sous WampServer (utilisateur root).</div></div>
                <div class="col-12 form-check ms-2">
                    <input class="form-check-input" type="checkbox" id="create_db" name="create_db" value="1" <?= $form['create_db'] === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="create_db">Créer la base si elle n'existe pas (utf8mb4)</label>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">3. Tables</div>
            <div class="card-body">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mode" id="mode_create" value="create" <?= $form['mode'] === 'create' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mode_create">Créer les tables à partir de <code>database/schema.sql</code></label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="mode" id="mode_existing" value="existing" <?= $form['mode'] === 'existing' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mode_existing">Les tables ont déjà été importées avec phpMyAdmin (créer uniquement l'administrateur)</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="demo" name="demo" value="1" <?= $form['demo'] === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="demo">Charger les données de démonstration <strong>fictives</strong> (Niamey)</label>
                </div>
                <hr>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="confirm_create" name="confirm_create" value="1">
                    <label class="form-check-label" for="confirm_create"><strong>Je confirme la création des tables</strong> dans la base indiquée.</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="confirm_overwrite" name="confirm_overwrite" value="1">
                    <label class="form-check-label text-danger" for="confirm_overwrite">J'accepte d'effacer les données Niger Parcelles existantes (si la base en contient).</label>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">4. Compte administrateur général</div>
            <div class="card-body row g-3">
                <div class="col-md-6"><label class="form-label" for="admin_username">Identifiant</label>
                    <input class="form-control" id="admin_username" name="admin_username" value="<?= e($form['admin_username']) ?>" required></div>
                <div class="col-md-6"><label class="form-label" for="admin_full_name">Nom complet</label>
                    <input class="form-control" id="admin_full_name" name="admin_full_name" value="<?= e($form['admin_full_name']) ?>" required></div>
                <div class="col-md-12"><label class="form-label" for="admin_email">E-mail (facultatif)</label>
                    <input class="form-control" id="admin_email" name="admin_email" type="email" value="<?= e($form['admin_email']) ?>"></div>
                <div class="col-md-6"><label class="form-label" for="admin_password">Mot de passe</label>
                    <input class="form-control" id="admin_password" name="admin_password" type="password" required minlength="10" autocomplete="new-password"></div>
                <div class="col-md-6"><label class="form-label" for="admin_password2">Confirmation</label>
                    <input class="form-control" id="admin_password2" name="admin_password2" type="password" required minlength="10" autocomplete="new-password"></div>
                <div class="col-12 form-text">Au moins 10 caractères avec lettres et chiffres. Il est haché (bcrypt) et n'est jamais enregistré en clair.</div>
            </div>
        </div>
        <button class="btn btn-primary btn-lg"><i class="bi bi-gear"></i> Installer</button>
    </form>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
