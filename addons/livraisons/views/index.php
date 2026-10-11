<?php use Addons\Livraisons\LivraisonService as LS; $colors = ['a_livrer' => 'warning text-dark', 'en_route' => 'info text-dark', 'livree' => 'success', 'annulee' => 'secondary']; ?>
<link rel="stylesheet" href="<?= e(asset('addons/livraisons/livraisons.css')) ?>">
<div class="row g-3">
  <?php if (can('livraisons.manage')): ?>
  <div class="col-lg-4"><div class="card"><div class="card-header"><i class="bi bi-truck"></i> Nouvelle livraison</div><div class="card-body">
    <form method="post" action="<?= e(url('/livraisons')) ?>" class="row g-2"><?= csrf_field() ?>
      <div class="col-12"><label class="form-label required">Client</label><input name="customer_name" class="form-control" maxlength="150" required></div>
      <div class="col-6"><label class="form-label required">Téléphone</label><input name="phone" class="form-control" maxlength="50" required></div>
      <div class="col-6"><label class="form-label">N° de ticket</label><input name="sale_number" class="form-control" placeholder="V2026-…" maxlength="30"></div>
      <div class="col-12"><label class="form-label required">Adresse / quartier</label><input name="address" class="form-control" maxlength="255" required></div>
      <div class="col-7"><label class="form-label">Livreur</label><input name="driver" class="form-control" maxlength="100"></div>
      <div class="col-5"><label class="form-label">Frais</label><input name="fee" class="form-control text-end" inputmode="numeric" value="<?= e(setting('livraisons_frais_defaut', '0')) ?>"></div>
      <div class="col-12"><input name="note" class="form-control" placeholder="Note (repère, horaire…)" maxlength="255"></div>
      <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Enregistrer</button></div>
    </form>
  </div></div></div>
  <?php endif; ?>
  <div class="<?= can('livraisons.manage') ? 'col-lg-8' : 'col-12' ?>">
    <div class="d-flex flex-wrap gap-2 mb-2">
      <?php foreach (['' => 'Toutes'] + LS::STATUSES as $k => $l): ?><a class="btn btn-sm <?= $status === $k ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url('/livraisons', $k ? ['status' => $k] : [])) ?>"><?= e($l) ?></a><?php endforeach; ?>
    </div>
    <div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0 liv-table"><thead><tr><th>Date</th><th>Client</th><th>Adresse</th><th>Livreur</th><th class="num">Frais</th><th>Statut</th><th></th></tr></thead><tbody>
    <?php foreach ($p['rows'] as $l): ?>
      <tr><td class="small"><?= e(fdate($l['created_at'])) ?><?= $l['sale_number'] ? '<br><a href="' . e(url('/sales/' . $l['sale_id'])) . '">' . e($l['sale_number']) . '</a>' : '' ?></td>
      <td><?= e($l['customer_name']) ?><br><a class="small" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $l['phone'])) ?>"><?= e($l['phone']) ?></a></td>
      <td class="small"><?= e($l['address']) ?><?= $l['note'] ? '<br><span class="text-secondary">' . e($l['note']) . '</span>' : '' ?></td>
      <td><?= e($l['driver'] ?? '') ?></td><td class="num"><?= money($l['fee']) ?></td>
      <td><span class="badge bg-<?= e($colors[$l['status']]) ?>"><?= e(LS::STATUSES[$l['status']]) ?></span></td>
      <td class="text-end"><?php if (can('livraisons.manage')): foreach (LS::next($l['status']) as $to): ?><form method="post" action="<?= e(url('/livraisons/' . $l['id'] . '/status')) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="status" value="<?= e($to) ?>"><button class="btn btn-sm btn-outline-<?= $to === 'livree' ? 'success' : ($to === 'annulee' ? 'danger' : 'primary') ?> mb-1"><?= e(LS::STATUSES[$to]) ?></button></form> <?php endforeach; endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$p['rows']): ?><tr><td colspan="7" class="text-center text-secondary py-4">Aucune livraison.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
  </div>
</div>
