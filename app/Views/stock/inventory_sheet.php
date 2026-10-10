<div class="sheet">
  <?= App\Core\View::partial('partials/doc_head', ['docTitle' => 'FEUILLE D\'INVENTAIRE', 'docNumber' => $inv['number'], 'docDate' => $inv['created_at']]) ?>
  <p>Entrepôt : <b><?= e($inv['warehouse']) ?></b></p>
  <table class="lines"><thead><tr><th>Catégorie</th><th>Référence</th><th>Article</th><th>Quantité comptée</th></tr></thead><tbody>
  <?php foreach ($items as $i): ?><tr><td><?= e($i['category'] ?? '') ?></td><td><?= e($i['reference']) ?></td><td><?= e($i['name']) ?></td><td style="border-bottom:1px solid #000;width:120px"></td></tr><?php endforeach; ?>
  </tbody></table>
  <div class="sign"><div>Compteur</div><div>Contrôleur</div></div>
</div>
