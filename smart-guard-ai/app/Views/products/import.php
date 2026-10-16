<div class="card" style="max-width:820px"><div class="card-body">
<p>Fichier CSV (séparateur « ; » ou « , », encodage UTF-8) avec une ligne d'en-tête contenant les colonnes suivantes :</p>
<pre class="bg-light p-2 small">nom;categorie;marque;reference;code_barres;prix_achat;prix_vente;stock_initial;seuil;unite
Riz parfumé 5 kg;Riz et céréales;Marque X;RZ5;6001234567890;3500;4250;20;5;sac</pre>
<p class="small text-muted">Si le code-barres existe déjà, le produit est mis à jour (le stock n'est jamais modifié par une mise à jour : utilisez le module Stocks). La catégorie doit correspondre exactement à une catégorie existante.</p>
<form method="post" enctype="multipart/form-data" action="<?= url('products/import') ?>" class="d-flex gap-2"><?= csrf_field() ?>
  <input type="file" name="csv" accept=".csv,text/csv" class="form-control" required><button class="btn btn-primary">Importer</button></form>
</div></div>
