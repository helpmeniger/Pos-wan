<?php $this->layout('layouts/app'); ?>
<section class="jn-hero py-5">
  <div class="container py-lg-4">
    <div class="row align-items-center g-4">
      <div class="col-lg-7">
        <span class="badge bg-accent-soft text-dark mb-3"><i class="bi bi-geo-alt" aria-hidden="true"></i> Lancement à Niamey</span>
        <h1 class="display-5 mb-3">Un travail, une opportunité, un revenu.</h1>
        <p class="lead mb-4">Particuliers, commerces, restaurants, hôtels, entreprises et ONG trouvent rapidement des prestataires de confiance.
          Étudiants, jeunes diplômés, artisans et indépendants trouvent des missions rémunérées près de chez eux.</p>
        <div class="d-flex flex-column flex-sm-row gap-2">
          <a class="btn btn-accent btn-lg" href="<?= e(url('/inscription?type=worker')) ?>"><i class="bi bi-person-workspace" aria-hidden="true"></i> Je cherche des missions</a>
          <a class="btn btn-light btn-lg" href="<?= e(url('/inscription?type=client')) ?>"><i class="bi bi-briefcase" aria-hidden="true"></i> Je recrute</a>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="jn-hero-card p-4">
          <div class="row text-center g-3">
            <div class="col-4"><div class="jn-stat"><?= count($categories) ?></div><div class="small">domaines</div></div>
            <div class="col-4"><div class="jn-stat"><?= (int) $tradesCount ?></div><div class="small">métiers</div></div>
            <div class="col-4"><div class="jn-stat"><?= count($cities) ?></div><div class="small">villes</div></div>
          </div>
          <hr class="border-light opacity-25">
          <ul class="list-unstyled small mb-0">
            <li class="mb-2"><i class="bi bi-shield-check me-2" aria-hidden="true"></i>Profils vérifiés selon le niveau de risque de la mission</li>
            <li class="mb-2"><i class="bi bi-receipt me-2" aria-hidden="true"></i>Commission affichée avant tout engagement, reçus et historique</li>
            <li><i class="bi bi-chat-dots me-2" aria-hidden="true"></i>Messagerie, négociation et récapitulatif signé par les deux parties</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="container py-5">
  <div class="d-flex justify-content-between align-items-end mb-3 flex-wrap gap-2">
    <div>
      <h2 class="h4 mb-1">Les domaines d'activité</h2>
      <p class="text-muted mb-0">Des missions de quelques heures, d'une journée, saisonnières ou à distance.</p>
    </div>
    <a href="<?= e(url('/categories')) ?>" class="btn btn-outline-primary btn-sm">Voir tous les métiers</a>
  </div>
  <div class="row g-3">
    <?php foreach (array_slice($categories, 0, 12) as $c): ?>
      <div class="col-6 col-md-4 col-lg-3">
        <a class="jn-card jn-card-link p-3" href="<?= e(url('/categories/' . $c['slug'])) ?>">
          <div class="d-flex align-items-center gap-2">
            <span class="jn-icon"><i class="bi <?= e($c['icon'] ?: 'bi-briefcase') ?>" aria-hidden="true"></i></span>
            <div>
              <div class="fw-semibold small lh-sm"><?= e($c['name']) ?></div>
              <div class="text-muted small"><?= (int) $c['trades_count'] ?> métiers</div>
            </div>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="bg-white border-top border-bottom py-5">
  <div class="container">
    <h2 class="h4 mb-4 text-center">Comment ça marche ?</h2>
    <div class="row g-4">
      <?php foreach ([
        ['megaphone', 'Publier', 'Le demandeur décrit la mission : lieu, dates, horaires, rémunération, conditions.'],
        ['people', 'Choisir', 'Les prestataires postulent ; le demandeur compare les profils, les avis et les vérifications.'],
        ['file-earmark-check', 'S\'engager', 'Prix négocié, récapitulatif contractuel accepté par les deux parties, commission affichée.'],
        ['star', 'Réaliser et évaluer', 'Mission confirmée, paiement suivi, reçus, puis évaluation réciproque.'],
      ] as $i => [$icon, $t, $d]): ?>
        <div class="col-sm-6 col-lg-3">
          <div class="d-flex gap-3">
            <span class="jn-icon accent"><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i></span>
            <div><div class="fw-semibold"><?= $i + 1 ?>. <?= e($t) ?></div><p class="text-muted small mb-0"><?= e($d) ?></p></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
