<?php use App\Core\Settings as S; $tab = ($_GET['tab'] ?? '') === 'permissions' ? 'permissions' : 'general'; ?>
<div class="page-header"><h1><i class="bi bi-sliders"></i> Paramètres</h1></div>
<ul class="nav nav-tabs">
    <li class="nav-item"><button class="nav-link<?= $tab === 'general' ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#t-general" type="button">Sécurité et général</button></li>
    <li class="nav-item"><button class="nav-link<?= $tab === 'permissions' ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#t-perms" type="button">Rôles et permissions</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-env" type="button">Environnement</button></li>
</ul>
<div class="card card-tabbed"><div class="card-body tab-content">
<div class="tab-pane fade<?= $tab === 'general' ? ' show active' : '' ?>" id="t-general">
<form method="post" action="<?= e(url('settings.update')) ?>" class="row g-3">
    <?= csrf_field() ?>
    <div class="col-md-6"><label class="form-label" for="institution_name">Nom du service</label>
        <input class="form-control" id="institution_name" name="institution_name" value="<?= e($settings['institution_name']) ?>" maxlength="150"></div>
    <div class="w-100"></div>
    <?php foreach ([
        'session_idle_minutes' => 'Déconnexion après inactivité (minutes)',
        'session_max_hours' => 'Durée maximale d\'une session (heures)',
        'login_max_attempts' => 'Tentatives de connexion avant verrouillage',
        'lockout_minutes' => 'Durée du verrouillage (minutes)',
        'account_inactivity_days' => 'Désactivation des comptes inactifs (jours)',
        'password_min_length' => 'Longueur minimale des mots de passe',
    ] as $k => $l): [$min, $max] = S::BOUNDS[$k]; ?>
        <div class="col-md-4"><label class="form-label" for="<?= e($k) ?>"><?= e($l) ?></label>
            <input class="form-control" type="number" id="<?= e($k) ?>" name="<?= e($k) ?>" min="<?= $min ?>" max="<?= $max ?>" value="<?= e($settings[$k]) ?>" required>
            <div class="form-text">Entre <?= $min ?> et <?= $max ?>.</div></div>
    <?php endforeach; ?>
    <div class="col-12"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
</form>
</div>

<div class="tab-pane fade<?= $tab === 'permissions' ? ' show active' : '' ?>" id="t-perms">
<p class="small">Principe du moindre privilège : n'accordez que les permissions nécessaires aux missions de chaque rôle.
    Toute modification est journalisée et signalée au responsable de contrôle. Certaines permissions essentielles sont verrouillées.</p>
<form method="post" action="<?= e(url('settings.permissions')) ?>" data-confirm="Enregistrer les modifications de la matrice des permissions ?">
    <?= csrf_field() ?>
    <div class="table-responsive"><table class="table table-sm table-bordered align-middle perm-matrix">
        <thead><tr><th>Permission</th><?php foreach ($roles as $r): ?><th class="text-center"><?= e($r['label']) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($perms as $p): ?>
            <tr><td><span class="small"><?= e($p['label']) ?></span><br><code class="small"><?= e($p['code']) ?></code></td>
            <?php foreach ($roles as $r):
                $locked = ($r['code'] === 'admin' && in_array($p['code'], ['users.manage', 'settings.manage'], true)) || ($r['code'] === 'controleur' && $p['code'] === 'audit.view'); ?>
                <td class="text-center"><input class="form-check-input" type="checkbox" name="perm[<?= (int) $r['id'] ?>][<?= (int) $p['id'] ?>]" value="1"
                    <?= checked(isset($matrix[$r['id']][$p['id']])) ?><?= $locked ? ' disabled' : '' ?> aria-label="<?= e($r['label'] . ' : ' . $p['label']) ?>"></td>
            <?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <button class="btn btn-primary" type="submit">Enregistrer la matrice</button>
</form>
</div>

<div class="tab-pane fade" id="t-env">
    <dl class="row small mb-0">
        <dt class="col-sm-4">PHP</dt><dd class="col-sm-8"><?= e($env['php']) ?></dd>
        <dt class="col-sm-4">Base de données</dt><dd class="col-sm-8"><?= e($env['db']) ?></dd>
        <dt class="col-sm-4">Hachage des mots de passe</dt><dd class="col-sm-8"><?= $env['argon2'] ? 'Argon2id' : 'bcrypt' ?></dd>
        <dt class="col-sm-4">HTTPS</dt><dd class="col-sm-8"><?= $env['https'] ? 'actif' : '<span class="text-warning">inactif — recommandé sur un réseau local partagé</span>' ?></dd>
        <dt class="col-sm-4">Mode</dt><dd class="col-sm-8"><?= \App\Core\Config::isDemo() ? '<span class="text-warning">démonstration</span>' : 'production' ?></dd>
    </dl>
    <p class="small text-muted mt-3">Le contrôle complet de l'environnement s'effectue avec <code>php scripts/check_env.php</code>.</p>
</div>
</div></div>
