<form method="post" action="<?= e(url('/roles/' . $role['id'])) ?>"><?= csrf_field() ?>
<div class="card mb-3"><div class="card-body d-flex gap-2 align-items-center"><input name="name" value="<?= e($role['name']) ?>" class="form-control" style="max-width:340px" maxlength="100"><?php if ($role['code'] === 'admin'): ?><span class="text-secondary small">L'administrateur général dispose toujours de toutes les permissions.</span><?php else: ?><button class="btn btn-primary ms-auto"><i class="bi bi-check2"></i> Enregistrer</button><?php endif; ?></div></div>
<div class="row g-3">
<?php foreach ($perms as $module => $list): ?>
  <div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-header"><?= e($module) ?></div><div class="card-body">
    <?php foreach ($list as $p): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="perms[]" value="<?= e($p['code']) ?>" id="p<?= (int) $p['id'] ?>" <?= $role['code'] === 'admin' || isset($granted[$p['code']]) ? 'checked' : '' ?> <?= $role['code'] === 'admin' ? 'disabled' : '' ?>><label class="form-check-label small" for="p<?= (int) $p['id'] ?>"><?= e($p['label']) ?> <code class="text-secondary"><?= e($p['code']) ?></code></label></div><?php endforeach; ?>
  </div></div></div>
<?php endforeach; ?>
</div>
</form>
