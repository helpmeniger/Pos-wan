<?php $this->layout('layouts/app'); ?>
<?= $this->partial('partials/admin-nav') ?>
<div class="container pb-4">
  <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center mb-3">
    <h1 class="h4 mb-0">Vérification des documents</h1>
    <div class="btn-group btn-group-sm"><a class="btn btn-outline-primary<?= $tab === 'pending' ? ' active' : '' ?>" href="<?= e(url('/admin/verifications')) ?>">À traiter</a>
      <a class="btn btn-outline-primary<?= $tab === 'done' ? ' active' : '' ?>" href="<?= e(url('/admin/verifications?vue=traitees')) ?>">Traités</a></div>
  </div>
  <?php if (!$rows): ?><div class="jn-card p-4 text-center text-muted"><?= $tab === 'pending' ? 'Aucun document en attente. 🎉' : 'Aucun document traité.' ?></div><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <div class="jn-card p-3 mb-3">
      <div class="d-flex justify-content-between flex-wrap gap-2">
        <div><a class="fw-semibold" href="<?= e(url('/admin/utilisateurs/' . $r['user_uuid'])) ?>"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></a>
          <?= $r['business_name'] ? '— ' . e($r['business_name']) : '' ?> <span class="small text-muted">(<?= e($r['primary_type'] === 'WORKER' ? 'prestataire' : 'recruteur') ?>, <?= e(App\Support\Phone::format($r['phone'])) ?>)</span>
          <div class="small"><?= e($docTypes[$r['doc_type']] ?? $r['doc_type']) ?><?= $r['document_number_last4'] ? ' · n° …' . e($r['document_number_last4']) : '' ?> · envoyé le <?= e(date_fr($r['submitted_at'], true)) ?></div>
          <?php if ($tab === 'done'): ?><div class="small text-muted"><?= badge($r['status'], status_tone($r['status'])) ?> par <?= e($r['reviewer'] ?? '—') ?> le <?= e(date_fr($r['decided_at'], true)) ?><?= $r['decision_note'] ? ' — ' . e($r['decision_note']) : '' ?></div><?php endif; ?>
        </div>
        <?php if (can('users.view_identity_docs')): ?><a class="btn btn-sm btn-outline-secondary align-self-start" href="<?= e(url('/fichiers/' . $r['file_uuid'])) ?>" target="_blank" rel="noopener"><i class="bi bi-eye" aria-hidden="true"></i> Ouvrir le document</a><?php endif; ?>
      </div>
      <?php if ($tab === 'pending'): ?>
        <form method="post" action="<?= e(url('/admin/verifications/' . (int) $r['id'])) ?>" class="d-flex flex-wrap gap-2 mt-2">
          <?= csrf_field() ?>
          <input class="form-control form-control-sm" style="max-width:360px" name="note" maxlength="500" placeholder="Note (obligatoire en cas de refus)" aria-label="Note">
          <button class="btn btn-sm btn-success" name="decision" value="approve" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Approuver</button>
          <button class="btn btn-sm btn-outline-danger" name="decision" value="reject" type="submit">Refuser</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <p class="small text-muted">Vérifiez la lisibilité, la concordance du nom avec le compte et la date de validité. Chaque ouverture de document est journalisée.</p>
</div>
