<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <div class="btn-group" role="group" id="areaTabs">
    <button class="btn btn-dark active" data-area="0">Toutes les salles</button>
    <?php foreach ($areas as $a): ?><button class="btn btn-outline-dark" data-area="<?= (int) $a['id'] ?>"><?= e($a['name']) ?></button><?php endforeach; ?>
  </div>
  <div class="ms-auto d-flex gap-2">
    <button class="btn btn-warning" data-new="takeaway"><i class="bi bi-bag"></i> À emporter</button>
    <button class="btn btn-info text-white" data-new="delivery"><i class="bi bi-scooter"></i> Livraison</button>
    <?php if (can('restaurant.kitchen')): ?><a href="<?= e(url('/kitchen')) ?>" class="btn btn-outline-secondary"><i class="bi bi-fire"></i> Écran cuisine</a><?php endif; ?>
  </div>
</div>
<div class="floor-legend small mb-2"><span class="lg free"></span> Libre <span class="lg busy"></span> Occupée <span class="lg billed"></span> Addition demandée <span class="lg ready"></span> Plats prêts <span class="lg unsent"></span> À envoyer en cuisine</div>
<?php if (!$data['tables']): ?>
  <div class="alert alert-info">Aucune table n'est configurée. <?php if (can('restaurant.manage')): ?><a href="<?= e(url('/restaurant/setup')) ?>">Créer les salles et les tables</a>.<?php endif; ?></div>
<?php endif; ?>
<div class="floor-grid" id="floorGrid"></div>
<h2 class="h6 mt-4 mb-2"><i class="bi bi-bag"></i> À emporter & livraisons en cours</h2>
<div class="floor-grid" id="othersGrid"></div>

<div class="modal fade" id="openModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content">
  <div class="modal-header"><h6 class="modal-title" id="openTitle">Nouvelle commande</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
  <div class="modal-body">
    <input type="hidden" id="openTable"><input type="hidden" id="openType">
    <div id="coversBox"><label class="form-label">Nombre de couverts</label>
      <div class="d-flex flex-wrap gap-1 mb-2" id="coversKeys"><?php for ($i = 1; $i <= 8; $i++): ?><button type="button" class="btn btn-outline-primary cover-key" data-c="<?= $i ?>"><?= $i ?></button><?php endfor; ?></div>
      <input type="number" min="1" max="200" id="openCovers" class="form-control" value="2"></div>
    <div id="custBox" class="d-none">
      <input class="form-control mb-1" id="openName" placeholder="Nom du client" maxlength="150">
      <input class="form-control mb-1" id="openPhone" placeholder="Téléphone" maxlength="50">
      <input class="form-control" id="openAddress" placeholder="Adresse de livraison" maxlength="255">
    </div>
    <div class="alert alert-danger d-none mt-2 mb-0 py-2 small" id="openError"></div>
  </div>
  <div class="modal-footer"><button class="btn btn-success w-100" id="openGo"><i class="bi bi-plus-circle"></i> Ouvrir la commande</button></div>
</div></div></div>
<script type="application/json" id="floor-data"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
