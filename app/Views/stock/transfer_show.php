<div class="sheet">
  <?= App\Core\View::partial('partials/doc_head', ['docTitle' => 'BON DE TRANSFERT', 'docNumber' => $t['number'], 'docDate' => $t['created_at']]) ?>
  <p>De : <b><?= e($t['from_name']) ?></b> → Vers : <b><?= e($t['to_name']) ?></b> · Établi par <?= e($t['full_name']) ?><?= $t['note'] ? ' · ' . e($t['note']) : '' ?></p>
  <table class="lines"><thead><tr><th>Référence</th><th>Article</th><th class="num">Quantité</th><th>Reçu (contrôle)</th></tr></thead><tbody>
    <?php foreach ($items as $i): ?><tr><td><?= e($i['reference']) ?></td><td><?= e($i['name']) ?></td><td class="num"><?= qty($i['quantity']) ?></td><td></td></tr><?php endforeach; ?>
  </tbody></table>
  <div class="sign"><div>Expéditeur</div><div>Transporteur</div><div>Réceptionnaire</div></div>
</div>
