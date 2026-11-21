<?= App\Core\View::partial(App\Services\Documents::a4Template(null, 'invoice'), ['doc' => App\Services\Documents::saleA4($s, $items, $payments, 'invoice', true)]) ?>
