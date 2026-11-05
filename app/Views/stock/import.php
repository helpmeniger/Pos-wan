<?php use App\Services\StockEntry; ?>
<?php if ($preview): $c = $preview['count']; ?>
  <div class="card mb-3"><div class="card-header"><i class="bi bi-search"></i> Vérification de « <?= e($preview['name']) ?> » — <?= e(StockEntry::MODES[$preview['mode']]) ?> <span class="text-secondary small">(rien n'est encore modifié)</span></div>
  <div class="card-body">
    <div class="d-flex flex-wrap gap-2 mb-3">
      <span class="badge text-bg-success fs-6"><?= (int) $c['ok'] ?> ligne(s) à appliquer</span>
      <span class="badge text-bg-secondary fs-6"><?= (int) $c['skip'] ?> sans changement</span>
      <span class="badge text-bg-danger fs-6"><?= (int) $c['error'] ?> en erreur (ignorées)</span>
    </div>
    <div class="table-responsive" style="max-height:55vh"><table class="table table-sm table-hover align-middle"><thead class="table-light sticky-top"><tr><th>Ligne</th><th>Article</th><th>Entrepôt</th><th class="text-end">Stock actuel</th><th class="text-end">Variation</th><th class="text-end">Nouveau stock</th><th>État</th></tr></thead><tbody>
    <?php foreach ($preview['lines'] as $l): ?>
      <tr class="<?= $l['status'] === 'error' ? 'table-danger' : ($l['status'] === 'skip' ? 'text-secondary' : '') ?>">
        <td><?= (int) $l['line'] ?></td><td><?= e($l['name'] ?: $l['identifier']) ?><div class="small text-secondary"><?= e($l['identifier']) ?><?= $l['lot'] ? ' · lot ' . e($l['lot']) : '' ?></div></td>
        <td class="small"><?= e($l['warehouse']) ?></td>
        <td class="text-end"><?= $l['before'] !== null ? qty($l['before']) : '' ?></td>
        <td class="text-end fw-semibold <?= ($l['change'] ?? 0) > 0 ? 'text-success' : 'text-danger' ?>"><?= $l['change'] !== null && abs($l['change']) >= 0.0005 ? ($l['change'] > 0 ? '+' : '') . qty($l['change']) : '' ?></td>
        <td class="text-end"><?= $l['after'] !== null ? qty($l['after']) : '' ?></td>
        <td class="small"><?= $l['status'] === 'ok' ? '<span class="text-success"><i class="bi bi-check-circle"></i> à appliquer</span>' : e($l['message']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </div>
  <div class="card-footer">
    <?php if ($c['ok'] > 0): ?>
    <form method="post" action="<?= e(url('/stock/import/apply')) ?>" class="row g-2 align-items-end" data-confirm="Appliquer <?= (int) $c['ok'] ?> ligne(s) au stock ? Chaque ligne devient un mouvement de stock à votre nom ; l'import et son fichier sont conservés."><?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($preview['token']) ?>">
      <div class="col-md-7"><label class="form-label small">Commentaire de l'import (facultatif)</label><input name="note" class="form-control" maxlength="255" placeholder="ex. inventaire du dépôt du 05/11"></div>
      <div class="col-md-5 d-flex gap-2"><button class="btn btn-success"><i class="bi bi-check2-all"></i> Appliquer <?= (int) $c['ok'] ?> ligne(s)</button><a href="<?= e(url('/stock/import')) ?>" class="btn btn-light">Annuler</a></div>
    </form>
    <?php else: ?><div class="text-secondary">Aucune ligne à appliquer. <a href="<?= e(url('/stock/import')) ?>">Choisir un autre fichier</a></div><?php endif; ?>
  </div></div>
<?php else: ?>
<div class="row g-3">
  <div class="col-lg-6"><div class="card"><div class="card-header"><i class="bi bi-file-earmark-excel text-success"></i> Mettre à jour le stock depuis un fichier Excel ou CSV</div><div class="card-body">
    <form method="post" action="<?= e(url('/stock/import/preview')) ?>" enctype="multipart/form-data" class="row g-3"><?= csrf_field() ?>
      <div class="col-12"><label class="form-label fw-semibold">1. Fichier (.xlsx ou .csv)</label><input type="file" name="file" class="form-control" required accept=".xlsx,.csv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"></div>
      <div class="col-12"><label class="form-label fw-semibold">2. Le fichier contient…</label>
        <?php foreach (StockEntry::MODES as $k => $l): ?><div class="form-check"><input class="form-check-input" type="radio" name="mode" value="<?= e($k) ?>" id="m-<?= e($k) ?>" <?= $k === 'add' ? 'checked' : '' ?>><label class="form-check-label" for="m-<?= e($k) ?>"><?= e($l) ?></label></div><?php endforeach; ?></div>
      <div class="col-12"><label class="form-label fw-semibold">3. Entrepôt par défaut <span class="small text-secondary">(si la colonne « entrepot » est vide)</span></label><select name="warehouse_id" class="form-select"><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= (int) $w['id'] === $wh ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><button class="btn btn-primary"><i class="bi bi-search"></i> Vérifier le fichier</button> <span class="small text-secondary">Un aperçu ligne par ligne s'affiche avant toute modification.</span></div>
    </form>
  </div></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header"><i class="bi bi-download"></i> Modèle</div><div class="card-body small">
    <form method="get" action="<?= e(url('/stock/import/template')) ?>" class="d-flex gap-2 mb-3"><select name="warehouse_id" class="form-select form-select-sm"><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= (int) $w['id'] === $wh ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-success text-nowrap"><i class="bi bi-file-earmark-excel"></i> Télécharger le modèle Excel</button></form>
    <p class="mb-1">Le modèle contient tous vos articles et leur <b>stock actuel</b>. Remplissez la colonne <b>quantite</b> puis renvoyez le fichier. Colonnes reconnues :</p>
    <ul class="mb-2"><li><b>reference</b> ou <b>code_barres</b> (obligatoire) — pour retrouver l'article ;</li><li><b>quantite</b> (obligatoire) — quantité à ajouter, ou stock compté selon le choix 2 ; une ligne vide est ignorée ;</li><li><b>entrepot</b> — nom ou code ; <b>prix_achat</b> — met à jour le coût moyen ; <b>lot</b> et <b>peremption</b> — articles suivis par lot ; <b>motif</b>.</li></ul>
    <p class="mb-0 text-secondary">Traçabilité : chaque ligne appliquée est un mouvement « Import » à votre nom ; l'import garde le fichier d'origine (empreinte SHA-256), le détail des lignes appliquées et refusées. Rien n'est effacé.</p>
  </div></div></div>
  <div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-clock-history"></i> Historique des imports</div>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle"><thead class="table-light"><tr><th>N°</th><th>Date</th><th>Fichier</th><th>Mode</th><th>Entrepôt</th><th class="text-end">Appliquées</th><th class="text-end">Ignorées</th><th>Par</th><th></th></tr></thead><tbody>
    <?php foreach ($imports as $i): ?>
      <tr><td><?= (int) $i['id'] ?></td><td class="small text-nowrap"><?= e(fdate($i['created_at'])) ?></td><td><?= e($i['file_name']) ?><?php if ($i['note']): ?><div class="small text-secondary"><?= e($i['note']) ?></div><?php endif; ?></td>
        <td class="small"><?= $i['mode'] === 'add' ? 'Ajout' : 'Stock compté' ?></td><td class="small"><?= e($i['warehouse'] ?? '') ?></td><td class="text-end text-success"><?= (int) $i['rows_applied'] ?></td><td class="text-end"><?= (int) $i['rows_skipped'] ?></td>
        <td class="small"><i class="bi bi-person"></i> <?= e($i['user_name']) ?></td><td><a href="<?= e(url('/stock/imports/' . $i['id'])) ?>" class="btn btn-sm btn-outline-primary">Détail</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$imports): ?><tr><td colspan="9" class="text-secondary small p-3">Aucun import pour le moment.</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
</div>
<?php endif; ?>
