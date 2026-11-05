<?php use App\Saas\Billing; ?>
<h1 class="mb-3">Paiements</h1>
<div class="panel mb-3">
  <h2>Déclarés par les clients — à vérifier (<?= count($pending) ?>)</h2>
  <p class="small text-muted">Vérifiez chaque référence dans votre relevé mobile money ou bancaire AVANT de valider : la validation prolonge l'abonnement et émet la facture.</p>
  <?php if (!$pending): ?><p class="text-muted small mb-0">Rien à vérifier.</p><?php endif; ?>
  <?php foreach ($pending as $p): ?>
    <div class="border rounded p-3 mb-2">
      <div class="d-flex flex-wrap justify-content-between gap-2">
        <div><a href="<?= e(url('/console/clients/' . $p['tenant_id'])) ?>" class="fw-semibold"><?= e($p['company']) ?></a> · <?= e($p['plan_name']) ?> · <?= (int) $p['months'] ?> mois
          <div class="small">Montant déclaré : <b><?= e(Billing::fcfa($p['amount'])) ?></b> · <?= e($methods[$p['method']]) ?> · réf. <b><?= e($p['reference']) ?></b><?= $p['payer_phone'] ? ' · payé depuis ' . e($p['payer_phone']) : '' ?> · le <?= e(date('d/m/Y', strtotime($p['paid_on']))) ?></div>
          <div class="small text-muted">Déclaré par <?= e($p['declared_by_name']) ?> le <?= e(date('d/m/Y H:i', strtotime($p['created_at']))) ?><?= $p['note'] ? ' — « ' . e($p['note']) . ' »' : '' ?></div></div>
        <div class="d-flex gap-2 align-items-start">
          <form method="post" action="<?= e(url('/console/paiements/' . $p['id'] . '/valider')) ?>" data-confirm="Valider ce paiement de <?= e(Billing::fcfa($p['amount'])) ?> ? Vérifiez d'abord la référence <?= e($p['reference']) ?>."><?= csrf_field() ?><button class="btn btn-success btn-sm"><i class="bi bi-check2"></i> Valider</button></form>
          <form method="post" action="<?= e(url('/console/paiements/' . $p['id'] . '/refuser')) ?>" class="d-flex gap-1"><?= csrf_field() ?><input name="reason" class="form-control form-control-sm" placeholder="Motif du refus" required><button class="btn btn-outline-danger btn-sm">Refuser</button></form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<div class="panel">
  <h2>Historique</h2>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Date</th><th>Client</th><th>Offre</th><th>Montant</th><th>Mode / réf.</th><th>État</th><th>Facture</th></tr></thead><tbody>
    <?php foreach ($done as $p): ?>
      <tr><td><?= e(date('d/m/Y', strtotime($p['paid_on']))) ?></td><td><a href="<?= e(url('/console/clients/' . $p['tenant_id'])) ?>"><?= e($p['company']) ?></a></td><td><?= e($p['plan_name']) ?> · <?= (int) $p['months'] ?> m</td><td><?= e(Billing::fcfa($p['amount'])) ?></td>
        <td><?= e($methods[$p['method']]) ?> <small class="text-muted"><?= e($p['reference'] ?: '') ?></small></td>
        <td><?= $p['status'] === 'validated' ? '<span class="st st-active">Validé</span>' : '<span class="st st-expired" title="' . e($p['reject_reason']) . '">Refusé</span>' ?></td>
        <td><?php if ($p['invoice_no']): ?><a href="<?= e(url('/console/factures/' . $p['id'])) ?>" target="_blank"><?= e($p['invoice_no']) ?></a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
