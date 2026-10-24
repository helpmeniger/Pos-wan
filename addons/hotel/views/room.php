<link rel="stylesheet" href="<?= e(asset('addons/hotel/hotel.css')) ?>">
<?php use Addons\Hotel\HotelService as HS; $ooo = (int) $r['out_of_order'] === 1; ?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <a class="btn btn-outline-secondary" href="<?= e(url('/hotel/plan')) ?>"><i class="bi bi-arrow-left"></i> Plan des chambres</a>
  <?php if (can('hotel.reception') && !$current && !$ooo): ?><a class="btn btn-success" href="<?= e(url('/hotel/reservations/nouvelle', ['walkin' => 1, 'room' => $r['id']])) ?>"><i class="bi bi-person-plus"></i> Installer un client</a><?php endif; ?>
  <?php if (can('hotel.reception') && !$ooo): ?><a class="btn btn-primary" href="<?= e(url('/hotel/reservations/nouvelle', ['room' => $r['id']])) ?>"><i class="bi bi-calendar-plus"></i> Réserver</a><?php endif; ?>
</div>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-body text-center"><div class="display-4 fw-bold"><?= e($r['number']) ?></div><div class="fw-semibold"><?= e($r['type_name']) ?></div>
      <div class="small text-secondary"><?= e($r['floor']) ?><?= $r['beds'] ? ' · ' . e($r['beds']) : '' ?> · <?= (int) $r['capacity'] ?> pers. · <?= money($r['base_price']) ?> / nuit<?= $r['weekend_price'] ? ' (week-end ' . money($r['weekend_price']) . ')' : '' ?></div>
      <?php if ($r['amenities']): ?><div class="small mt-1"><?= e($r['amenities']) ?></div><?php endif; ?>
      <div class="mt-2"><span class="badge bg-<?= $ooo ? 'secondary' : ($current ? 'primary' : 'success') ?> fs-6"><?= $ooo ? 'Hors service' : ($current ? 'Occupée' : 'Libre') ?></span> <span class="badge bg-light text-dark border fs-6"><?= e(HS::HK[$r['housekeeping']]) ?></span></div>
      <?php if ($r['note']): ?><div class="small text-secondary mt-2"><?= e($r['note']) ?></div><?php endif; ?>
    </div></div>
    <?php if (can('hotel.housekeeping') && !$ooo): ?>
    <div class="card mb-3"><div class="card-header">Ménage</div><div class="card-body d-flex flex-wrap gap-1">
      <?php foreach (HS::HK as $k => $l): ?><form method="post" action="<?= e(url('/hotel/chambres/' . $r['id'] . '/menage')) ?>"><?= csrf_field() ?><input type="hidden" name="state" value="<?= e($k) ?>"><input type="hidden" name="back" value="room"><button class="btn btn-sm <?= $r['housekeeping'] === $k ? 'btn-dark' : 'btn-outline-secondary' ?>"><?= e($l) ?></button></form><?php endforeach; ?>
    </div></div>
    <?php endif; ?>
    <?php if (can('hotel.manage')): ?>
    <div class="card mb-3 <?= $ooo ? 'border-secondary' : '' ?>"><div class="card-header"><i class="bi bi-tools"></i> Maintenance</div><div class="card-body">
      <?php if ($ooo): ?>
        <div class="small mb-2">Hors service : <b><?= e((string) $r['ooo_reason']) ?></b><?= $r['ooo_until'] ? ' jusqu\'au ' . e(fdate($r['ooo_until'], false)) : '' ?></div>
        <form method="post" action="<?= e(url('/hotel/chambres/' . $r['id'] . '/hors-service')) ?>"><?= csrf_field() ?><input type="hidden" name="on" value="0"><input name="reason" class="form-control form-control-sm mb-1" placeholder="Travaux réalisés (facultatif)"><button class="btn btn-sm btn-success w-100">Remettre en service</button></form>
      <?php else: ?>
        <form method="post" action="<?= e(url('/hotel/chambres/' . $r['id'] . '/hors-service')) ?>"><?= csrf_field() ?><input type="hidden" name="on" value="1">
          <input name="reason" class="form-control form-control-sm mb-1" placeholder="Raison : climatisation en panne, peinture…" required maxlength="200">
          <label class="small">Jusqu'au (facultatif)</label><input type="date" name="until" class="form-control form-control-sm mb-1" min="<?= e(date('Y-m-d')) ?>">
          <button class="btn btn-sm btn-outline-secondary w-100">Mettre hors service</button></form>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>
  </div>
  <div class="col-lg-8">
    <?php if ($current): ?><div class="alert alert-primary d-flex align-items-center"><i class="bi bi-person-fill me-2"></i><div>Occupée par <a href="<?= e(url('/hotel/sejours/' . $current['id'])) ?>" class="fw-bold"><?= e(trim($current['last_name'] . ' ' . $current['first_name'])) ?></a> du <?= e(fdate($current['arrival'], false)) ?> au <?= e(fdate($current['departure'], false)) ?>.</div></div><?php endif; ?>
    <div class="card mb-3"><div class="card-header">Prochaines réservations</div><ul class="list-group list-group-flush small">
      <?php foreach ($next as $n): ?><li class="list-group-item"><a href="<?= e(url('/hotel/sejours/' . $n['id'])) ?>"><?= e(trim($n['last_name'] . ' ' . $n['first_name'])) ?></a> · <?= e(fdate($n['arrival'], false)) ?> → <?= e(fdate($n['departure'], false)) ?> · <?= App\Core\View::partial('hotel::_status', ['st' => $n['status']]) ?></li><?php endforeach; ?>
      <?php if (!$next): ?><li class="list-group-item text-secondary">Aucune.</li><?php endif; ?></ul></div>
    <div class="row g-3">
      <div class="col-md-6"><div class="card h-100"><div class="card-header">Derniers séjours</div><ul class="list-group list-group-flush small">
        <?php foreach ($past as $n): ?><li class="list-group-item"><a href="<?= e(url('/hotel/sejours/' . $n['id'])) ?>"><?= e(trim($n['last_name'] . ' ' . $n['first_name'])) ?></a> · <?= e(fdate($n['arrival'], false)) ?> → <?= e(fdate($n['departure'], false)) ?></li><?php endforeach; ?>
        <?php if (!$past): ?><li class="list-group-item text-secondary">Aucun.</li><?php endif; ?></ul></div></div>
      <div class="col-md-6"><div class="card h-100"><div class="card-header">Historique de la chambre</div><ul class="list-group list-group-flush small ht-log">
        <?php foreach ($log as $l): ?><li class="list-group-item"><?= e(fdate($l['created_at'])) ?> · <b><?= e(['menage' => 'Ménage', 'arrivee' => 'Arrivée', 'depart' => 'Départ', 'hors_service' => 'Hors service', 'en_service' => 'Remise en service'][$l['event']] ?? $l['event']) ?></b><?= $l['to_state'] ? ' : ' . e(HS::HK[$l['to_state']] ?? $l['to_state']) : '' ?> · <?= e($l['full_name'] ?? '') ?><?= $l['note'] ? '<br><span class="text-secondary">' . e($l['note']) . '</span>' : '' ?></li><?php endforeach; ?>
        <?php if (!$log): ?><li class="list-group-item text-secondary">Aucun événement.</li><?php endif; ?></ul></div></div>
    </div>
  </div>
</div>
