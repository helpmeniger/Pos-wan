<?php $this->layout('layouts/app'); $types = ['WORKER' => 'Prestataire', 'CLIENT' => 'Recruteur', 'STAFF' => 'Personnel']; ?>
<?= $this->partial('partials/admin-nav') ?>
<div class="container pb-4">
  <h1 class="h4">Utilisateurs <span class="text-muted fs-6">(<?= (int) $result['total'] ?>)</span></h1>
  <form class="row g-2 mb-3" method="get">
    <div class="col-md-4"><input class="form-control form-control-sm" name="q" value="<?= e($f['q']) ?>" placeholder="Nom, téléphone, e-mail, entreprise" aria-label="Recherche"></div>
    <div class="col-6 col-md-2"><select class="form-select form-select-sm" name="type" aria-label="Type"><option value="">Tous les types</option><?php foreach ($types as $k => $v): ?><option value="<?= $k ?>"<?= $f['type'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><select class="form-select form-select-sm" name="status" aria-label="Statut"><option value="">Tous les statuts</option><?php foreach (['ACTIVE' => 'Actif', 'SUSPENDED' => 'Suspendu', 'BANNED' => 'Fermé'] as $k => $v): ?><option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><select class="form-select form-select-sm" name="verified" aria-label="Vérification"><option value="">Vérifiés ou non</option><option value="1"<?= $f['verified'] === '1' ? ' selected' : '' ?>>Vérifiés</option><option value="0"<?= $f['verified'] === '0' ? ' selected' : '' ?>>Non vérifiés</option></select></div>
    <div class="col-6 col-md-2 d-grid"><button class="btn btn-sm btn-primary">Filtrer</button></div>
  </form>
  <div class="jn-card table-responsive"><table class="table table-jn table-hover align-middle mb-0">
    <thead><tr><th>Nom</th><th>Type</th><th>Téléphone</th><th>Ville</th><th>Vérif.</th><th>Statut</th><th>Inscrit le</th></tr></thead>
    <tbody><?php foreach ($result['rows'] as $u): ?><tr>
      <td><a href="<?= e(url('/admin/utilisateurs/' . $u['uuid'])) ?>"><?= e($u['first_name'] . ' ' . $u['last_name']) ?></a><?= $u['business_name'] ? '<div class="small text-muted">' . e($u['business_name']) . '</div>' : '' ?></td>
      <td class="small"><?= e($types[$u['primary_type']] ?? $u['primary_type']) ?></td>
      <td class="small text-nowrap"><?= e(App\Support\Phone::format($u['phone'])) ?></td><td class="small"><?= e($u['city'] ?? '') ?></td>
      <td><?= (int) $u['verification_level'] >= 2 ? badge('Vérifié', 'success') : badge('Niv. ' . (int) $u['verification_level']) ?><?= (int) $u['pending_docs'] > 0 ? ' ' . badge('doc. en attente', 'warning') : '' ?></td>
      <td><?= badge($u['status'], status_tone($u['status'])) ?></td><td class="small"><?= e(date_fr($u['created_at'])) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?= $this->partial('partials/pagination', ['result' => $result]) ?>
</div>
