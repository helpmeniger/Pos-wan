<div class="d-flex justify-content-end mb-2"><a href="<?= url('users/create') ?>" class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Nouvel utilisateur</a></div>
<div class="card mb-4"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
<thead><tr><th>Nom</th><th>Identifiant</th><th>Rôles</th><th>Magasins</th><th>Dernière connexion</th><th>Statut</th></tr></thead><tbody>
<?php foreach ($users as $u): ?><tr><td><a href="<?= url('users/' . (int) $u['id'] . '/edit') ?>"><?= e($u['full_name']) ?></a></td><td><code><?= e($u['username']) ?></code></td>
  <td class="small"><?= e($u['roles']) ?></td><td class="small"><?= e($u['stores']) ?></td><td class="small"><?= dt($u['last_login_at']) ?> <?= e($u['last_login_ip']) ?></td>
  <td><?= $u['is_active'] ? '<span class="badge bg-success">actif</span>' : '<span class="badge bg-secondary">désactivé</span>' ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>

<h2 class="h6">Droits par rôle</h2>
<form method="post" action="<?= url('users/roles') ?>"><?= csrf_field() ?>
<div class="card mb-4"><div class="table-responsive"><table class="table table-sm mb-0">
<thead><tr><th>Permission</th><?php foreach ($roles as $r): ?><th class="text-center"><?= e($r['name']) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($perms as $p): ?><tr><td class="small"><?= e($p['description']) ?> <code class="text-muted"><?= e($p['code']) ?></code></td>
  <?php foreach ($roles as $r): $on = !empty($matrix[$r['id']][$p['id']]); ?><td class="text-center"><input type="checkbox" class="form-check-input" name="perm[<?= (int) $r['id'] ?>][<?= (int) $p['id'] ?>]" value="1" <?= $on ? 'checked' : '' ?> <?= $r['code'] === 'admin' ? 'disabled' : '' ?>></td><?php endforeach; ?></tr>
<?php endforeach; ?></tbody></table></div>
<div class="card-footer bg-white"><button class="btn btn-primary btn-sm">Enregistrer les droits</button> <span class="small text-muted ms-2">Les droits sont vérifiés côté serveur à chaque requête.</span></div></div></form>

<?php if (can('stores.manage')): ?>
<h2 class="h6">Magasins (installation multi-sites)</h2>
<div class="card"><div class="card-body">
<?php foreach (array_merge($stores, [['id' => '', 'code' => '', 'name' => '', 'city' => '', 'address' => '', 'phone' => '', 'is_active' => 1]]) as $s): ?>
<form method="post" action="<?= url('stores') ?>" class="row g-2 mb-2 align-items-center"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($s['id']) ?>">
  <div class="col-md-2"><input name="code" class="form-control form-control-sm" placeholder="Code" value="<?= e($s['code']) ?>" required></div>
  <div class="col-md-3"><input name="name" class="form-control form-control-sm" placeholder="Nom du magasin" value="<?= e($s['name']) ?>" required></div>
  <div class="col-md-2"><input name="city" class="form-control form-control-sm" placeholder="Ville" value="<?= e($s['city']) ?>"></div>
  <div class="col-md-2"><input name="phone" class="form-control form-control-sm" placeholder="Téléphone" value="<?= e($s['phone']) ?>"></div>
  <div class="col-md-1"><div class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" <?= $s['is_active'] ? 'checked' : '' ?>><label class="form-check-label small">Actif</label></div></div>
  <div class="col-md-2"><button class="btn btn-sm <?= $s['id'] ? 'btn-outline-primary' : 'btn-primary' ?> w-100"><?= $s['id'] ? 'Enregistrer' : 'Ajouter' ?></button></div>
</form>
<?php endforeach; ?></div></div>
<?php endif; ?>
