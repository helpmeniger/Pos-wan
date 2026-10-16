<div class="card" style="max-width:860px"><div class="card-body">
<p>Importez les ventes exportées par votre logiciel de caisse (une ligne par article ; les lignes ayant la même <em>référence</em> forment un ticket) :</p>
<pre class="bg-light p-2 small">reference;date;code_barres;quantite;prix_unitaire;paiement
F-2024-0001;2024-05-12 10:42:00;6001234567890;2;4250;especes
F-2024-0001;2024-05-12 10:42:00;6009876543210;1;1500;especes</pre>
<p class="small text-muted">Une référence déjà importée est ignorée (pas de doublon). Après l'import, les sorties vidéo correspondantes sont rapprochées automatiquement. Pour une intégration en temps réel, utilisez l'API (<code>POST /api/v1/sales</code>, voir docs/API.md).</p>
<form method="post" enctype="multipart/form-data" action="<?= url('pos/import') ?>" class="d-flex gap-2"><?= csrf_field() ?>
  <input type="file" name="csv" accept=".csv,text/csv" class="form-control" required><button class="btn btn-primary">Importer</button></form>
</div></div>
