<link rel="stylesheet" href="<?= e(asset('addons/pressing/pressing.css')) ?>">
<?php
use Addons\Pressing\PressingService as PS;
$colors = ['received' => 'secondary', 'processing' => 'info text-dark', 'ready' => 'success', 'partial' => 'warning text-dark', 'delivered' => 'dark', 'cancelled' => 'danger'];
$tiles = [
  ['received', 'Reçus', 'bi-inbox', 'secondary', (int) ($counts['received'] ?? 0)],
  ['processing', 'En cours', 'bi-arrow-repeat', 'info', (int) ($counts['processing'] ?? 0)],
  ['ready', 'Prêts à retirer', 'bi-check2-circle', 'success', (int) ($counts['ready'] ?? 0)],
  ['late', 'En retard', 'bi-alarm', 'danger', $stats['late']],
  ['forgotten', 'Non retirés (+' . (int) setting('pressing_relance_jours', '30') . ' j)', 'bi-hourglass-split', 'warning', $stats['forgotten']],
];
?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php if (can('pressing.deposit')): ?><a href="<?= e(url('/pressing/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Nouveau dépôt</a><?php endif; ?>
  <form class="d-flex gap-2 ms-auto" method="get" action="<?= e(url('/pressing')) ?>">
    <input name="q" value="<?= e($q) ?>" class="form-control" placeholder="N°, client, téléphone ou étiquette" autofocus>
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
  </form>
</div>
<div class="row g-2 mb-3">
  <?php foreach ($tiles as [$f, $label, $icon, $c, $n]): ?>
  <div class="col-6 col-md-4 col-xl"><a class="card text-decoration-none border-<?= e($c) ?> h-100 <?= $filter === $f ? 'shadow' : '' ?>" href="<?= e(url('/pressing', ['f' => $f])) ?>"><div class="card-body py-2 d-flex align-items-center gap-2">
    <i class="bi <?= e($icon) ?> fs-3 text-<?= e($c) ?>"></i><div><div class="fs-4 fw-bold text-body"><?= $n ?></div><div class="small text-secondary"><?= e($label) ?></div></div>
  </div></a></div>
  <?php endforeach; ?>
  <div class="col-6 col-md-4 col-xl"><div class="card h-100"><div class="card-body py-2"><div class="small text-secondary">Encaissé aujourd'hui</div><div class="fs-5 fw-bold"><?= money($stats['cash_today']) ?></div><div class="small text-secondary"><?= (int) $stats['today'] ?> dépôt(s) · reste à encaisser (prêts) <?= money($stats['due_ready']) ?></div></div></div></div>
</div>
<div class="d-flex flex-wrap gap-1 mb-2">
  <a class="btn btn-sm <?= $filter === '' ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url('/pressing')) ?>">En cours de traitement</a>
  <?php foreach (PS::STATUSES as $k => $l): ?><a class="btn btn-sm <?= $filter === $k ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url('/pressing', ['f' => $k])) ?>"><?= e($l) ?></a><?php endforeach; ?>
  <a class="btn btn-sm <?= $filter === 'all' ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url('/pressing', ['f' => 'all'])) ?>">Tous</a>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>N°</th><th>Client</th><th class="num">Pièces</th><th>Retrait prévu</th><th class="num">Total</th><th class="num">Reste</th><th>Statut</th><th></th></tr></thead><tbody>
<?php foreach ($p['rows'] as $o): $rest = round((float) $o['total'] - (float) $o['paid'], 2); $late = in_array($o['status'], ['received', 'processing'], true) && $o['due_date'] < $today; ?>
  <tr>
    <td><a class="fw-semibold" href="<?= e(url('/pressing/' . $o['id'])) ?>"><?= e($o['number']) ?></a><?= (int) $o['express'] ? ' <span class="badge bg-danger">EXPRESS</span>' : '' ?><?= (int) ($o['delivery'] ?? 0) ? ' <span class="badge bg-primary"><i class="bi bi-truck"></i> LIVRAISON</span>' : '' ?><br><small class="text-secondary"><?= e(fdate($o['created_at'])) ?></small></td>
    <td><?= e($o['customer_name']) ?><br><a class="small" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $o['phone'])) ?>"><?= e($o['phone']) ?></a></td>
    <td class="num"><?= (int) $o['pieces'] ?><?= (float) ($o['weight'] ?? 0) > 0 ? '<br><small class="text-secondary">' . e(PS::qty($o['weight'], 'kg')) . '</small>' : '' ?></td>
    <td class="<?= $late ? 'text-danger fw-semibold' : '' ?>"><?= e(fdate($o['due_date'], false)) ?><?= $late ? ' <i class="bi bi-alarm"></i>' : '' ?></td>
    <td class="num"><?= money($o['total']) ?></td>
    <td class="num <?= $rest > 0 ? 'text-danger fw-semibold' : 'text-success' ?>"><?= $rest > 0 ? money($rest) : 'payé' ?></td>
    <td><span class="badge bg-<?= e($colors[$o['status']]) ?>"><?= e(PS::SHORT[$o['status']]) ?></span><?php $ld = PS::lateDays($o); ?><?= $ld ? '<br><small class="text-danger fw-semibold">' . $ld . ' j de retard</small>' : '' ?></td>
    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/pressing/' . $o['id'])) ?>"><i class="bi bi-eye"></i></a></td>
  </tr>
<?php endforeach; ?>
<?php if (!$p['rows']): ?><tr><td colspan="8" class="text-center text-secondary py-4">Aucun dépôt.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
