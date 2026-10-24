<link rel="stylesheet" href="<?= e(asset('addons/hotel/hotel.css')) ?>">
<?php
$today = date('Y-m-d');
$dates = [];
for ($i = 0; $i < $days; $i++) { $dates[] = date('Y-m-d', strtotime($start . " +$i day")); }
$end = date('Y-m-d', strtotime($start . " +$days day"));
$byRoom = [];
foreach ($stays as $st) { $byRoom[(int) $st['room_id']][] = $st; }
$wd = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];
$colors = ['reservee' => '#f59e0b', 'confirmee' => '#0891b2', 'en_cours' => '#2563eb', 'terminee' => '#94a3b8'];
?>
<div class="d-flex flex-wrap gap-2 mb-2 align-items-center">
  <a class="btn btn-outline-secondary" href="<?= e(url('/hotel/planning', ['d' => date('Y-m-d', strtotime($start . ' -' . $days . ' day')), 'n' => $days])) ?>"><i class="bi bi-chevron-left"></i></a>
  <a class="btn btn-outline-secondary" href="<?= e(url('/hotel/planning', ['n' => $days])) ?>">Aujourd'hui</a>
  <a class="btn btn-outline-secondary" href="<?= e(url('/hotel/planning', ['d' => $end, 'n' => $days])) ?>"><i class="bi bi-chevron-right"></i></a>
  <form method="get" class="d-flex gap-1"><input type="date" name="d" value="<?= e($start) ?>" class="form-control" data-autosubmit><select name="n" class="form-select" data-autosubmit><?php foreach ([7, 14, 21, 31] as $n): ?><option value="<?= $n ?>" <?= $n === $days ? 'selected' : '' ?>><?= $n ?> jours</option><?php endforeach; ?></select></form>
  <span class="ms-auto small d-flex gap-3 flex-wrap"><?php foreach (['reservee' => 'Réservée', 'confirmee' => 'Confirmée', 'en_cours' => 'En séjour', 'terminee' => 'Partie'] as $k => $l): ?><span><i class="ht-dot" style="background:<?= $colors[$k] ?>"></i> <?= e($l) ?></span><?php endforeach; ?><span><i class="ht-dot" style="background:#64748b"></i> Hors service</span></span>
</div>
<?php if ($unassigned): ?><div class="alert alert-warning py-2 small"><b><?= count($unassigned) ?> réservation(s) sans chambre</b> : <?php foreach ($unassigned as $u): ?><a href="<?= e(url('/hotel/sejours/' . $u['id'])) ?>" class="me-2"><?= e($u['last_name']) ?> (<?= e(fdate($u['arrival'], false)) ?>, <?= e($u['type_name']) ?>)</a><?php endforeach; ?></div><?php endif; ?>
<div class="card"><div class="ht-plan-wrap"><table class="ht-plan" style="--days:<?= $days ?>">
  <thead><tr><th class="rm">Chambre</th><?php foreach ($dates as $d): $w = (int) date('N', strtotime($d)); ?><th class="<?= $d === $today ? 'today' : '' ?> <?= $w >= 6 ? 'we' : '' ?>"><small><?= e($wd[$w - 1]) ?></small><br><?= e(date('d/m', strtotime($d))) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($rooms as $r): $ooo = (int) $r['out_of_order'] ? ($r['ooo_until'] ?: '9999-12-31') : null; ?>
    <tr><th class="rm"><a href="<?= e(url('/hotel/chambres/' . $r['id'])) ?>"><b><?= e($r['number']) ?></b></a> <small class="text-secondary"><?= e($r['type_code']) ?></small></th>
    <?php $skip = 0; foreach ($dates as $i => $d):
      if ($skip > 0) { $skip--; continue; }
      $hit = null;
      foreach ($byRoom[(int) $r['id']] ?? [] as $st) { if ($st['arrival'] <= $d && $st['departure'] > $d) { $hit = $st; break; } }
      if ($hit) {
          $span = 0; $x = $d; while ($x < $hit['departure'] && $span < $days - $i) { $span++; $x = date('Y-m-d', strtotime($x . ' +1 day')); }
          $skip = $span - 1; ?>
      <td colspan="<?= $span ?>" class="busy"><a class="ht-bar" style="background:<?= e($colors[$hit['status']] ?? '#64748b') ?>" href="<?= e(url('/hotel/sejours/' . $hit['id'])) ?>" title="<?= e($hit['number'] . ' · ' . trim($hit['last_name'] . ' ' . $hit['first_name']) . ' · ' . fdate($hit['arrival'], false) . ' → ' . fdate($hit['departure'], false)) ?>"><?= $hit['arrival'] < $start ? '‹ ' : '' ?><?= (int) $hit['vip'] ? '★ ' : '' ?><?= e(trim($hit['last_name'] . ' ' . mb_substr((string) $hit['first_name'], 0, 1))) ?></a></td>
      <?php } elseif ($ooo !== null && $d <= $ooo) { ?>
      <td class="ooo" title="Hors service : <?= e((string) $r['ooo_reason']) ?>"></td>
      <?php } else { ?>
      <td class="<?= $d === $today ? 'today' : '' ?>"><?php if (can('hotel.reception') && $d >= $today): ?><a class="ht-free" href="<?= e(url('/hotel/reservations/nouvelle', ['room' => $r['id'], 'arrival' => $d])) ?>" title="Réserver la chambre <?= e($r['number']) ?> le <?= e(fdate($d, false)) ?>">+</a><?php endif; ?></td>
      <?php } ?>
    <?php endforeach; ?></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<p class="small text-secondary mt-2">Cliquez sur une case libre (+) pour réserver la chambre à cette date, ou sur une barre pour ouvrir le séjour.</p>
