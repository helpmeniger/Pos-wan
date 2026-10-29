<?php use Addons\Pressing\PressingService as PS; $colors = ['received' => 'secondary', 'processing' => 'info text-dark', 'ready' => 'success', 'partial' => 'warning text-dark', 'delivered' => 'dark', 'cancelled' => 'danger']; ?>
<div class="row g-2 mb-3">
  <?php foreach ([['late', 'En retard', 'bi-alarm', 'danger', $counts['late']], ['soon', 'À livrer sous ' . $alertDays . ' j', 'bi-hourglass-split', 'warning', $counts['soon']], ['ready', 'Prêts à retirer', 'bi-bag-check', 'success', $counts['ready']], ['unpaid', 'Reste à encaisser', 'bi-cash-coin', 'primary', money($counts['unpaid'])]] as [$k, $l, $ic, $c, $n]): ?>
  <div class="col-6 col-lg-3"><a class="card text-decoration-none border-<?= e($c) ?> h-100 <?= $f === $k ? 'shadow' : '' ?>" href="<?= e(url('/pressing/suivi', ['f' => $k])) ?>"><div class="card-body py-2 d-flex gap-2 align-items-center"><i class="bi <?= e($ic) ?> fs-3 text-<?= e($c) ?>"></i><div><div class="fs-5 fw-bold text-body"><?= e((string) $n) ?></div><div class="small text-secondary"><?= e($l) ?></div></div></div></a></div>
  <?php endforeach; ?>
</div>
<div class="d-flex flex-wrap gap-1 mb-2 align-items-center">
  <?php foreach (['open' => 'En cours', 'late' => 'En retard', 'soon' => 'Bientôt dus', 'ready' => 'Prêts', 'unpaid' => 'Non soldés', 'delivered' => 'Retirés', 'all' => 'Tous'] as $k => $l): ?><a class="btn btn-sm <?= $f === $k ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url('/pressing/suivi', array_filter(['f' => $k, 'q' => $q]))) ?>"><?= e($l) ?></a><?php endforeach; ?>
  <a class="btn btn-sm btn-primary" href="<?= e(url('/pressing/ecran')) ?>"><i class="bi bi-display"></i> Écran des dépôts</a>
  <form class="d-flex gap-1 ms-auto" method="get"><input type="hidden" name="f" value="<?= e($f) ?>"><input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="N° de dépôt, client, téléphone"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button></form>
  <?php if (can('reports.export')): ?><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i></a><a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>"><i class="bi bi-filetype-pdf"></i></a><?php endif; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 small"><thead><tr><th>N° dépôt</th><th>Client</th><th>Téléphone</th><th>Vêtements</th><th class="num">Qté</th><th>Dépôt</th><th>Livraison prévue</th><th class="num">Total</th><th class="num">Acompte / payé</th><th class="num">Reste</th><th>Statut</th><th class="num">Retard</th></tr></thead><tbody>
<?php foreach ($rows as $o): $rest = round((float) $o['total'] - (float) $o['paid'], 2); $ld = PS::lateDays($o); ?>
  <tr class="<?= $ld ? 'table-danger' : '' ?>">
    <td><a class="fw-semibold" href="<?= e(url('/pressing/' . $o['id'])) ?>"><?= e($o['number']) ?></a><?= (int) $o['express'] ? ' <span class="badge bg-danger">EXP</span>' : '' ?><?= (int) ($o['delivery'] ?? 0) ? ' <span class="badge bg-primary" title="Livraison à domicile"><i class="bi bi-truck"></i></span>' : '' ?></td>
    <td><a href="<?= e(url('/pressing/client', ['phone' => $o['phone']])) ?>"><?= e($o['customer_name']) ?></a></td>
    <td class="text-nowrap"><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $o['phone'])) ?>"><?= e($o['phone']) ?></a></td>
    <td style="max-width:260px"><?= e($o['clothes'] ?? '') ?></td>
    <td class="num"><?= (int) $o['pieces'] ?><?= (float) ($o['weight'] ?? 0) > 0 ? '<br><small class="text-secondary">' . e(PS::qty($o['weight'], 'kg')) . '</small>' : '' ?></td>
    <td class="text-nowrap"><?= e(fdate($o['created_at'])) ?></td>
    <td class="text-nowrap"><?= e(fdate($o['due_date'], false)) ?></td>
    <td class="num"><?= money($o['total']) ?></td>
    <td class="num"><?= money($o['paid']) ?></td>
    <td class="num <?= $rest > 0 && $o['status'] !== 'cancelled' ? 'text-danger fw-semibold' : '' ?>"><?= $o['status'] === 'cancelled' ? '—' : money(max(0, $rest)) ?></td>
    <td><span class="badge bg-<?= e($colors[$o['status']]) ?>"><?= e(PS::SHORT[$o['status']]) ?></span><?= $o['delivered_at'] ? '<br><span class="text-secondary">' . e(fdate($o['delivered_at'])) . '</span>' : '' ?></td>
    <td class="num fw-semibold <?= $ld ? 'text-danger' : '' ?>"><?= $ld ? $ld . ' j' : '—' ?></td>
  </tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="12" class="text-center text-secondary py-4">Aucun dépôt.</td></tr><?php endif; ?>
</tbody></table></div></div>
