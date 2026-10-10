<style>@page { size: <?= $bodyClass === 't58' ? '58mm' : '80mm' ?> auto; margin: 0; }</style>
<?= App\Core\View::partial(App\Services\Documents::ticketTemplate(), ['doc' => App\Services\Documents::saleTicket($s, $items, $payments, $dup)]) ?>
