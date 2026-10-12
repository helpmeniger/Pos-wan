<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Verifia\Auth;
use Verifia\Csrf;
use Verifia\DataPurge;
use Verifia\Database;
use Verifia\EventLog;
use Verifia\Settings;
use Verifia\View;

$admin = Auth::require('admin');
$flash = null;
$error = null;
$editable = [
    'max_concurrent_sessions' => ['int', 1, 50], 'session_memory_mb' => ['int', 100, 8000], 'memory_reserve_mb' => ['int', 0, 64000],
    'alert_min_priority' => ['enum', ['low', 'normal', 'high', 'critical']], 'image_dhash_threshold' => ['int', 0, 20],
    'text_similarity_threshold' => ['float', 0.2, 1.0], 'tesseract_path' => ['path'], 'retention_days' => ['int', 1, 3650],
    'store_thumbnails' => ['enum', ['0', '1']], 'session_previews' => ['enum', ['0', '1']],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyRequest();
    try {
        switch ($_POST['op'] ?? '') {
            case 'settings':
                foreach ($editable as $k => $rule) {
                    if (!isset($_POST[$k])) {
                        continue;
                    }
                    $v = trim((string) $_POST[$k]);
                    $v = match ($rule[0]) {
                        'int' => (string) max($rule[1], min($rule[2], (int) $v)),
                        'float' => (string) max($rule[1], min($rule[2], (float) $v)),
                        'enum' => in_array($v, $rule[1], true) ? $v : throw new InvalidArgumentException("Valeur invalide pour $k"),
                        'path' => ($v === '' || preg_match('/tesseract(\.exe)?$/i', $v)) ? $v : throw new InvalidArgumentException('Le chemin doit désigner tesseract.exe'),
                    };
                    Settings::set($k, $v);
                }
                EventLog::audit($admin['id'], 'settings_update');
                $flash = 'Paramètres enregistrés.';
                break;
            case 'user_create':
                Auth::createUser(trim($_POST['username'] ?? ''), trim($_POST['display_name'] ?? '') ?: trim($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['role'] ?? 'viewer'));
                EventLog::audit($admin['id'], 'user_create', 'user', null, ['username' => $_POST['username'] ?? '']);
                $flash = 'Utilisateur créé.';
                break;
            case 'user_update':
                $uid = (int) $_POST['user_id'];
                if ($uid === $admin['id'] && (($_POST['is_active'] ?? '1') !== '1' || ($_POST['role'] ?? 'admin') !== 'admin')) {
                    throw new InvalidArgumentException('Vous ne pouvez pas désactiver ou rétrograder votre propre compte.');
                }
                $roleId = Database::value('SELECT id FROM roles WHERE code = ?', [$_POST['role'] ?? '']);
                if (!$roleId) {
                    throw new InvalidArgumentException('Rôle inconnu.');
                }
                Database::run('UPDATE users SET role_id = ?, is_active = ? WHERE id = ?', [$roleId, ($_POST['is_active'] ?? '0') === '1' ? 1 : 0, $uid]);
                if (!empty($_POST['password'])) {
                    if (mb_strlen($_POST['password']) < 10) {
                        throw new InvalidArgumentException('Mot de passe : 10 caractères minimum.');
                    }
                    Database::run('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?', [password_hash($_POST['password'], PASSWORD_DEFAULT), $uid]);
                }
                EventLog::audit($admin['id'], 'user_update', 'user', $uid);
                $flash = 'Utilisateur mis à jour.';
                break;
            case 'site_update':
                Database::run('UPDATE monitored_sites SET name = ?, automation_allowed = ?, is_satire = ?, notes = ? WHERE id = ?', [
                    mb_substr(trim($_POST['name'] ?? ''), 0, 120) ?: 'site', isset($_POST['automation_allowed']) ? 1 : 0, isset($_POST['is_satire']) ? 1 : 0,
                    mb_substr((string) ($_POST['notes'] ?? ''), 0, 2000), (int) $_POST['site_id'],
                ]);
                EventLog::audit($admin['id'], 'site_update', 'site', (int) $_POST['site_id']);
                $flash = 'Site mis à jour.';
                break;
            case 'purge_expired':
                $flash = DataPurge::expired() . ' publication(s) expirée(s) supprimée(s).';
                break;
            case 'wipe':
                if (($_POST['confirm'] ?? '') !== 'SUPPRIMER') {
                    throw new InvalidArgumentException('Tapez SUPPRIMER pour confirmer.');
                }
                \Verifia\Sessions\SessionService::emergencyStop($admin['id']);
                DataPurge::everything($admin['id']);
                $flash = 'Toutes les données collectées ont été supprimées (les comptes utilisateurs et paramètres sont conservés). L\'arrêt immédiat reste actif.';
                break;
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}
$settings = [];
foreach (Settings::all() as $s) {
    $settings[$s['setting_key']] = $s;
}
$users = Database::all('SELECT u.*, r.code AS role FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.username');
$sites = Database::all('SELECT * FROM monitored_sites ORDER BY domain');
View::header('Administration', 'settings');
?>
<h1 class="h4">Administration</h1>
<?php if ($flash): ?><div class="alert alert-success"><?= e($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="row g-3">
<div class="col-lg-6"><div class="card"><div class="card-header">Paramètres</div><div class="card-body">
<form method="post" class="small"><?= Csrf::field() ?><input type="hidden" name="op" value="settings">
<?php foreach ($editable as $k => $rule): $cur = $settings[$k]['setting_value'] ?? ''; ?>
  <div class="mb-2"><label class="form-label mb-0"><code><?= e($k) ?></code> — <?= e($settings[$k]['description'] ?? '') ?></label>
  <?php if ($rule[0] === 'enum'): ?><select class="form-select form-select-sm" name="<?= e($k) ?>"><?php foreach ($rule[1] as $o): ?><option <?= $o === $cur ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select>
  <?php else: ?><input class="form-control form-control-sm" name="<?= e($k) ?>" value="<?= e($cur) ?>"><?php endif; ?></div>
<?php endforeach; ?>
<button class="btn btn-primary btn-sm">Enregistrer</button></form>
</div></div></div>

<div class="col-lg-6">
<div class="card mb-3"><div class="card-header">Utilisateurs et rôles</div><div class="card-body small">
<p class="text-body-secondary">admin : tout ; analyst : sessions, alertes, vérification ; viewer : consultation seule.</p>
<?php foreach ($users as $u): ?>
<form method="post" class="row g-1 align-items-center mb-1"><?= Csrf::field() ?><input type="hidden" name="op" value="user_update"><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
  <div class="col-3"><b><?= e($u['username']) ?></b><br><span class="text-body-secondary"><?= e($u['display_name']) ?></span></div>
  <div class="col-3"><select class="form-select form-select-sm" name="role"><?php foreach (['admin', 'analyst', 'viewer'] as $r): ?><option <?= $r === $u['role'] ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select></div>
  <div class="col-2"><select class="form-select form-select-sm" name="is_active"><option value="1" <?= $u['is_active'] ? 'selected' : '' ?>>actif</option><option value="0" <?= $u['is_active'] ? '' : 'selected' ?>>inactif</option></select></div>
  <div class="col-3"><input class="form-control form-control-sm" type="password" name="password" placeholder="nouveau mot de passe" autocomplete="new-password"></div>
  <div class="col-1"><button class="btn btn-sm btn-outline-primary">OK</button></div>
</form>
<?php endforeach; ?>
<hr><form method="post" class="row g-1"><?= Csrf::field() ?><input type="hidden" name="op" value="user_create">
  <div class="col-3"><input class="form-control form-control-sm" name="username" placeholder="identifiant" required></div>
  <div class="col-3"><input class="form-control form-control-sm" name="display_name" placeholder="nom affiché"></div>
  <div class="col-2"><select class="form-select form-select-sm" name="role"><option>analyst</option><option>viewer</option><option>admin</option></select></div>
  <div class="col-3"><input class="form-control form-control-sm" type="password" name="password" placeholder="mot de passe (10+)" minlength="10" required autocomplete="new-password"></div>
  <div class="col-1"><button class="btn btn-sm btn-primary">+</button></div>
</form></div></div>

<div class="card mb-3"><div class="card-header">Sites surveillés</div><div class="card-body small">
<p class="text-body-secondary">Décochez « automatisation autorisée » pour un site dont les conditions d'utilisation interdisent le défilement automatique : ses sessions ne feront alors que des analyses manuelles des publications visibles (bouton « Analyser maintenant »).</p>
<?php foreach ($sites as $s): ?>
<form method="post" class="row g-1 align-items-center mb-1"><?= Csrf::field() ?><input type="hidden" name="op" value="site_update"><input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
  <div class="col-3"><code><?= e($s['domain']) ?></code><br><span class="text-body-secondary">adaptateur : <?= e($s['adapter']) ?></span></div>
  <div class="col-3"><input class="form-control form-control-sm" name="name" value="<?= e($s['name']) ?>"></div>
  <div class="col-2 form-check"><input class="form-check-input" type="checkbox" name="automation_allowed" id="aa<?= (int) $s['id'] ?>" <?= $s['automation_allowed'] ? 'checked' : '' ?>><label class="form-check-label" for="aa<?= (int) $s['id'] ?>">automatisation</label></div>
  <div class="col-2 form-check"><input class="form-check-input" type="checkbox" name="is_satire" id="sa<?= (int) $s['id'] ?>" <?= $s['is_satire'] ? 'checked' : '' ?>><label class="form-check-label" for="sa<?= (int) $s['id'] ?>">satirique</label></div>
  <input type="hidden" name="notes" value="<?= e($s['notes']) ?>">
  <div class="col-2"><button class="btn btn-sm btn-outline-primary">OK</button></div>
</form>
<?php endforeach; ?>
<?php if (!$sites): ?><p>Les sites apparaissent automatiquement à la création des sessions.</p><?php endif; ?>
</div></div>

<div class="card border-danger"><div class="card-header text-danger">Données</div><div class="card-body small">
<form method="post" class="mb-3"><?= Csrf::field() ?><input type="hidden" name="op" value="purge_expired">
  <button class="btn btn-sm btn-outline-warning">Supprimer les publications non signalées expirées (> <?= (int) ($settings['retention_days']['setting_value'] ?? 90) ?> jours)</button></form>
<form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="wipe">
  <p>Effacement complet : sessions, publications, alertes, rapports, images, profils de navigateur (y compris les connexions faites dans les fenêtres surveillées). Irréversible.</p>
  <div class="input-group input-group-sm"><input class="form-control" name="confirm" placeholder="Tapez SUPPRIMER"><button class="btn btn-danger">Tout supprimer</button></div>
</form></div></div>
</div></div>
<?php View::footer();
