<?php $title = 'Rôles & permissions'; ?>
<div class="mc-page-head">
    <div>
        <h1>Rôles & <span class="mc-accent">permissions</span></h1>
        <p>Chaque rôle définit précisément ce que ses utilisateurs peuvent faire. Contrôle appliqué côté serveur.</p>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="mc-card">
            <div class="table-responsive">
                <table class="table mc-table table-hover">
                    <thead><tr><th>Rôle</th><th>Utilisateurs</th><th>Permissions</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($roles as $r): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e($r['name']) ?> <?php if ($r['is_system']): ?><span class="badge text-bg-secondary ms-1">Système</span><?php endif; ?></div>
                                <small class="text-muted"><?= e($r['description']) ?></small>
                            </td>
                            <td><?= (int) $r['users_count'] ?></td>
                            <td><?= $r['slug'] === 'super_admin' ? '<span class="mc-status ok">Toutes</span>' : (int) $r['perms_count'] ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/roles/' . $r['id'])) ?>"><i class="bi bi-sliders"></i> Permissions</a>
                                <?php if (!$r['is_system']): ?>
                                    <form class="d-inline" method="post" action="<?= e(url('/admin/roles/' . $r['id'])) ?>" data-confirm="Supprimer ce rôle ?">
                                        <?= csrf_field() ?><?= method_field('DELETE') ?>
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <form class="mc-card" method="post" action="<?= e(url('/admin/roles')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mc-card-head"><h2><i class="bi bi-plus-circle me-2 text-primary"></i>Nouveau rôle</h2></div>
            <div class="mc-card-body">
                <div class="mb-3">
                    <label class="form-label" for="name">Nom</label>
                    <input class="form-control<?= invalid('name') ?>" id="name" name="name" value="<?= e(old('name')) ?>" required>
                    <?= error_for('name') ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="slug">Code</label>
                    <input class="form-control<?= invalid('slug') ?>" id="slug" name="slug" value="<?= e(old('slug')) ?>" placeholder="ex. superviseur_site" required>
                    <?= error_for('slug') ?>
                    <div class="form-text">Minuscules, chiffres et _ uniquement.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="description">Description</label>
                    <input class="form-control" id="description" name="description" value="<?= e(old('description')) ?>">
                </div>
                <button class="btn btn-primary w-100" type="submit">Créer le rôle</button>
            </div>
        </form>
    </div>
</div>
