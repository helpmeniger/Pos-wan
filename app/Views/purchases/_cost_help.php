<div class="card mb-3 border-info"><div class="card-body py-2 small d-flex flex-wrap gap-3 align-items-center">
  <div><label class="form-label mb-0 fw-semibold" for="costModeAll">Coût des articles reçus :</label>
    <select id="costModeAll" class="form-select form-select-sm d-inline-block w-auto" data-cost-mode-all><option value="average">Prix moyen pondéré (recommandé)</option><option value="new">Nouveau prix (tout le stock)</option><option value="old">Ancien prix (inchangé)</option></select></div>
  <div class="text-secondary flex-grow-1" style="min-width:260px">
    <b>Prix moyen</b> : (ancien stock × ancien coût + quantité reçue × nouveau coût) ÷ stock total. <b>Nouveau prix</b> : tout le stock, ancien compris, est revalorisé au nouveau coût. <b>Ancien prix</b> : le coût actuel est conservé.
    Le mode peut être changé ligne par ligne. Un <b>nouveau prix de vente</b> est appliqué immédiatement et historisé.
  </div>
</div></div>
