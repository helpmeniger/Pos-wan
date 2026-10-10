<?php
use App\Services\Documents;
[$sampleSale, $sampleItems, $samplePayments] = Documents::sample();
$current = (string) setting($type === 'ticket' ? 'ticket_template' : 'invoice_template', '1');
$names = $type === 'ticket' ? Documents::TICKET_TEMPLATES : Documents::A4_TEMPLATES;
?>
<div class="preview-grid <?= $type === 'ticket' ? 'tickets' : 'sheets' ?>">
<?php foreach ($names as $n => $name): ?>
  <div class="preview-item">
    <div class="preview-label no-print"><?= e(t('Modèle')) ?> <?= e($n) ?> — <?= e(t($name)) ?><?= $current === (string) $n ? ' ✔ ' . e(t('actuel')) : '' ?></div>
    <?php if ($type === 'ticket'): ?>
      <?= App\Core\View::partial(Documents::ticketTemplate((string) $n), ['doc' => Documents::saleTicket($sampleSale, $sampleItems, $samplePayments, false)]) ?>
    <?php else: ?>
      <?= App\Core\View::partial(Documents::a4Template((string) $n), ['doc' => Documents::saleA4($sampleSale, $sampleItems, $samplePayments, 'invoice', true)]) ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
