<?php $this->layout('layouts/app'); ?>
<?= $this->partial('partials/admin-nav') ?>
<div class="container pb-4">
  <h1 class="h4">Offres <span class="text-muted fs-6">(<?= (int) $result['total'] ?>)</span></h1>
  <form class="row g-2 mb-3" method="get">
    <div class="col-md-5"><input class="form-control form-control-sm" name="q" value="<?= e($f['q']) ?>" placeholder="Titre, référence, recruteur" aria-label="Recherche"></div>
    <div class="col-md-4"><select class="form-select form-select-sm" name="status" aria-label="Statut"><option value="">Tous les statuts</option>
      <?php foreach (['EN_MODERATION', 'CANDIDATURES_OUVERTES', 'ATTRIBUEE', 'TERMINEE', 'EXPIREE', 'ANNULEE', 'REFUSEE'] as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(ucfirst(strtolower(str_replace('_', ' ', $s)))) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3 d-grid"><button class="btn btn-sm btn-primary">Filtrer</button></div>
  </form>
  <div class="jn-card table-responsive"><table class="table table-jn align-middle mb-0">
    <thead><tr><th>Offre</th><th>Recruteur</th><th>Statut</th><th class="text-center">Cand.</th><th>Modération</th></tr></thead>
    <tbody><?php foreach ($result['rows'] as $p): ?><tr>
      <td><a href="<?= e(url('/offres/' . $p['uuid'])) ?>"><?= e($p['title']) ?></a><div class="small text-muted"><?= e($p['reference']) ?> · <?= e($p['trade']) ?> · <?= e($p['city'] ?? '') ?> · <?= e(date_fr($p['created_at'])) ?></div>
        <?= $p['moderation_flags'] ? '<div class="small text-danger">Mots détectés : ' . e($p['moderation_flags']) . '</div>' : '' ?><?= in_array($p['risk_level'], ['HIGH', 'REGULATED'], true) ? badge('Métier à risque', 'warning') : '' ?></td>
      <td class="small"><a href="<?= e(url('/admin/utilisateurs/' . $p['recruiter_uuid'])) ?>"><?= e($p['recruiter']) ?></a></td>
      <td><?= badge(ucfirst(strtolower(str_replace('_', ' ', $p['status']))), status_tone($p['status'])) ?></td>
      <td class="text-center"><?= (int) $p['applications'] ?></td>
      <td><form method="post" action="<?= e(url('/admin/offres/' . $p['uuid'] . '/moderation')) ?>" class="d-flex flex-wrap gap-1"><?= csrf_field() ?>
        <?php if ($p['status'] === 'EN_MODERATION'): ?><button class="btn btn-sm btn-success" name="action" value="approve" type="submit">Publier</button><?php endif; ?>
        <?php if (!in_array($p['status'], ['ANNULEE', 'REFUSEE'], true)): ?>
          <input class="form-control form-control-sm" style="width:150px" name="reason" maxlength="500" placeholder="Motif" aria-label="Motif">
          <button class="btn btn-sm btn-outline-danger" name="action" value="<?= $p['status'] === 'EN_MODERATION' ? 'reject' : 'suspend' ?>" type="submit"><?= $p['status'] === 'EN_MODERATION' ? 'Refuser' : 'Retirer' ?></button>
        <?php endif; ?></form></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?= $this->partial('partials/pagination', ['result' => $result]) ?>
</div>
