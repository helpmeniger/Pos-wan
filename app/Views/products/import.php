<div class="row g-3">
  <div class="col-lg-7"><div class="card"><div class="card-header"><i class="bi bi-upload"></i> Importer des articles (CSV / Excel)</div><div class="card-body">
    <ol class="small">
      <li>Téléchargez le <a href="<?= e(url('/products/template')) ?>">modèle CSV</a> et ouvrez-le dans Excel.</li>
      <li>Remplissez une ligne par article (colonnes obligatoires : <b>nom</b>, <b>prix_vente</b>).</li>
      <li>Enregistrez au format <b>CSV (séparateur point-virgule)</b> puis importez-le ici.</li>
    </ol>
    <p class="small text-secondary">Un article dont la <b>référence</b> existe déjà est mis à jour (nom, prix) : son stock n'est jamais modifié par l'import. Les nouveaux articles peuvent recevoir un stock initial. En cas d'erreur, rien n'est enregistré.</p>
    <form method="post" enctype="multipart/form-data" action="<?= e(url('/products/import')) ?>" class="row g-2"><?= csrf_field() ?>
      <div class="col-12"><input type="file" name="file" accept=".csv,text/csv" class="form-control" required></div>
      <div class="col-md-6"><label class="form-label small">Entrepôt du stock initial</label><select name="warehouse_id" class="form-select"><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-6 d-flex align-items-end"><div class="form-check"><input type="checkbox" class="form-check-input" name="simulate" value="1" id="sim" checked><label for="sim" class="form-check-label">Simulation (vérifier sans enregistrer)</label></div></div>
      <div class="col-12"><button class="btn btn-success"><i class="bi bi-upload"></i> Lancer l'import</button></div>
    </form>
    <?php if ($report): ?>
      <hr><div class="small"><b>Dernier import<?= $report['simulate'] ? ' (simulation)' : '' ?> :</b> <?= (int) $report['created'] ?> création(s), <?= (int) $report['updated'] ?> mise(s) à jour.</div>
      <?php if ($report['errors']): ?><ul class="small text-danger mt-2"><?php foreach (array_slice($report['errors'], 0, 100) as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php endif; ?>
  </div></div></div>
  <div class="col-lg-5"><div class="card"><div class="card-header"><i class="bi bi-download"></i> Exporter</div><div class="card-body">
    <p class="small">Export de tous les articles actifs au format CSV compatible Excel (UTF-8, séparateur « ; »).</p>
    <a href="<?= e(url('/products/export')) ?>" class="btn btn-outline-success"><i class="bi bi-filetype-csv"></i> Exporter les articles</a>
    <hr><p class="small mb-1">Colonnes du modèle :</p><code class="small">reference; code_barres; nom; categorie; unite; prix_vente; prix_achat; prix_gros; seuil_alerte; stock_initial; lot; peremption (JJ/MM/AAAA)</code>
  </div></div></div>
</div>
