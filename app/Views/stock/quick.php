<?php $types = App\Services\Catalog::STOCK_MOVEMENT_TYPES; ?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card border-success"><div class="card-header bg-success-subtle"><i class="bi bi-plus-square-fill text-success"></i> <b>Ajouter du stock</b> <span class="small text-secondary">— scannez ou cherchez l'article, tapez la quantité, Entrée</span></div>
    <div class="card-body">
      <div class="position-relative mb-3">
        <label class="form-label fw-semibold" for="qsSearch">1. Article</label>
        <input id="qsSearch" class="form-control form-control-lg" placeholder="Nom, référence ou code-barres…" autocomplete="off" autofocus>
        <div class="list-group position-absolute w-100 shadow" style="z-index:20" id="qsResults"></div>
      </div>
      <div id="qsCard" class="d-none">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div><div class="fs-5 fw-bold" id="qsName"></div><div class="small text-secondary" id="qsRef"></div></div>
          <div class="text-end"><div class="small text-secondary">Stock total</div><div class="fs-4 fw-bold" id="qsTotal"></div></div>
        </div>
        <table class="table table-sm mb-3"><thead><tr><th>Entrepôt</th><th class="text-end">Stock actuel</th></tr></thead><tbody id="qsLevels"></tbody></table>
        <form id="qsForm" class="row g-2" autocomplete="off">
          <div class="col-md-6"><label class="form-label fw-semibold">2. Entrepôt</label><select name="warehouse_id" class="form-select" id="qsWh"><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= (int) $w['id'] === $wh ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label fw-semibold">3. Quantité à ajouter <span id="qsUnit" class="text-secondary"></span></label><input name="quantity" id="qsQty" type="number" step="any" min="0" class="form-control form-control-lg" required></div>
          <?php if ($canCost): ?><div class="col-md-6"><label class="form-label">Prix d'achat unitaire <span class="small text-secondary">(facultatif : met à jour le coût moyen)</span></label><input name="cost" type="number" step="any" min="0" class="form-control" id="qsCost"></div><?php endif; ?>
          <div class="col-md-6"><label class="form-label">Motif <span class="small text-secondary">(facultatif)</span></label><input name="reason" class="form-control" maxlength="200" placeholder="ex. livraison du fournisseur, retour d'un dépôt"></div>
          <div class="col-md-6 qs-lot d-none"><label class="form-label">N° de lot</label><input name="lot" class="form-control" maxlength="80"></div>
          <div class="col-md-6 qs-lot d-none"><label class="form-label">Péremption</label><input name="expiry" type="date" class="form-control"></div>
          <div class="col-12 d-flex gap-2 align-items-center">
            <button class="btn btn-success btn-lg"><i class="bi bi-plus-lg"></i> Ajouter au stock</button>
            <span class="small text-secondary" id="qsPreview"></span>
          </div>
          <div class="col-12"><div class="alert d-none mb-0" id="qsMsg"></div></div>
        </form>
      </div>
      <?php if ($preset): ?><span class="d-none" id="qsPreset" data-id="<?= (int) $preset['id'] ?>"></span><?php endif; ?>
    </div></div>
    <div class="card mt-3"><div class="card-header"><i class="bi bi-lightning-charge"></i> Ajouts de cette séance</div>
      <div class="table-responsive"><table class="table table-sm mb-0 align-middle"><thead><tr><th>Heure</th><th>Article</th><th>Entrepôt</th><th class="text-end">Ajouté</th><th class="text-end">Nouveau stock</th></tr></thead><tbody id="qsSession"><tr class="qs-empty"><td colspan="5" class="text-secondary small">Aucun ajout pour le moment.</td></tr></tbody></table></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-header d-flex flex-wrap gap-2 align-items-center"><span><i class="bi bi-clock-history"></i> Historique des ajouts rapides</span>
      <form class="d-flex flex-wrap gap-1 ms-auto" method="get">
        <select name="user" class="form-select form-select-sm" style="width:auto"><option value="">Tous les utilisateurs</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $f['user'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?></select>
        <input type="date" name="from" value="<?= e($f['from']) ?>" class="form-control form-control-sm" style="width:auto" aria-label="Du">
        <input type="date" name="to" value="<?= e($f['to']) ?>" class="form-control form-control-sm" style="width:auto" aria-label="Au">
        <button class="btn btn-sm btn-outline-primary">Filtrer</button>
      </form></div>
      <div class="table-responsive" style="max-height:70vh"><table class="table table-sm table-hover mb-0 align-middle"><thead class="table-light sticky-top"><tr><th>Date</th><th>Article</th><th>Entrepôt</th><th class="text-end">Ajouté</th><th class="text-end">Stock après</th><th>Par</th><th>Motif</th></tr></thead><tbody>
      <?php foreach ($history as $h): ?>
        <tr><td class="text-nowrap small"><?= e(fdate($h['created_at'])) ?></td>
          <td><a href="<?= e(url('/products/' . $h['product_id'])) ?>"><?= e($h['product']) ?></a><?php if ($h['lot_number']): ?><div class="small text-secondary">lot <?= e($h['lot_number']) ?></div><?php endif; ?></td>
          <td class="small"><?= e($h['warehouse']) ?></td><td class="text-end text-success fw-semibold">+<?= qty($h['quantity']) ?></td><td class="text-end"><?= qty($h['balance_after']) ?></td>
          <td class="small"><i class="bi bi-person"></i> <?= e($h['user_name'] ?? '—') ?></td><td class="small text-secondary"><?= e($h['reason']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$history): ?><tr><td colspan="7" class="text-secondary small p-3">Aucun ajout rapide enregistré.</td></tr><?php endif; ?>
      </tbody></table></div>
      <div class="card-footer small text-secondary">Chaque ajout est un mouvement de stock daté et signé, visible aussi dans <a href="<?= e(url('/stock/movements', ['type' => 'quick_entry'])) ?>">Mouvements de stock</a> et le journal d'audit. Il ne peut pas être effacé ; une erreur se corrige par un <a href="<?= e(url('/stock/adjust')) ?>">ajustement</a> motivé.</div>
    </div>
  </div>
</div>
