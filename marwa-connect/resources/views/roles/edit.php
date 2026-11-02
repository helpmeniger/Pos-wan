<?php
$title = 'Rôle : ' . $role['name'];
$locked = $role['slug'] === 'super_admin';
?>
<div class="mc-page-head">
    <div>
        <h1><?= e($role['name']) ?></h1>
        <p><?= $locked ? 'Le super administrateur dispose en permanence de toutes les permissions.' : 'Cochez les actions autorisées pour ce rôle.' ?></p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/roles')) ?>"><i class="bi bi-arrow-left"></i> Retour</a>
</div>
<form method="post" action="<?= e(url('/admin/roles/' . $role['id'])) ?>">
    <?= csrf_field() ?><?= method_field('PUT') ?>
    <div class="mc-card mb-3">
        <div class="mc-card-body row g-3">
            <div class="col-md-5">
                <label class="form-label" for="name">Nom du rôle</label>
                <input class="form-control<?= invalid('name') ?>" id="name" name="name" value="<?= e(old('name', $role['name'])) ?>" <?= $locked ? 'disabled' : '' ?> required>
                <?= error_for('name') ?>
            </div>
            <div class="col-md-7">
                <label class="form-label" for="description">Description</label>
                <input class="form-control" id="description" name="description" value="<?= e(old('description', $role['description'])) ?>" <?= $locked ? 'disabled' : '' ?>>
            </div>
        </div>
    </div>
    <div class="row g-3">
        <?php $i = 0; foreach ($modules as $module => $perms): $i++; ?>
            <div class="col-md-6 col-xl-4">
                <div class="mc-perm-module mc-card">
                    <h3>
                        <span><?= e($module) ?></span>
                        <input class="form-check-input" type="checkbox" data-check-all="m<?= $i ?>" aria-label="Tout cocher : <?= e($module) ?>" <?= $locked ? 'checked disabled' : '' ?>>
                    </h3>
                    <?php foreach ($perms as $p): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= (int) $p['id'] ?>" id="p<?= (int) $p['id'] ?>" data-check-group="m<?= $i ?>"
                                <?= $locked || in_array((int) $p['id'], $granted, true) ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
                            <label class="form-check-label small" for="p<?= (int) $p['id'] ?>"><?= e($p['name']) ?> <code class="mc-xs text-muted"><?= e($p['slug']) ?></code></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (!$locked): ?>
        <div class="d-flex justify-content-end mt-3">
            <button class="btn btn-primary px-4" type="submit"><i class="bi bi-check2 me-1"></i> Enregistrer les permissions</button>
        </div>
    <?php endif; ?>
</form>
