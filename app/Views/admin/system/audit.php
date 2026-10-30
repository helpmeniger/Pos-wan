<?php $this->layout('layouts/app'); ?>
<?= $this->partial('partials/admin-nav') ?>
<div class="container pb-4">
  <h1 class="h4">Journaux</h1>
  <div class="alert <?= $chain['ok'] ? 'alert-success' : 'alert-danger' ?> small"><?= $chain['ok'] ? 'Journal d\'audit intègre : ' . (int) $chain['checked'] . ' entrées vérifiées (chaînage SHA-256).' : 'Rupture du chaînage détectée à l\'entrée #' . (int) $chain['broken_at'] . ' : altération possible.' ?></div>
  <h2 class="h5">Actions sensibles</h2>
  <div class="jn-card table-responsive mb-2"><table class="table table-jn table-sm small mb-0">
    <thead><tr><th>Date</th><th>Auteur</th><th>Action</th><th>Objet</th><th>Motif</th><th>IP</th></tr></thead>
    <tbody><?php foreach ($logs['rows'] as $l): ?><tr><td class="text-nowrap"><?= e(date_fr($l['created_at'], true)) ?></td><td><?= e($l['actor'] ?? 'Système') ?></td><td><?= e($l['action']) ?></td><td><?= e($l['entity_type']) ?> #<?= e((string) $l['entity_id']) ?></td><td><?= e($l['reason'] ?? '') ?></td><td><?= e($l['ip_address'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?= $this->partial('partials/pagination', ['result' => $logs]) ?>
  <h2 class="h5 mt-4">Sécurité <a class="small fw-normal" href="<?= e(url('/admin/journaux?tout=1')) ?>">(tout afficher)</a></h2>
  <div class="jn-card table-responsive"><table class="table table-jn table-sm small mb-0">
    <thead><tr><th>Date</th><th>Gravité</th><th>Événement</th><th>Utilisateur</th><th>IP</th></tr></thead>
    <tbody><?php foreach ($security['rows'] as $s): ?><tr><td class="text-nowrap"><?= e(date_fr($s['created_at'], true)) ?></td><td><?= badge($s['severity'], $s['severity'] === 'CRITIQUE' ? 'danger' : ($s['severity'] === 'INFO' ? 'secondary' : 'warning')) ?></td><td><?= e($s['event_type']) ?></td><td><?= e($s['user_name'] ?? '') ?></td><td><?= e($s['ip_address'] ?? '') ?></td></tr><?php endforeach; ?>
      <?php if (!$security['rows']): ?><tr><td colspan="5" class="text-muted">Aucun avertissement.</td></tr><?php endif; ?></tbody></table></div>
</div>
