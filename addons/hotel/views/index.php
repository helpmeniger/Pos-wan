<link rel="stylesheet" href="<?= e(asset('addons/hotel/hotel.css')) ?>">
<?php
use Addons\Hotel\HotelService as HS;
$t = $board['totals'];
$tiles = [
  ['Occupation', $t['occupancy'] . ' %', $t['occupied'] . ' / ' . ($t['rooms'] - $t['ooo']) . ' chambres', 'bi-pie-chart-fill', '#0f766e', '/hotel/plan'],
  ['Arrivées du jour', $t['arrivals'], count($arrivals) . ' à installer' . ($t['unassigned'] ? ' · ' . $t['unassigned'] . ' sans chambre' : ''), 'bi-box-arrow-in-right', '#0891b2', '/hotel/reservations?f=arrivees'],
  ['Départs du jour', $t['departures'], $t['overdue'] ? $t['overdue'] . ' en retard' : 'à libérer avant ' . setting('hotel_heure_depart', '12:00'), 'bi-box-arrow-right', '#7c3aed', '/hotel/reservations?f=departs'],
  ['Libres ce soir', $t['free'], $t['dirty'] . ' à nettoyer · ' . $t['ooo'] . ' hors service', 'bi-door-open', '#16a34a', '/hotel/plan'],
  ['Clients présents', $t['guests'], $inHouse . ' séjour(s) en cours', 'bi-people-fill', '#2563eb', '/hotel/reservations?f=en_cours'],
];
$hkColor = ['propre' => '#16a34a', 'inspectee' => '#0d9488', 'sale' => '#d97706', 'nettoyage' => '#f59e0b'];
$stColor = ['libre' => '#16a34a', 'occupee' => '#2563eb', 'arrivee' => '#0891b2', 'depart' => '#7c3aed', 'depart_retard' => '#dc2626', 'hors_service' => '#64748b'];
?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <?php if (can('hotel.reception')): ?>
  <a href="<?= e(url('/hotel/reservations/nouvelle', ['walkin' => 1])) ?>" class="btn btn-success"><i class="bi bi-person-plus"></i> Client sans réservation</a>
  <a href="<?= e(url('/hotel/reservations/nouvelle')) ?>" class="btn btn-primary"><i class="bi bi-calendar-plus"></i> Nouvelle réservation</a>
  <?php endif; ?>
  <a href="<?= e(url('/hotel/plan')) ?>" class="btn btn-dark"><i class="bi bi-grid-3x3-gap"></i> Plan des chambres</a>
  <a href="<?= e(url('/hotel/planning')) ?>" class="btn btn-outline-secondary"><i class="bi bi-calendar-week"></i> Planning</a>
  <form class="d-flex gap-2 ms-auto" method="get" action="<?= e(url('/hotel/reservations')) ?>"><input type="hidden" name="f" value="tous">
    <input name="q" class="form-control" placeholder="Client, chambre, n° de réservation, téléphone"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button></form>
</div>
<?php if ($unposted > 0 && can('hotel.reception')): ?>
<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 py-2"><i class="bi bi-moon-stars"></i>
  <span><b><?= $unposted ?> séjour(s)</b> ont des nuitées passées pas encore portées au folio. Faites l'audit de nuit chaque matin (ou en fin de journée) pour des soldes justes.<?= $lastAudit ? ' Dernier audit : ' . e($lastAudit) . '.' : '' ?></span>
  <form method="post" action="<?= e(url('/hotel/audit-de-nuit')) ?>" class="ms-auto"><?= csrf_field() ?><button class="btn btn-sm btn-warning"><i class="bi bi-moon-stars"></i> Faire l'audit de nuit</button></form>
</div>
<?php endif; ?>
<div class="row g-2 mb-3">
  <?php foreach ($tiles as [$label, $value, $sub, $icon, $color, $href]): ?>
  <div class="col-6 col-md-4 col-xl"><a class="card h-100 text-decoration-none ht-tile" href="<?= e(url($href)) ?>" style="--c:<?= e($color) ?>"><div class="card-body py-2 d-flex gap-2 align-items-center">
    <i class="bi <?= e($icon) ?> fs-2" style="color:var(--c)"></i><div><div class="fs-3 fw-bold text-body lh-1"><?= e((string) $value) ?></div><div class="small fw-semibold text-body"><?= e($label) ?></div><div class="small text-secondary"><?= e($sub) ?></div></div>
  </div></a></div>
  <?php endforeach; ?>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-8"><div class="card h-100"><div class="card-header d-flex align-items-center"><i class="bi bi-grid-3x3-gap me-1"></i> Chambres aujourd'hui<a class="ms-auto small" href="<?= e(url('/hotel/plan')) ?>">Plan complet <i class="bi bi-arrows-fullscreen"></i></a></div>
    <div class="card-body">
      <?php foreach ($board['floors'] as $floor): ?>
      <div class="small text-secondary fw-semibold mb-1"><?= e($floor) ?></div>
      <div class="ht-mini mb-2">
        <?php foreach (array_filter($board['rooms'], fn($r) => $r['floor'] === $floor) as $r): ?>
        <a href="<?= e(url($r['stay'] ? '/hotel/sejours/' . $r['stay']['id'] : '/hotel/chambres/' . $r['id'])) ?>" class="ht-mini-room" style="--c:<?= e($stColor[$r['state']]) ?>" title="<?= e($r['number'] . ' · ' . $r['type'] . ($r['stay'] ? ' · ' . $r['stay']['name'] : '') . ' · ' . HS::HK[$r['hk']]) ?>">
          <b><?= e($r['number']) ?></b><span><?= e($r['stay'] ? mb_substr($r['stay']['name'], 0, 12) : ($r['state'] === 'hors_service' ? 'H.S.' : 'libre')) ?></span><i style="background:<?= e($hkColor[$r['hk']]) ?>" title="<?= e(HS::HK[$r['hk']]) ?>"></i>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
      <div class="small text-secondary d-flex flex-wrap gap-3 mt-1"><?php foreach (['libre' => 'Libre', 'occupee' => 'Occupée', 'arrivee' => 'Arrivée attendue', 'depart' => 'Départ du jour', 'depart_retard' => 'Départ dépassé', 'hors_service' => 'Hors service'] as $k => $l): ?><span><i class="ht-dot" style="background:<?= e($stColor[$k]) ?>"></i> <?= e($l) ?></span><?php endforeach; ?><span>· pastille : état du ménage</span></div>
    </div></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header"><i class="bi bi-cash-coin"></i> Aujourd'hui</div><ul class="list-group list-group-flush">
    <li class="list-group-item d-flex"><span>Encaissé (hôtel)</span><b class="ms-auto"><?= money($stats['cash']) ?></b></li>
    <li class="list-group-item d-flex"><span title="Nuit du <?= e(fdate(date('Y-m-d', strtotime('-1 day')), false)) ?>, portée par l'audit de nuit">Nuit dernière : nuitées</span><b class="ms-auto"><?= (int) $stats['nights'] ?> · <?= money($stats['room']) ?></b></li>
    <li class="list-group-item d-flex"><span>Autres prestations</span><b class="ms-auto"><?= money($stats['other']) ?></b></li>
    <li class="list-group-item d-flex"><span title="Prix moyen par chambre occupée, nuit dernière">Prix moyen (ADR)</span><b class="ms-auto"><?= money($stats['adr']) ?></b></li>
    <li class="list-group-item d-flex"><span title="Revenu des nuitées par chambre disponible, nuit dernière">RevPAR</span><b class="ms-auto"><?= money($stats['revpar']) ?></b></li>
    <li class="list-group-item d-flex"><a href="<?= e(url('/hotel/reservations', ['f' => 'soldes'])) ?>">Soldes à recouvrer (clients partis)</a><b class="ms-auto <?= $stats['receivable'] > 0 ? 'text-danger' : '' ?>"><?= money($stats['receivable']) ?></b></li>
  </ul>
  <?php if (can('hotel.reception') && $unposted === 0): ?><div class="card-body pt-2 small text-secondary"><i class="bi bi-check2-circle text-success"></i> Folios à jour<?= $lastAudit ? ' (audit du ' . e($lastAudit) . ')' : '' ?>.</div><?php endif; ?>
  </div></div>
</div>
<div class="row g-3">
  <?php foreach ([['Arrivées attendues', $arrivals, 'arrivee', 'bi-box-arrow-in-right', 'info'], ['Départs du jour', $departures, 'depart', 'bi-box-arrow-right', 'warning']] as [$label, $list, $anchor, $icon, $c]): ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header"><i class="bi <?= e($icon) ?>"></i> <?= e($label) ?> <span class="badge bg-<?= e($c) ?> text-dark"><?= count($list) ?></span></div>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><tbody>
    <?php foreach ($list as $s): $late = $anchor === 'depart' && $s['departure'] < $today; ?>
      <tr><td class="fw-bold fs-5" style="width:4.5rem"><?= e($s['room_number'] ?? '—') ?></td>
        <td><a href="<?= e(url('/hotel/sejours/' . $s['id'])) ?>" class="fw-semibold"><?= (int) $s['vip'] ? '<i class="bi bi-star-fill text-warning"></i> ' : '' ?><?= e(trim($s['last_name'] . ' ' . $s['first_name'])) ?></a><br>
          <small class="text-secondary"><?= e($s['number']) ?> · <?= e($s['type_name']) ?> · <?= HS::nights($s['arrival'], $s['departure']) ?> nuit(s)<?= $late ? ' · <span class="text-danger fw-semibold">départ prévu le ' . e(fdate($s['departure'], false)) . '</span>' : '' ?><?= $anchor === 'arrivee' && $s['arrival'] < $today ? ' · <span class="text-danger">prévue le ' . e(fdate($s['arrival'], false)) . '</span>' : '' ?></small></td>
        <td class="text-end"><?php if (can('hotel.reception')): ?><a class="btn btn-sm btn-<?= e($c) ?>" href="<?= e(url('/hotel/sejours/' . $s['id'])) ?>#<?= e($anchor) ?>"><?= $anchor === 'arrivee' ? 'Arrivée' : 'Départ' ?></a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td class="text-center text-secondary py-3">Aucun<?= $anchor === 'arrivee' ? 'e arrivée attendue' : ' départ prévu' ?>.</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
  <?php endforeach; ?>
  <?php if ($upcoming): ?>
  <div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-calendar-event"></i> Prochaines réservations</div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody>
    <?php foreach ($upcoming as $s): ?><tr><td><?= e(fdate($s['arrival'], false)) ?> → <?= e(fdate($s['departure'], false)) ?></td><td><a href="<?= e(url('/hotel/sejours/' . $s['id'])) ?>"><?= e(trim($s['last_name'] . ' ' . $s['first_name'])) ?></a></td><td><?= e($s['type_name']) ?></td><td><?= e($s['room_number'] ?? 'à attribuer') ?></td><td><?= App\Core\View::partial('hotel::_status', ['st' => $s['status']]) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div></div>
  <?php endif; ?>
</div>
