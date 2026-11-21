<style>@page { size: <?= $bodyClass === 't58' ? '58mm' : '80mm' ?> auto; margin: 0; }</style>
<?php $doc = App\Services\Documents::saleTicket($s, $items, $payments, $dup); ?>
<?= App\Core\View::partial(App\Services\Documents::ticketTemplate(null, $doc['kind']), ['doc' => $doc]) ?>
