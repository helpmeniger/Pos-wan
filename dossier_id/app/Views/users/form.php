<div class="page-header"><h1><i class="bi bi-person-gear"></i> <?= e($title) ?></h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('users.index')) ?>">Retour</a></div>

<?php if ($temp): ?>
<div class="alert alert-warning">
    <strong>Mot de passe temporaire pour « <?= e($temp['user']) ?> » :</strong>
    <code class="fs-5 user-select-all"><?= e($temp['password']) ?></code><br>
    <span class="small">Il ne sera plus jamais affiché. Transmettez-le à l'agent par un moyen sûr ; il devra le changer à la première connexion.</span>
</div>
<?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post" action="<?= e($id ? url('users.update', ['id' => $id]) : url('users.store')) ?>" autocomplete="off" novalidate>
<?= csrf_field() ?>
<div class="card mb-3"><div class="card-body row g-3">
    <div class="col-md-4"><label class="form-label required" for="username">Identifiant</label>
        <input class="form-control<?= invalid($errors, 'username') ?>" id="username" name="username" value="<?= old($u, 'username') ?>" <?= $id ? 'disabled' : 'required' ?> maxlength="60" autocapitalize="none"></div>
    <div class="col-md-8"><label class="form-label required" for="full_name">Nom complet</label>
        <input class="form-control<?= invalid($errors, 'full_name') ?>" id="full_name" name="full_name" value="<?= old($u, 'full_name') ?>" required maxlength="150"></div>
    <div class="col-md-4"><label class="form-label" for="email">Courriel</label>
        <input class="form-control<?= invalid($errors, 'email') ?>" type="email" id="email" name="email" value="<?= old($u, 'email') ?>" maxlength="150"></div>
    <div class="col-md-4"><label class="form-label" for="service">Service</label>
        <input class="form-control" id="service" name="service" value="<?= old($u, 'service') ?>" maxlength="120"></div>
    <div class="col-md-4"><label class="form-label required" for="role_id">Rôle</label>
        <select class="form-select<?= invalid($errors, 'role_id') ?>" id="role_id" name="role_id" required>
            <option value="">— Choisir —</option>
            <?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>"<?= selected($u['role_id'] ?? '', $r['id']) ?> title="<?= e($r['description']) ?>"><?= e($r['label']) ?></option><?php endforeach; ?>
        </select></div>
    <div class="col-12 small text-muted">
        <?php foreach ($roles as $r): ?><div><strong><?= e($r['label']) ?></strong> : <?= e($r['description']) ?></div><?php endforeach; ?>
    </div>
</div></div>
<div class="d-flex gap-2">
    <button class="btn btn-primary" type="submit"><?= $id ? 'Enregistrer' : 'Créer le compte' ?></button>
</div>
</form>

<?php if ($id && $id !== \App\Core\Auth::id()): ?>
<hr>
<form method="post" action="<?= e(url('users.reset', ['id' => $id])) ?>" data-confirm="Générer un nouveau mot de passe temporaire ? L'ancien ne fonctionnera plus.">
    <?= csrf_field() ?>
    <button class="btn btn-outline-warning" type="submit"><i class="bi bi-key"></i> Réinitialiser le mot de passe / déverrouiller</button>
</form>
<?php endif; ?>
