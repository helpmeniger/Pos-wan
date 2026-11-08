<?php
/** Boutiques, entrepôts et caisses : création, modification, désactivation (caisse jamais utilisée : suppression). */
$opt = function (array $list, $sel, string $empty = ''): string {
    $h = $empty !== '' ? '<option value="">' . e($empty) . '</option>' : '';
    foreach ($list as $x) {
        $h .= '<option value="' . (int) $x['id'] . '"' . ((int) $sel === (int) $x['id'] ? ' selected' : '') . '>' . e($x['name']) . ((int) ($x['active'] ?? 1) ? '' : ' (inactif)') . '</option>';
    }
    return $h;
};
$activeWh = array_values(array_filter($warehouses, fn ($w) => (int) $w['active'] === 1));
$editBtn = fn (string $id) => '<button type="button" class="btn btn-sm btn-outline-secondary py-0" data-bs-toggle="collapse" data-bs-target="#' . $id . '" aria-expanded="false" title="Modifier"><i class="bi bi-pencil"></i></button>';
$state = fn ($a) => (int) $a ? '' : ' <span class="badge text-bg-light border">inactif</span>';
?>
<p class="text-secondary small mb-3"><i class="bi bi-info-circle"></i> Une boutique contient des entrepôts (stock) et des caisses. Chaque caisse vend le stock de son entrepôt. Un élément qui a déjà servi n'est jamais effacé : il est <b>désactivé</b> (son historique est conservé).</p>
<div class="row g-3">
  <div class="col-lg-4"><div class="card h-100"><div class="card-header"><i class="bi bi-shop"></i> Boutiques</div>
    <table class="table table-sm align-middle mb-0"><tbody>
    <?php foreach ($stores as $s): $cid = 'st' . (int) $s['id']; ?>
      <tr class="<?= (int) $s['active'] ? '' : 'text-secondary' ?>"><td><b><?= e($s['code']) ?></b></td><td><?= e($s['name']) ?><?= $state($s['active']) ?><div class="small text-secondary"><?= e(trim(($s['address'] ?? '') . ' ' . ($s['phone'] ?? ''))) ?></div></td><td class="text-end"><?= $editBtn($cid) ?></td></tr>
      <tr class="collapse" id="<?= $cid ?>"><td colspan="3" class="bg-body-tertiary">
        <form method="post" action="<?= e(url('/stores/' . (int) $s['id'])) ?>" class="row g-1"><?= csrf_field() ?>
          <div class="col-8"><label class="form-label small mb-0">Nom</label><input name="name" class="form-control form-control-sm" value="<?= e($s['name']) ?>" required maxlength="150"></div>
          <div class="col-4"><label class="form-label small mb-0">Code</label><input name="code" class="form-control form-control-sm" value="<?= e($s['code']) ?>" required maxlength="20"></div>
          <div class="col-6"><input name="address" class="form-control form-control-sm" value="<?= e($s['address'] ?? '') ?>" placeholder="Adresse"></div>
          <div class="col-6"><input name="phone" class="form-control form-control-sm" value="<?= e($s['phone'] ?? '') ?>" placeholder="Téléphone"></div>
          <div class="col-12 form-check ms-1"><input type="checkbox" class="form-check-input" name="active" value="1" id="<?= $cid ?>a" <?= (int) $s['active'] ? 'checked' : '' ?>><label class="form-check-label small" for="<?= $cid ?>a">Active</label></div>
          <div class="col-12"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-check2"></i> Enregistrer</button></div>
        </form></td></tr>
    <?php endforeach; ?></tbody></table>
    <div class="card-body border-top"><form method="post" action="<?= e(url('/stores')) ?>" class="row g-1"><?= csrf_field() ?><div class="col-8"><input name="name" class="form-control form-control-sm" placeholder="Nom" required></div><div class="col-4"><input name="code" class="form-control form-control-sm" placeholder="Code (B02)" required maxlength="20"></div><div class="col-6"><input name="address" class="form-control form-control-sm" placeholder="Adresse"></div><div class="col-6"><input name="phone" class="form-control form-control-sm" placeholder="Téléphone"></div><div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Ajouter une boutique</button></div></form></div></div></div>

  <div class="col-lg-4"><div class="card h-100"><div class="card-header"><i class="bi bi-buildings"></i> Entrepôts / dépôts</div>
    <table class="table table-sm align-middle mb-0"><tbody>
    <?php foreach ($warehouses as $w): $cid = 'wh' . (int) $w['id']; ?>
      <tr class="<?= (int) $w['active'] ? '' : 'text-secondary' ?>"><td><b><?= e($w['code']) ?></b></td><td><?= e($w['name']) ?><?= $state($w['active']) ?><div class="small text-secondary"><?= e($w['store'] ?? 'Dépôt central') ?><?= (int) $w['is_sales_point'] === 1 ? ' · point de vente' : '' ?></div></td><td class="text-end"><?= $editBtn($cid) ?></td></tr>
      <tr class="collapse" id="<?= $cid ?>"><td colspan="3" class="bg-body-tertiary">
        <form method="post" action="<?= e(url('/warehouses/' . (int) $w['id'])) ?>" class="row g-1"><?= csrf_field() ?>
          <div class="col-8"><label class="form-label small mb-0">Nom</label><input name="name" class="form-control form-control-sm" value="<?= e($w['name']) ?>" required maxlength="150"></div>
          <div class="col-4"><label class="form-label small mb-0">Code</label><input name="code" class="form-control form-control-sm" value="<?= e($w['code']) ?>" required maxlength="20"></div>
          <div class="col-12"><select name="store_id" class="form-select form-select-sm"><?= $opt($stores, $w['store_id'], 'Dépôt central (sans boutique)') ?></select></div>
          <div class="col-6 form-check ms-1"><input type="checkbox" class="form-check-input" name="is_sales_point" value="1" id="<?= $cid ?>p" <?= (int) $w['is_sales_point'] ? 'checked' : '' ?>><label class="form-check-label small" for="<?= $cid ?>p">Point de vente</label></div>
          <div class="col-5 form-check"><input type="checkbox" class="form-check-input" name="active" value="1" id="<?= $cid ?>a" <?= (int) $w['active'] ? 'checked' : '' ?>><label class="form-check-label small" for="<?= $cid ?>a">Actif</label></div>
          <div class="col-12"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-check2"></i> Enregistrer</button></div>
        </form></td></tr>
    <?php endforeach; ?></tbody></table>
    <div class="card-body border-top"><form method="post" action="<?= e(url('/warehouses')) ?>" class="row g-1"><?= csrf_field() ?><div class="col-8"><input name="name" class="form-control form-control-sm" placeholder="Nom" required></div><div class="col-4"><input name="code" class="form-control form-control-sm" placeholder="Code (E02)" required maxlength="20"></div><div class="col-12"><select name="store_id" class="form-select form-select-sm"><option value="">Dépôt central (sans boutique)</option><?php foreach ($stores as $s): if ((int) $s['active']): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endif; endforeach; ?></select></div><div class="col-12 form-check ms-1"><input type="checkbox" class="form-check-input" name="is_sales_point" value="1" id="sp" checked><label class="form-check-label small" for="sp">Point de vente</label></div><div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Ajouter un entrepôt</button></div></form></div></div></div>

  <div class="col-lg-4"><div class="card h-100"><div class="card-header"><i class="bi bi-pc-display"></i> Caisses</div>
    <table class="table table-sm align-middle mb-0"><tbody>
    <?php foreach ($registers as $r): $cid = 'rg' . (int) $r['id']; ?>
      <tr class="<?= (int) $r['active'] ? '' : 'text-secondary' ?>"><td><?= e($r['name']) ?><?= $state($r['active']) ?><div class="small text-secondary"><?= e($r['store']) ?> · stock : <?= e($r['warehouse']) ?></div></td><td><?= (int) $r['open'] ? '<span class="badge bg-success">ouverte</span>' : '<span class="badge bg-secondary">fermée</span>' ?></td><td class="text-end"><?= $editBtn($cid) ?></td></tr>
      <tr class="collapse" id="<?= $cid ?>"><td colspan="3" class="bg-body-tertiary">
        <form method="post" action="<?= e(url('/registers/' . (int) $r['id'])) ?>" class="row g-1"><?= csrf_field() ?>
          <div class="col-12"><label class="form-label small mb-0">Nom</label><input name="name" class="form-control form-control-sm" value="<?= e($r['name']) ?>" required maxlength="100"></div>
          <div class="col-6"><label class="form-label small mb-0">Boutique</label><select name="store_id" class="form-select form-select-sm"><?= $opt($stores, $r['store_id']) ?></select></div>
          <div class="col-6"><label class="form-label small mb-0">Entrepôt (stock vendu)</label><select name="warehouse_id" class="form-select form-select-sm"><?= $opt($activeWh, $r['warehouse_id']) ?></select></div>
          <div class="col-12 form-check ms-1"><input type="checkbox" class="form-check-input" name="active" value="1" id="<?= $cid ?>a" <?= (int) $r['active'] ? 'checked' : '' ?>><label class="form-check-label small" for="<?= $cid ?>a">Active (proposée aux utilisateurs et imprimantes)</label></div>
          <?php if ((int) $r['open']): ?><div class="col-12 small text-warning-emphasis"><i class="bi bi-lock"></i> Caisse ouverte : seul le nom peut changer avant la clôture.</div><?php endif; ?>
          <div class="col-12"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-check2"></i> Enregistrer</button></div>
        </form>
        <form method="post" action="<?= e(url('/registers/' . (int) $r['id'] . '/delete')) ?>" class="mt-1" data-confirm="Supprimer la caisse « <?= e($r['name']) ?> » ? Possible seulement si elle n'a jamais été ouverte ni utilisée pour une vente."><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-trash"></i> Supprimer (caisse jamais utilisée)</button></form>
      </td></tr>
    <?php endforeach; ?></tbody></table>
    <div class="card-body border-top"><form method="post" action="<?= e(url('/registers')) ?>" class="row g-1"><?= csrf_field() ?><div class="col-12"><input name="name" class="form-control form-control-sm" placeholder="Nom (Caisse 2)" required></div><div class="col-6"><select name="store_id" class="form-select form-select-sm"><?php foreach ($stores as $s): if ((int) $s['active']): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endif; endforeach; ?></select></div><div class="col-6"><select name="warehouse_id" class="form-select form-select-sm"><?= $opt($activeWh, 0) ?></select></div><div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Ajouter une caisse</button></div></form></div></div></div>
</div>
