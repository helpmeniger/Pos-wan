<?php $this->layout('layouts/app'); use App\Services\Jobs\JobPostService; ?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3">
    <div>
      <h1 class="h3 mb-0">Offres d'emploi et missions</h1>
      <p class="text-muted mb-0"><?= (int) $result['total'] ?> offre(s) ouverte(s) — postulez en un clic.</p>
    </div>
    <?php if (has_role('CLIENT')): ?><a class="btn btn-primary" href="<?= e(url('/recruteur/offres/nouvelle')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Publier une offre</a><?php endif; ?>
  </div>
  <form class="jn-card p-3 mb-3" method="get" action="<?= e(url('/offres')) ?>" role="search">
    <div class="row g-2">
      <div class="col-md-5"><label class="visually-hidden" for="q">Recherche</label>
        <input class="form-control" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Métier, mot-clé… (ex : serveur, chauffeur, saisie)"></div>
      <div class="col-6 col-md-3"><label class="visually-hidden" for="ville">Ville</label>
        <select class="form-select" id="ville" name="ville"><option value="">Toutes les villes</option>
          <?php foreach ($cities as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $filters['city'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-3"><label class="visually-hidden" for="categorie">Domaine</label>
        <select class="form-select" id="categorie" name="categorie"><option value="">Tous les domaines</option>
          <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $filters['category'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-1 d-grid"><button class="btn btn-primary" type="submit" aria-label="Rechercher"><i class="bi bi-search" aria-hidden="true"></i></button></div>
    </div>
  </form>

  <?php if (!$result['rows']): ?>
    <div class="jn-card p-4 text-center text-muted">
      <i class="bi bi-search fs-2 d-block mb-2" aria-hidden="true"></i>
      Aucune offre ne correspond pour le moment. Élargissez la recherche ou revenez bientôt.
    </div>
  <?php endif; ?>
  <div class="row g-3">
    <?php foreach ($result['rows'] as $p): ?>
      <div class="col-md-6">
        <a class="jn-card jn-card-link jn-job-card p-3<?= $p['is_sponsored'] ? ' sponsored' : '' ?>" href="<?= e(url('/offres/' . $p['uuid'])) ?>">
          <div class="d-flex justify-content-between gap-2">
            <h2 class="h6 mb-1"><?= e($p['title']) ?></h2>
            <span class="fw-bold text-primary jn-amount"><?= e(JobPostService::payLabel($p)) ?></span>
          </div>
          <div class="small text-muted mb-2">
            <i class="bi bi-building" aria-hidden="true"></i> <?= e($p['recruiter']) ?>
            <?php if ($p['recruiter_verified']): ?><i class="bi bi-patch-check-fill text-primary" title="Recruteur vérifié" aria-label="Recruteur vérifié"></i><?php endif; ?>
            · <i class="bi bi-geo-alt" aria-hidden="true"></i> <?= e($p['city'] ?? 'À distance') ?><?= $p['neighborhood'] ? ', ' . e($p['neighborhood']) : '' ?>
          </div>
          <p class="small mb-2"><?= e(mb_strimwidth((string) $p['description'], 0, 160, '…')) ?></p>
          <div class="d-flex flex-wrap gap-1 small">
            <span class="badge text-bg-light border"><?= e($p['trade']) ?></span>
            <span class="badge text-bg-light border"><?= e($p['contract_type']) ?></span>
            <span class="badge text-bg-light border">Début : <?= e(date_fr($p['start_date'])) ?></span>
            <span class="badge text-bg-light border"><?= (int) $p['positions_total'] - (int) $p['positions_filled'] ?> poste(s)</span>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
  <?= $this->partial('partials/pagination', ['result' => $result]) ?>
</div>
