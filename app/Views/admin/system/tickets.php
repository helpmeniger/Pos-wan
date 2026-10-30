<?php $this->layout('layouts/app'); $labels = ['OUVERT' => 'Ouvert', 'EN_COURS' => 'En cours', 'ATTENTE_UTILISATEUR' => 'Attente utilisateur', 'RESOLU' => 'Résolu', 'FERME' => 'Fermé']; ?>
<?= $this->partial('partials/admin-nav') ?>
<div class="container pb-4">
  <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center mb-3"><h1 class="h4 mb-0">Support</h1>
    <div class="btn-group btn-group-sm"><a class="btn btn-outline-primary<?= $status === 'open' ? ' active' : '' ?>" href="<?= e(url('/admin/support')) ?>">Ouverts</a><a class="btn btn-outline-primary<?= $status === 'all' ? ' active' : '' ?>" href="<?= e(url('/admin/support?vue=tous')) ?>">Tous</a></div></div>
  <?php if (!$result['rows']): ?><div class="jn-card p-4 text-center text-muted">Aucun ticket.</div><?php endif; ?>
  <?php foreach ($result['rows'] as $t): ?>
    <div class="jn-card p-3 mb-2">
      <div class="d-flex justify-content-between flex-wrap gap-2"><div><span class="fw-semibold"><?= e($t['subject']) ?></span> <?= badge($t['priority'], in_array($t['priority'], ['HAUTE', 'URGENTE'], true) ? 'danger' : 'secondary') ?>
        <div class="small text-muted"><?= e($t['reference']) ?> · <?= e($t['category']) ?> · <?= e($t['contact_name'] ?? '') ?> <?= e($t['contact_phone'] ?? '') ?> <?= e($t['contact_email'] ?? '') ?> · <?= e(date_fr($t['created_at'], true)) ?></div></div>
        <form method="post" action="<?= e(url('/admin/support/' . (int) $t['id'])) ?>" class="d-flex gap-1"><?= csrf_field() ?>
          <select class="form-select form-select-sm" name="status" aria-label="Statut"><?php foreach ($labels as $k => $v): ?><option value="<?= $k ?>"<?= $t['status'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
          <button class="btn btn-sm btn-outline-primary" type="submit">OK</button></form></div>
      <p class="small mb-0 mt-2" style="white-space:pre-line"><?= e($t['first_message'] ?? '') ?></p>
    </div>
  <?php endforeach; ?>
  <?= $this->partial('partials/pagination', ['result' => $result]) ?>
</div>
