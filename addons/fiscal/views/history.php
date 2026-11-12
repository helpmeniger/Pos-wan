<?= App\Core\View::partial('fiscal::_nav', ['tab' => $tab]) ?>
<?= App\Core\View::partial('fiscal::_certfilters', compact('from', 'to', 'q', 'st')) ?>
<?= App\Core\View::partial('fiscal::_certtable', ['p' => $p, 'actions' => false]) ?>
