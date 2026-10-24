<link rel="stylesheet" href="<?= e(asset('addons/hotel/hotel.css')) ?>">
<?php use Addons\Hotel\HotelService as HS; $t = $r['totals']; ?>
<div class="card mb-3 d-print-none"><div class="card-body py-2"><form class="row g-2 align-items-end" method="get">
  <?= App\Core\View::partial('partials/period', ['from' => $from, 'to' => $to]) ?>
  <div class="col-auto"><button class="btn btn-sm btn-primary">Afficher</button></div>
  <?php if (can('reports.export')): ?><div class="col-auto ms-auto"><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a> <a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a></div><?php endif; ?>
</form></div></div>
<div class="row g-2 mb-3">
  <?php foreach ([['Taux d\'occupation', $t['occupancy'] . ' %', $t['occupied'] . ' nuitées / ' . $t['available'] . ' disponibles'], ['CA hébergement', money($t['room']), 'nuitées portées au folio'], ['Autres prestations', money($t['other']), 'restauration, taxes, services…'],
      ['Prix moyen (ADR)', money($t['adr']), 'par chambre occupée'], ['RevPAR', money($t['revpar']), 'par chambre disponible'], ['Arrivées / départs', $t['arrivals'] . ' / ' . $t['departures'], $r['cancelled'] . ' annulation(s) / non-présentation(s)']] as [$k, $v, $sub]): ?>
  <div class="col-6 col-lg-4 col-xl-2"><div class="card h-100"><div class="card-body py-2"><div class="small text-secondary"><?= e($k) ?></div><div class="fs-4 fw-bold"><?= e((string) $v) ?></div><div class="small text-secondary"><?= e($sub) ?></div></div></div></div>
  <?php endforeach; ?>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-8"><div class="card h-100"><div class="card-header">Occupation nuit par nuit</div><div class="card-body">
    <div class="ht-bars"><?php foreach ($r['days'] as $d => $x): $pct = $r['rooms'] ? round($x['occupied'] * 100 / $r['rooms']) : 0; ?>
      <div class="ht-barcol" title="<?= e(fdate($d, false)) ?> : <?= (int) $x['occupied'] ?> chambre(s) · <?= $pct ?> % · <?= e(money($x['room'])) ?>"><span style="height:<?= max(2, $pct) ?>%"></span><small><?= e(date('d', strtotime($d))) ?></small></div>
    <?php endforeach; ?></div>
  </div></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header">Par type de chambre</div><table class="table table-sm mb-0"><thead><tr><th>Type</th><th class="num">Nuitées</th><th class="num">CA</th></tr></thead><tbody>
    <?php foreach ($r['types'] as $x): ?><tr><td><?= e($x['name']) ?></td><td class="num"><?= (int) $x['nights'] ?></td><td class="num"><?= money($x['total']) ?></td></tr><?php endforeach; ?>
    <?php if (!$r['types']): ?><tr><td colspan="3" class="text-secondary text-center">—</td></tr><?php endif; ?></tbody></table></div></div>
</div>
<div class="row g-3">
  <div class="col-lg-4"><div class="card h-100"><div class="card-header">Chiffre d'affaires par nature</div><table class="table table-sm mb-0"><tbody>
    <?php foreach ($r['kinds'] as $x): ?><tr><td><?= e(HS::KINDS[$x['kind']] ?? $x['kind']) ?></td><td class="num"><?= (int) $x['n'] ?></td><td class="num"><?= money($x['total']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header">Origine des réservations</div><table class="table table-sm mb-0"><tbody>
    <?php foreach ($r['sources'] as $x): ?><tr><td><?= e(HS::SOURCES[$x['source']] ?? $x['source']) ?></td><td class="num"><?= (int) $x['n'] ?> séj.</td><td class="num"><?= (int) $x['nights'] ?> nuits</td></tr><?php endforeach; ?></tbody></table></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header">Encaissements par mode</div><table class="table table-sm mb-0"><tbody>
    <?php foreach ($r['payments'] as $x): ?><tr><td><?= e(method_label($x['method'])) ?> <small class="text-secondary"><?= e($x['kind']) ?></small></td><td class="num"><?= (int) $x['n'] ?></td><td class="num"><?= money($x['total']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
<p class="small text-secondary mt-2">Une nuit est comptée occupée pour chaque séjour présent cette nuit-là (séjours en cours et terminés). Le CA hébergement est celui des nuitées portées au folio : faites l'audit de nuit pour qu'il soit à jour.</p>
