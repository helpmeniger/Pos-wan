<?php $this->layout('layouts/app'); use App\Services\Employment\PayslipService;
$s = $summary; $st = $s['byStatus']; $bal = $s['balances'];
$sum = fn (array $keys, string $col) => array_sum(array_map(fn ($k) => (float) ($st[$k][$col] ?? 0), $keys));
$cnt = fn (array $keys) => array_sum(array_map(fn ($k) => (int) ($st[$k]['n'] ?? 0), $keys));
$b = fn (string $c) => (int) ($bal[$c]['balance'] ?? 0);
?>
<?= $this->partial('partials/admin-nav') ?>
<div class="container pb-4">
  <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center mb-3">
    <h1 class="h4 mb-0">Finances : salaires et commissions</h1>
    <?php if ($canExport): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/finances/export.csv' . ($f['status'] ? '?status=' . rawurlencode($f['status']) : ''))) ?>"><i class="bi bi-filetype-csv" aria-hidden="true"></i> Export Excel (CSV)</a><?php endif; ?>
  </div>
  <?php if ($s['unbalanced'] > 0): ?><div class="alert alert-danger">Attention : <?= (int) $s['unbalanced'] ?> journal(aux) comptable(s) déséquilibré(s).</div><?php endif; ?>
  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Salaires versés et confirmés', 'value' => money((int) $sum(['RECUE'], 'net')), 'icon' => 'check2-circle', 'hint' => $cnt(['RECUE']) . ' fiche(s) — payés directement par les employeurs']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Salaires en attente', 'value' => money((int) $sum(['EMISE', 'PAIEMENT_DEMANDE', 'PAYEE_DECLAREE'], 'net')), 'icon' => 'hourglass-split', 'hint' => $cnt(['EMISE', 'PAIEMENT_DEMANDE', 'PAYEE_DECLAREE']) . ' fiche(s) non confirmée(s)']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Contestations', 'value' => (string) $cnt(['CONTESTEE']), 'icon' => 'exclamation-triangle', 'hint' => money((int) $sum(['CONTESTEE'], 'net')) . ' en jeu']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Commissions acquises', 'value' => money($b('4010') - $b('4090')), 'icon' => 'graph-up-arrow', 'hint' => 'Encaissées : ' . money($b('1020')) . ' · à recevoir : ' . money($b('1210'))]) ?></div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6"><div class="jn-card p-3 h-100"><h2 class="jn-section-title">Commissions dues par les employeurs</h2>
      <?php if (!$s['receivables']): ?><p class="small text-muted mb-0">Aucune commission en attente.</p><?php endif; ?>
      <?php foreach ($s['receivables'] as $r): ?>
        <div class="border-bottom py-2 small d-flex flex-wrap justify-content-between gap-2 align-items-center">
          <span><a href="<?= e(url('/admin/utilisateurs/' . $r['uuid'])) ?>"><?= e($r['business_name'] ?: $r['name']) ?></a> — <strong class="jn-amount"><?= e(money((int) round((float) $r['due']))) ?></strong></span>
          <?php if ($canCollect): ?><form method="post" action="<?= e(url('/admin/commissions/' . $r['uuid'] . '/encaisser')) ?>" class="d-flex gap-1" data-confirm="Enregistrer cet encaissement ?"><?= csrf_field() ?>
            <input class="form-control form-control-sm" style="width:100px" name="amount" inputmode="numeric" value="<?= (int) round((float) $r['due']) ?>" aria-label="Montant">
            <input class="form-control form-control-sm" style="width:130px" name="reference" placeholder="Réf. reçue" required aria-label="Référence">
            <button class="btn btn-sm btn-outline-success" type="submit">Encaissé</button></form><?php endif; ?>
        </div>
      <?php endforeach; ?></div></div>
    <div class="col-lg-6"><div class="jn-card p-3 h-100"><h2 class="jn-section-title">Évolution mensuelle (salaires confirmés)</h2>
      <?php if (!$s['monthly']): ?><p class="small text-muted mb-0">Pas encore de données.</p><?php else: ?>
      <table class="table table-sm small mb-0"><thead><tr><th>Mois</th><th class="text-end">Fiches</th><th class="text-end">Net versé</th><th class="text-end">Commissions</th></tr></thead>
        <tbody><?php foreach ($s['monthly'] as $m): ?><tr><td><?= e($m['month']) ?></td><td class="text-end"><?= (int) $m['n'] ?></td><td class="text-end jn-amount"><?= e(money((string) $m['net'])) ?></td><td class="text-end jn-amount"><?= e(money((string) $m['commission'])) ?></td></tr><?php endforeach; ?></tbody></table>
      <?php endif; ?></div></div>
  </div>

  <h2 class="h5">Fiches de paie <span class="text-muted fs-6">(<?= (int) $result['total'] ?>)</span></h2>
  <form class="row g-2 mb-2" method="get">
    <div class="col-md-3"><input class="form-control form-control-sm" name="q" value="<?= e($f['q']) ?>" placeholder="N°, employé, employeur" aria-label="Recherche"></div>
    <div class="col-md-3"><select class="form-select form-select-sm" name="status" aria-label="Statut"><option value="">Tous les statuts</option><?php foreach (PayslipService::LABELS as $k => $v): ?><option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><input class="form-control form-control-sm" type="date" name="from" value="<?= e($f['from']) ?>" aria-label="Du"></div>
    <div class="col-6 col-md-2"><input class="form-control form-control-sm" type="date" name="to" value="<?= e($f['to']) ?>" aria-label="Au"></div>
    <div class="col-md-2 d-grid"><button class="btn btn-sm btn-primary">Filtrer</button></div>
  </form>
  <div class="jn-card table-responsive mb-4"><table class="table table-jn align-middle mb-0">
    <thead><tr><th>Fiche</th><th>Employé</th><th>Employeur</th><th class="text-end">Brut</th><th class="text-end">Comm.</th><th class="text-end">Net</th><th>Statut</th></tr></thead>
    <tbody><?php foreach ($result['rows'] as $p): ?><tr>
      <td class="small"><a href="<?= e(url('/admin/fiches-de-paie/' . $p['uuid'])) ?>"><?= e($p['number']) ?></a><div class="text-muted"><?= e($p['period_label']) ?></div></td>
      <td class="small"><?= e($p['employee']) ?></td><td class="small"><?= e($p['employer']) ?></td>
      <td class="text-end small jn-amount"><?= e(money((string) $p['gross_amount'])) ?></td><td class="text-end small jn-amount"><?= e(money((string) $p['commission_amount'])) ?></td>
      <td class="text-end small jn-amount fw-semibold"><?= e(money((string) $p['net_amount'])) ?></td>
      <td><?= badge(PayslipService::LABELS[$p['status']] ?? $p['status'], status_tone($p['status'])) ?><?= $p['dispute_note'] ? '<div class="small text-danger">' . e(mb_strimwidth($p['dispute_note'], 0, 60, '…')) . '</div>' : '' ?></td></tr>
    <?php endforeach; ?><?php if (!$result['rows']): ?><tr><td colspan="7" class="small text-muted">Aucune fiche.</td></tr><?php endif; ?></tbody></table></div>
  <?= $this->partial('partials/pagination', ['result' => $result]) ?>

  <div class="row g-3">
    <div class="col-lg-7"><div class="jn-card p-3 h-100"><h2 class="jn-section-title">Dernières transactions</h2>
      <div class="table-responsive"><table class="table table-sm small mb-0"><tbody>
        <?php foreach ($s['transactions'] as $t): ?><tr><td class="text-nowrap"><?= e(date_fr($t['created_at'])) ?></td><td><?= e($t['reference']) ?><br><span class="text-muted"><?= e($t['description'] ?? $t['type']) ?></span></td><td><?= e($t['user_name'] ?? '') ?></td><td class="text-end jn-amount"><?= e(money((string) $t['net_amount'])) ?></td></tr><?php endforeach; ?>
        <?php if (!$s['transactions']): ?><tr><td class="text-muted">Aucune transaction.</td></tr><?php endif; ?></tbody></table></div></div></div>
    <div class="col-lg-5"><div class="jn-card p-3 h-100"><h2 class="jn-section-title">Grand livre (soldes)</h2>
      <table class="table table-sm small mb-0"><tbody><?php foreach ($bal as $code => $a): if ($a['balance'] === 0) continue; ?><tr><td><?= e($code) ?></td><td><?= e($a['name']) ?></td><td class="text-end jn-amount"><?= e(money($a['balance'])) ?></td></tr><?php endforeach; ?></tbody></table>
      <p class="small text-muted mt-2 mb-0">Les salaires sont versés directement par les employeurs (comptes mémo 9010/9020, hors fonds de la plateforme). Seules les commissions sont des revenus de JOB NIGER.</p></div></div>
  </div>
</div>
