<?php
/** Commandes en ligne : liste à traiter (nouvelles → validées → encaissées → livrées). */
$tiles = [['todo', 'À traiter', 'bi-inbox', 'dark', $counts['new'] + $counts['confirmed'] + $counts['paid']]];
foreach ($statuses as $k => [$label, $color, $icon]) {
    $tiles[] = [$k, $label, $icon, $color, $counts[$k]];
}
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <div class="input-group input-group-sm w-auto"><span class="input-group-text"><i class="bi bi-globe2"></i> Votre boutique</span><input class="form-control" value="<?= e($shopUrl) ?>" readonly size="34" aria-label="Adresse de la boutique"><a class="btn btn-outline-primary" href="<?= e($shopUrl) ?>" target="_blank" rel="noopener" data-no-progress><i class="bi bi-box-arrow-up-right"></i> Ouvrir</a></div>
  <span class="badge text-bg-light border ms-auto">Aujourd'hui : <?= (int) $stats['today'] ?> commande(s) · <?= e(money($stats['sales_today'])) ?> encaissé(s)</span>
  <?php if (can('boutique.manage')): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/boutique-admin/reglages')) ?>"><i class="bi bi-sliders"></i> Réglages</a><?php endif; ?>
</div>
<div class="row g-2 mb-3">
<?php foreach ($tiles as [$k, $label, $icon, $color, $n]): ?>
  <div class="col-6 col-md-4 col-xl-2"><a href="<?= e(url('/boutique-admin', ['f' => $k])) ?>" class="card text-decoration-none h-100 <?= $f === $k ? 'border-' . $color . ' border-2 shadow-sm' : '' ?>"><div class="card-body py-2 d-flex align-items-center gap-2">
    <i class="bi <?= e($icon) ?> fs-4 text-<?= e($color) ?>"></i><div><div class="fs-4 fw-bold lh-1"><?= (int) $n ?></div><div class="small text-secondary"><?= e($label) ?></div></div></div></a></div>
<?php endforeach; ?>
  <div class="col-6 col-md-4 col-xl-2"><a href="<?= e(url('/boutique-admin', ['f' => 'all'])) ?>" class="card text-decoration-none h-100 <?= $f === 'all' ? 'border-2 shadow-sm' : '' ?>"><div class="card-body py-2 d-flex align-items-center gap-2"><i class="bi bi-list-ul fs-4"></i><div class="small">Toutes les commandes</div></div></a></div>
</div>
<form class="d-flex gap-2 mb-2" method="get"><input type="hidden" name="f" value="<?= e($f) ?>"><input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="N° de commande, nom ou téléphone du client" style="max-width:360px"><button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button></form>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Commande</th><th>Client</th><th>Mode</th><th class="text-end">Total</th><th>Statut</th><th></th></tr></thead><tbody>
  <?php foreach ($p['rows'] as $o): [$label, $color, $icon] = $statuses[$o['status']]; ?>
    <tr class="<?= $o['status'] === 'new' ? 'table-warning' : '' ?>">
      <td><a class="fw-bold" href="<?= e(url('/boutique-admin/' . $o['id'])) ?>"><?= e($o['number']) ?></a><div class="small text-secondary"><?= e(fdate($o['created_at'])) ?> · <?= (int) $o['nb_lines'] ?> article(s)</div></td>
      <td><?= e($o['customer_name']) ?><div class="small"><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $o['phone'])) ?>"><?= e($o['phone']) ?></a></div></td>
      <td><?= $o['delivery'] === 'delivery' ? '<i class="bi bi-truck"></i> Livraison<div class="small text-secondary text-truncate" style="max-width:220px">' . e($o['address'] ?? '') . '</div>' : '<i class="bi bi-shop"></i> Retrait' ?></td>
      <td class="text-end fw-bold text-nowrap"><?= e(money($o['total'])) ?></td>
      <td><span class="badge text-bg-<?= e($color) ?>"><i class="bi <?= e($icon) ?>"></i> <?= e($label) ?></span></td>
      <td class="text-end"><a class="btn btn-sm btn-<?= in_array($o['status'], ['new', 'confirmed', 'paid'], true) ? 'primary' : 'light' ?>" href="<?= e(url('/boutique-admin/' . $o['id'])) ?>"><?= ['new' => 'Vérifier', 'confirmed' => 'Encaisser', 'paid' => 'Livrer'][$o['status']] ?? 'Voir' ?></a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$p['rows']): ?><tr><td colspan="6" class="text-center text-secondary py-4"><i class="bi bi-inbox fs-3 d-block"></i>Aucune commande<?= $f === 'todo' ? ' à traiter' : '' ?>.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
