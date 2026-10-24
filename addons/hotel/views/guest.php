<?php use Addons\Hotel\HotelService as HS; $done = array_filter($stays, fn($s) => in_array($s['status'], ['en_cours', 'terminee'], true)); ?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-outline-secondary" href="<?= e(url('/hotel/clients')) ?>"><i class="bi bi-arrow-left"></i> Clients</a>
  <?php if (can('hotel.reception')): ?><a class="btn btn-primary" href="<?= e(url('/hotel/reservations/nouvelle', ['guest' => $g['id']])) ?>"><i class="bi bi-calendar-plus"></i> Nouvelle réservation pour ce client</a><?php endif; ?>
</div>
<div class="row g-3">
  <div class="col-lg-7"><div class="card"><div class="card-header"><i class="bi bi-person-vcard"></i> Fiche du client</div><div class="card-body">
    <?php if (can('hotel.reception')): ?><form method="post" action="<?= e(url('/hotel/clients/' . $g['id'])) ?>"><?= csrf_field() ?><?= App\Core\View::partial('hotel::_guest_fields', ['g' => $g, 'full' => true]) ?><button class="btn btn-primary mt-2"><i class="bi bi-save"></i> Enregistrer</button></form>
    <?php else: ?><div class="fs-5 fw-bold"><?= e(trim($g['last_name'] . ' ' . $g['first_name'])) ?></div><div><?= e((string) $g['phone']) ?> · <?= e((string) $g['nationality']) ?></div><?php endif; ?>
  </div></div></div>
  <div class="col-lg-5"><div class="card mb-3"><div class="card-body d-flex justify-content-around text-center">
      <div><div class="fs-3 fw-bold"><?= count($done) ?></div><div class="small text-secondary">séjour(s)</div></div>
      <div><div class="fs-3 fw-bold"><?= array_sum(array_map(fn($s) => HS::nights($s['arrival'], $s['departure']), $done)) ?></div><div class="small text-secondary">nuit(s)</div></div>
      <div><div class="fs-3 fw-bold"><?= money(array_sum(array_column($done, 'total_charges'))) ?></div><div class="small text-secondary">dépensé</div></div>
    </div></div>
    <div class="card"><div class="card-header">Séjours et réservations</div><ul class="list-group list-group-flush small">
      <?php foreach ($stays as $s): ?><li class="list-group-item d-flex gap-2"><span><a href="<?= e(url('/hotel/sejours/' . $s['id'])) ?>"><?= e($s['number']) ?></a> · <?= e(fdate($s['arrival'], false)) ?> → <?= e(fdate($s['departure'], false)) ?> · ch. <?= e($s['room_number'] ?? '—') ?> (<?= e($s['type_name']) ?>)</span><span class="ms-auto"><?= App\Core\View::partial('hotel::_status', ['st' => $s['status']]) ?></span></li><?php endforeach; ?>
      <?php if (!$stays): ?><li class="list-group-item text-secondary">Aucun séjour.</li><?php endif; ?>
    </ul></div></div>
</div>
