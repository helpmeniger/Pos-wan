<?php /* Mention de vente à crédit (reste dû sur cette vente). */ if (($doc['credit'] ?? 0) > 0): ?>
<div class="m-row m-credit"><span><?= e(t('Vente à crédit')) ?></span><span><?= e(t('Reste')) ?> : <?= money($doc['credit'], false) ?></span></div>
<?php endif; ?>
