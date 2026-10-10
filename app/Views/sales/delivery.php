<?= App\Core\View::partial(App\Services\Documents::a4Template(), ['doc' => App\Services\Documents::saleA4($s, $items, [], 'delivery', $withPrices)]) ?>
