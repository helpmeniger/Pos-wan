<?php use App\Saas\Subscription; ?>
<h1 class="mb-3">Clients <small class="text-muted fs-6">(<?= count($rows) ?>)</small></h1>
<form class="d-flex flex-wrap gap-2 mb-3" method="get">
  <input name="q" class="form-control" style="max-width:320px" value="<?= e($q) ?>" placeholder="Nom, adresse, téléphone…">
  <select name="etat" class="form-select" style="max-width:220px">
    <option value="">Tous les états</option>
    <?php foreach (Subscription::STATES + ['waiting_db' => 'En attente d\'une base', 'provisioning' => 'En création'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $f === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-primary">Filtrer</button>
</form>
<div class="panel p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead class="table-light"><tr><th>Commerce</th><th>Contact</th><th>Offre</th><th>État</th><th>Échéance</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $t): ?>
    <tr>
      <td><a href="<?= e(url('/console/clients/' . $t['id'])) ?>" class="fw-semibold"><?= e($t['company']) ?></a><br><small class="text-muted"><?= e($t['slug'] . '.' . config('saas.domain')) ?></small></td>
      <td><?= e($t['contact_name']) ?><br><small class="text-muted"><?= e($t['phone']) ?></small></td>
      <td><?= e($t['plan_name']) ?><br><small class="text-muted"><?= e(App\Saas\Billing::fcfa($t['price_month'])) ?>/mois</small></td>
      <td><?= Subscription::badge($t['sub']['state']) ?></td>
      <td><?= $t['sub']['until'] ? e(date('d/m/Y', strtotime($t['sub']['until']))) . ($t['sub']['days_left'] ? '<br><small class="text-muted">' . (int) $t['sub']['days_left'] . ' j restant(s)</small>' : '') : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">Aucun client.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
