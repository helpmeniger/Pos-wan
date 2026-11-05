<?php use App\Saas\Billing; use App\Saas\Subscription;
$alert = ['trial' => 'warning', 'active' => 'success', 'expired' => 'danger', 'suspended' => 'secondary'][$sub['state']]; ?>
<div class="alert alert-<?= $alert ?>">
  <?php if ($sub['state'] === 'trial'): ?><b>Essai gratuit</b> : <?= (int) $sub['days_left'] ?> jour(s) restant(s), jusqu'au <?= e(date('d/m/Y', strtotime($sub['until']))) ?>. Payez avant cette date pour continuer sans interruption.
  <?php elseif ($sub['state'] === 'active'): ?><b>Abonnement actif</b> jusqu'au <?= e(date('d/m/Y', strtotime($sub['until']))) ?> (<?= (int) $sub['days_left'] ?> jour(s)).
  <?php else: ?><b>Abonnement expiré<?= $sub['until'] ? ' le ' . e(date('d/m/Y', strtotime($sub['until']))) : '' ?></b> : POS Marwa est en lecture seule (consultation, rapports et sauvegardes restent possibles ; vos données sont conservées). Payez puis déclarez le paiement ci-dessous.<?php endif; ?>
</div>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3"><div class="card-body">
      <h2 class="h6 text-uppercase text-muted">Votre offre</h2>
      <div class="fs-4 fw-bold"><?= e($plan['name'] ?? '?') ?> <small class="fs-6 text-muted"><?= e(Billing::fcfa($plan['price_month'] ?? 0)) ?> / mois</small></div>
      <p class="small text-muted"><?= e($plan['description'] ?? '') ?></p>
      <?php foreach (['users' => ['Utilisateurs actifs', 'max_users'], 'stores' => ['Boutiques', 'max_stores'], 'registers' => ['Caisses', 'max_registers']] as $k => [$l, $col]): $max = max(1, (int) ($plan[$col] ?? 1)); $pct = min(100, round($usage[$k] / $max * 100)); ?>
        <div class="small d-flex justify-content-between"><span><?= e($l) ?></span><b><?= (int) $usage[$k] ?> / <?= $max ?></b></div>
        <div class="progress mb-2" style="height:6px"><div class="progress-bar <?= $pct >= 100 ? 'bg-danger' : '' ?>" style="width:<?= $pct ?>%"></div></div>
      <?php endforeach; ?>
      <?php $mods = ($plan['addons'] ?? '') === '*' ? array_values($addonNames) : array_values(array_intersect_key($addonNames, array_flip(array_filter(array_map('trim', explode(',', (string) ($plan['addons'] ?? ''))))))); ?>
      <div class="small mt-2"><b>Modules inclus :</b> <?= $mods ? e(implode(', ', $mods)) : 'aucun module complémentaire' ?></div>
      <div class="small text-muted mt-2">Espace : <?= e($t['slug'] . '.' . config('saas.domain')) ?></div>
    </div></div>
    <div class="card"><div class="card-body">
      <h2 class="h6 text-uppercase text-muted">Comment payer</h2>
      <div class="small" style="white-space:pre-line"><?= e($instructions ?: 'Contactez le service pour connaître les moyens de paiement.') ?></div>
      <?php if ($operator['operator_phone']): ?><div class="small mt-2"><i class="bi bi-telephone"></i> <?= e($operator['operator_name']) ?> : <?= e($operator['operator_phone']) ?></div><?php endif; ?>
      <h3 class="h6 mt-3">Tarifs</h3>
      <table class="table table-sm small mb-0"><tbody><?php foreach ($plans as $p): ?><tr><td><?= e($p['name']) ?></td><td class="text-end"><?= e(Billing::fcfa($p['price_month'])) ?> / mois</td></tr><?php endforeach; ?></tbody></table>
    </div></div>
  </div>
  <div class="col-lg-7">
    <?php if (can('settings.manage')): ?>
    <div class="card mb-3"><div class="card-body">
      <h2 class="h6 text-uppercase text-muted">J'ai payé : déclarer mon paiement</h2>
      <form method="post" action="<?= e(url('/abonnement/declarer')) ?>" class="row g-2" data-confirm="Déclarer ce paiement ? Il sera vérifié par le service avant prolongation."><?= csrf_field() ?>
        <div class="col-md-5"><label class="form-label small">Offre</label><select name="plan_id" class="form-select form-select-sm"><?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $t['plan_id'] ? 'selected' : '' ?>><?= e($p['name']) ?> — <?= e(Billing::fcfa($p['price_month'])) ?>/mois</option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small">Durée</label><select name="months" class="form-select form-select-sm"><?php foreach ([1, 3, 6, 12] as $m): ?><option value="<?= $m ?>"><?= $m ?> mois</option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label small">Montant payé (FCFA)</label><input name="amount" type="number" min="1" class="form-control form-control-sm" required></div>
        <div class="col-md-4"><label class="form-label small">Mode</label><select name="method" class="form-select form-select-sm"><?php foreach ($methods as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label small">Référence de la transaction</label><input name="reference" class="form-control form-control-sm" required maxlength="80" placeholder="ID reçu par SMS"></div>
        <div class="col-md-4"><label class="form-label small">Payé depuis le n°</label><input name="payer_phone" class="form-control form-control-sm" maxlength="40"></div>
        <div class="col-md-4"><label class="form-label small">Date du paiement</label><input name="paid_on" type="date" class="form-control form-control-sm" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
        <div class="col-md-8"><label class="form-label small">Message (facultatif)</label><input name="note" class="form-control form-control-sm" maxlength="255"></div>
        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-send"></i> Déclarer le paiement</button></div>
      </form>
    </div></div>
    <?php endif; ?>
    <div class="card"><div class="card-body">
      <h2 class="h6 text-uppercase text-muted">Paiements et factures</h2>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Date</th><th>Offre</th><th>Montant</th><th>État</th><th></th></tr></thead><tbody>
        <?php foreach ($payments as $p): ?>
          <tr><td><?= e(date('d/m/Y', strtotime($p['paid_on']))) ?></td><td><?= e($p['plan_name']) ?> · <?= (int) $p['months'] ?> mois<?php if ($p['period_from']): ?><br><small class="text-muted"><?= e(date('d/m/Y', strtotime($p['period_from'])) . ' → ' . date('d/m/Y', strtotime($p['period_to']))) ?></small><?php endif; ?></td><td><?= e(Billing::fcfa($p['amount'])) ?></td>
            <td><?= ['pending' => '<span class="badge text-bg-warning">En vérification</span>', 'validated' => '<span class="badge text-bg-success">Validé</span>', 'rejected' => '<span class="badge text-bg-danger">Refusé</span>'][$p['status']] ?><?php if ($p['status'] === 'rejected'): ?><br><small class="text-danger"><?= e($p['reject_reason']) ?></small><?php endif; ?></td>
            <td><?php if ($p['status'] === 'validated' && can('settings.manage')): ?><a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= e(url('/abonnement/facture/' . $p['id'])) ?>"><i class="bi bi-file-earmark-pdf"></i> <?= e($p['invoice_no']) ?></a><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$payments): ?><tr><td colspan="5" class="text-muted small">Aucun paiement pour le moment.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div></div>
  </div>
</div>
