<link rel="stylesheet" href="<?= e(asset('addons/hotel/hotel.css')) ?>">
<?php
use Addons\Hotel\HotelService as HS;
$next = ['sale' => ['nettoyage', 'Commencer le ménage', 'btn-warning', 'bi-play-circle'], 'nettoyage' => ['propre', 'Ménage terminé', 'btn-success', 'bi-check2-circle'],
  'propre' => [can('hotel.reception') || can('hotel.manage') ? 'inspectee' : null, 'Inspectée (prête)', 'btn-outline-info', 'bi-patch-check'], 'inspectee' => [null, '', '', '']];
$stLabel = ['libre' => 'Libre', 'occupee' => 'Client présent', 'arrivee' => 'Arrivée attendue', 'depart' => 'Départ aujourd\'hui', 'depart_retard' => 'Départ dépassé', 'hors_service' => 'Hors service'];
?>
<div class="d-flex flex-wrap gap-3 mb-3 small">
  <span class="badge bg-warning text-dark fs-6"><?= (int) $totals['dirty'] ?> chambre(s) à faire</span>
  <span class="text-secondary">Priorité : les chambres libres avec une arrivée attendue, puis les autres chambres à nettoyer. Une chambre occupée se fait à la demande du client.</span>
</div>
<div class="row g-2">
<?php foreach ($rooms as $r): if ($r['state'] === 'hors_service') continue; [$to, $label, $cls, $icon] = $next[$r['hk']]; $dirty = in_array($r['hk'], ['sale', 'nettoyage'], true); ?>
  <div class="col-12 col-md-6 col-xl-4"><div class="card h-100 ht-hk <?= $dirty ? 'border-warning' : '' ?>"><div class="card-body d-flex align-items-center gap-3 py-2">
    <div class="ht-hk-no"><?= e($r['number']) ?></div>
    <div class="flex-grow-1 small"><div class="fw-semibold"><?= e(HS::HK[$r['hk']]) ?></div><div class="text-secondary"><?= e($r['type']) ?> · <?= e($stLabel[$r['state']]) ?><?= $r['state'] === 'arrivee' ? ' <span class="badge bg-info text-dark">prioritaire</span>' : '' ?></div></div>
    <?php if ($to): ?><form method="post" action="<?= e(url('/hotel/chambres/' . $r['id'] . '/menage')) ?>"><?= csrf_field() ?><input type="hidden" name="state" value="<?= e($to) ?>"><button class="btn <?= e($cls) ?>"><i class="bi <?= e($icon) ?>"></i> <?= e($label) ?></button></form><?php endif; ?>
    <?php if (!$dirty): ?><form method="post" action="<?= e(url('/hotel/chambres/' . $r['id'] . '/menage')) ?>"><?= csrf_field() ?><input type="hidden" name="state" value="sale"><button class="btn btn-sm btn-outline-secondary" title="Marquer à nettoyer"><i class="bi bi-bucket"></i></button></form><?php endif; ?>
  </div></div></div>
<?php endforeach; ?>
</div>
