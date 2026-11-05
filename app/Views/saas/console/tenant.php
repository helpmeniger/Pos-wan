<?php use App\Saas\Billing; use App\Saas\Subscription; ?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div><a href="<?= e(url('/console/clients')) ?>" class="small"><i class="bi bi-arrow-left"></i> Clients</a>
    <h1 class="mb-0"><?= e($t['company']) ?> <?= Subscription::badge($sub['state']) ?></h1>
    <a href="<?= e($url) ?>" target="_blank" class="small"><?= e($t['slug'] . '.' . config('saas.domain')) ?> <i class="bi bi-box-arrow-up-right"></i></a></div>
</div>
<?php if ($t['provision_error']): ?><div class="alert alert-danger small"><b>Erreur lors de la création de l'espace :</b> <?= e($t['provision_error']) ?> — elle sera retentée à la prochaine visite du client.</div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="panel mb-3">
      <h2>Client</h2>
      <dl class="row small mb-0">
        <dt class="col-5">Contact</dt><dd class="col-7"><?= e($t['contact_name']) ?></dd>
        <dt class="col-5">Téléphone</dt><dd class="col-7"><?= e($t['phone']) ?></dd>
        <dt class="col-5">E-mail</dt><dd class="col-7"><?= e($t['email'] ?: '—') ?></dd>
        <dt class="col-5">Ville</dt><dd class="col-7"><?= e($t['city'] ?: '—') ?></dd>
        <dt class="col-5">Activité</dt><dd class="col-7"><?= e(App\Services\Catalog::BUSINESS_TYPES[$t['business_type']][0] ?? $t['business_type']) ?></dd>
        <dt class="col-5">Inscription</dt><dd class="col-7"><?= e(date('d/m/Y H:i', strtotime($t['created_at']))) ?></dd>
        <dt class="col-5">Base</dt><dd class="col-7"><code><?= e($t['db_name'] ?: '—') ?></code></dd>
      </dl>
    </div>
    <div class="panel mb-3">
      <h2>Abonnement</h2>
      <dl class="row small mb-2">
        <dt class="col-5">Offre</dt><dd class="col-7"><?= e($plan['name'] ?? '?') ?> (<?= e(Billing::fcfa($plan['price_month'] ?? 0)) ?>/mois)</dd>
        <dt class="col-5">Fin d'essai</dt><dd class="col-7"><?= $t['trial_ends'] ? e(date('d/m/Y', strtotime($t['trial_ends']))) : '—' ?></dd>
        <dt class="col-5">Payé jusqu'au</dt><dd class="col-7"><?= $t['paid_until'] ? e(date('d/m/Y', strtotime($t['paid_until']))) : '—' ?></dd>
        <?php if ($t['suspended_at']): ?><dt class="col-5">Suspendu le</dt><dd class="col-7"><?= e(date('d/m/Y', strtotime($t['suspended_at']))) ?> — <?= e($t['suspended_reason']) ?></dd><?php endif; ?>
      </dl>
      <?php if ($t['state'] === 'waiting_db'): ?>
        <form method="post" action="<?= e(url('/console/clients/' . $t['id'] . '/attribuer')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-info w-100"><i class="bi bi-database-add"></i> Attribuer une base de la réserve</button></form>
      <?php endif; ?>
      <form method="post" action="<?= e(url('/console/clients/' . $t['id'] . '/offre')) ?>" class="d-flex gap-2 mt-2"><?= csrf_field() ?>
        <select name="plan_id" class="form-select form-select-sm"><?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $t['plan_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-sm btn-outline-primary text-nowrap">Changer d'offre</button></form>
      <form method="post" action="<?= e(url('/console/clients/' . $t['id'] . '/prolonger')) ?>" class="row g-2 mt-1"><?= csrf_field() ?>
        <div class="col-4"><input name="days" type="number" min="1" max="365" class="form-control form-control-sm" placeholder="Jours" required></div>
        <div class="col-8"><input name="reason" class="form-control form-control-sm" placeholder="Motif (geste commercial…)" required></div>
        <div class="col-12"><button class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-calendar-plus"></i> Prolonger gratuitement</button></div></form>
      <?php if ($t['suspended_at']): ?>
        <form method="post" action="<?= e(url('/console/clients/' . $t['id'] . '/reactiver')) ?>" class="mt-2"><?= csrf_field() ?><button class="btn btn-sm btn-success w-100"><i class="bi bi-play-circle"></i> Réactiver l'espace</button></form>
      <?php else: ?>
        <form method="post" action="<?= e(url('/console/clients/' . $t['id'] . '/suspendre')) ?>" class="row g-2 mt-1" data-confirm="Suspendre l'espace ? Le client ne pourra plus y accéder (ses données sont conservées)."><?= csrf_field() ?>
          <div class="col-8"><input name="reason" class="form-control form-control-sm" placeholder="Motif de la suspension" required></div>
          <div class="col-4"><button class="btn btn-sm btn-outline-danger w-100">Suspendre</button></div></form>
      <?php endif; ?>
    </div>
    <div class="panel">
      <h2>Notes internes</h2>
      <form method="post" action="<?= e(url('/console/clients/' . $t['id'] . '/note')) ?>"><?= csrf_field() ?>
        <textarea name="notes" class="form-control form-control-sm mb-2" rows="4"><?= e($t['notes']) ?></textarea>
        <button class="btn btn-sm btn-outline-primary">Enregistrer</button></form>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="panel mb-3">
      <h2>Enregistrer un paiement reçu</h2>
      <form method="post" action="<?= e(url('/console/clients/' . $t['id'] . '/paiement')) ?>" class="row g-2" data-pay><?= csrf_field() ?>
        <div class="col-md-4"><label class="form-label small">Offre</label><select name="plan_id" class="form-select form-select-sm"><?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>" data-price="<?= e($p['price_month']) ?>" <?= (int) $p['id'] === (int) $t['plan_id'] ? 'selected' : '' ?>><?= e($p['name']) ?> — <?= e(Billing::fcfa($p['price_month'])) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label small">Durée</label><select name="months" class="form-select form-select-sm"><?php foreach ([1, 3, 6, 12] as $m): ?><option value="<?= $m ?>"><?= $m ?> mois</option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small">Montant reçu (FCFA)</label><input name="amount" type="number" min="1" step="1" class="form-control form-control-sm" required></div>
        <div class="col-md-3"><label class="form-label small">Date</label><input name="paid_on" type="date" class="form-control form-control-sm" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
        <div class="col-md-4"><label class="form-label small">Mode</label><select name="method" class="form-select form-select-sm"><?php foreach ($methods as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label small">Référence de la transaction</label><input name="reference" class="form-control form-control-sm" maxlength="80"></div>
        <div class="col-md-4"><label class="form-label small">Note</label><input name="note" class="form-control form-control-sm" maxlength="255"></div>
        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-check2-circle"></i> Enregistrer et prolonger l'abonnement</button> <small class="text-muted">La période commence aujourd'hui, ou au lendemain de l'essai ou de la période déjà payée.</small></div>
      </form>
    </div>
    <div class="panel mb-3">
      <h2>Paiements</h2>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Date</th><th>Offre</th><th>Montant</th><th>Mode / réf.</th><th>Période</th><th>État</th><th></th></tr></thead><tbody>
        <?php foreach ($payments as $p): ?>
          <tr><td><?= e(date('d/m/Y', strtotime($p['paid_on']))) ?></td><td><?= e($p['plan_name']) ?> · <?= (int) $p['months'] ?> mois</td><td><?= e(Billing::fcfa($p['amount'])) ?></td>
            <td><?= e($methods[$p['method']]) ?><br><small class="text-muted"><?= e($p['reference'] ?: '—') ?></small></td>
            <td class="small"><?= $p['period_from'] ? e(date('d/m/Y', strtotime($p['period_from'])) . ' → ' . date('d/m/Y', strtotime($p['period_to']))) : '—' ?></td>
            <td><?= ['pending' => '<span class="st st-trial">À vérifier</span>', 'validated' => '<span class="st st-active">Validé</span>', 'rejected' => '<span class="st st-expired">Refusé</span>'][$p['status']] ?></td>
            <td><?php if ($p['status'] === 'validated'): ?><a href="<?= e(url('/console/factures/' . $p['id'])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-pdf"></i> <?= e($p['invoice_no']) ?></a><?php elseif ($p['status'] === 'pending'): ?><a href="<?= e(url('/console/paiements')) ?>" class="btn btn-sm btn-warning">Vérifier</a><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$payments): ?><tr><td colspan="7" class="text-muted small">Aucun paiement.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
    <div class="panel">
      <h2>Historique</h2>
      <table class="table table-sm small mb-0"><tbody>
      <?php foreach ($log as $l): ?><tr><td class="text-nowrap"><?= e(date('d/m/Y H:i', strtotime($l['created_at']))) ?></td><td><?= e($l['action']) ?></td><td><?= e($l['username'] ?: 'système') ?></td><td class="text-muted"><?= e(mb_substr((string) $l['details'], 0, 120)) ?></td></tr><?php endforeach; ?>
      </tbody></table>
    </div>
  </div>
</div>
