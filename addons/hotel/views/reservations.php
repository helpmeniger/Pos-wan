<link rel="stylesheet" href="<?= e(asset('addons/hotel/hotel.css')) ?>">
<?php
use Addons\Hotel\HotelService as HS;
$tabs = ['actifs' => ['Réservations et séjours en cours', null], 'arrivees' => ['Arrivées du jour', $counts['arrivees']], 'en_cours' => ['En séjour', $counts['en_cours']],
  'departs' => ['Départs du jour', $counts['departs']], 'avenir' => ['À venir', $counts['avenir']], 'terminee' => ['Partis', null], 'soldes' => ['Soldes à recouvrer', $counts['soldes']], 'annulees' => ['Annulées / non présentés', null], 'tous' => ['Tous', null]];
?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php if (can('hotel.reception')): ?><a href="<?= e(url('/hotel/reservations/nouvelle')) ?>" class="btn btn-primary"><i class="bi bi-calendar-plus"></i> Nouvelle réservation</a>
  <a href="<?= e(url('/hotel/reservations/nouvelle', ['walkin' => 1])) ?>" class="btn btn-success"><i class="bi bi-person-plus"></i> Client sans réservation</a><?php endif; ?>
  <form class="d-flex gap-2 ms-auto" method="get"><input type="hidden" name="f" value="<?= e($f) ?>">
    <input name="q" value="<?= e($q) ?>" class="form-control" placeholder="Nom, téléphone, chambre, n°, pièce, société"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button></form>
</div>
<div class="d-flex flex-wrap gap-1 mb-2">
  <?php foreach ($tabs as $k => [$l, $n]): ?><a class="btn btn-sm <?= $f === $k ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url('/hotel/reservations', ['f' => $k] + ($q !== '' ? ['q' => $q] : []))) ?>"><?= e($l) ?><?= $n ? ' <span class="badge bg-' . ($k === 'soldes' ? 'danger' : 'secondary') . '">' . (int) $n . '</span>' : '' ?></a><?php endforeach; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>N°</th><th>Client</th><th>Chambre</th><th>Arrivée</th><th>Départ</th><th class="num">Nuits</th><th class="num">Total / payé</th><th>Statut</th><th></th></tr></thead><tbody>
<?php foreach ($p['rows'] as $s): $due = round((float) $s['total_charges'] - (float) $s['total_paid'], 2); ?>
  <tr>
    <td><a class="fw-semibold" href="<?= e(url('/hotel/sejours/' . $s['id'])) ?>"><?= e($s['number']) ?></a><br><small class="text-secondary"><?= e(HS::SOURCES[$s['source']] ?? $s['source']) ?></small></td>
    <td><?= (int) $s['vip'] ? '<i class="bi bi-star-fill text-warning"></i> ' : '' ?><?= e(trim($s['last_name'] . ' ' . $s['first_name'])) ?><br><small class="text-secondary"><?= e((string) $s['phone']) ?><?= $s['company'] ? ' · ' . e($s['company']) : '' ?></small></td>
    <td><b><?= e($s['room_number'] ?? '—') ?></b><br><small class="text-secondary"><?= e($s['type_name']) ?></small></td>
    <td class="<?= in_array($s['status'], ['reservee', 'confirmee'], true) && $s['arrival'] < $today ? 'text-danger fw-semibold' : '' ?>"><?= e(fdate($s['arrival'], false)) ?></td>
    <td class="<?= $s['status'] === 'en_cours' && $s['departure'] < $today ? 'text-danger fw-semibold' : '' ?>"><?= e(fdate($s['departure'], false)) ?></td>
    <td class="num"><?= HS::nights($s['arrival'], $s['departure']) ?></td>
    <td class="num"><?= money($s['total_charges']) ?><br><small class="<?= $due > 0.009 && $s['status'] === 'terminee' ? 'text-danger fw-semibold' : 'text-secondary' ?>"><?= money($s['total_paid']) ?></small></td>
    <td><?= App\Core\View::partial('hotel::_status', ['st' => $s['status']]) ?></td>
    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/hotel/sejours/' . $s['id'])) ?>"><i class="bi bi-folder2-open"></i></a></td>
  </tr>
<?php endforeach; ?>
<?php if (!$p['rows']): ?><tr><td colspan="9" class="text-center text-secondary py-4">Aucun séjour.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
