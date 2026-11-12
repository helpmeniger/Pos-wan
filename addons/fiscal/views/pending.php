<?= App\Core\View::partial('fiscal::_nav', ['tab' => $tab]) ?>
<div class="d-flex justify-content-between align-items-center mb-2"><p class="small text-secondary mb-0"><?= e(t('Factures transmises sans réponse valide, ou en échec. Elles restent NON CERTIFIÉES tant que le dispositif n\'a pas répondu positivement. La file est traitée par la tâche planifiée « php addons/fiscal/bin/fiscal-queue.php ».')) ?></p>
<?php if (can('fiscal.queue')): ?><form method="post" action="<?= e(url('/fiscal/attente/traiter')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat"></i> <?= e(t('Traiter la file maintenant')) ?></button></form><?php endif; ?></div>
<?= App\Core\View::partial('fiscal::_certfilters', compact('from', 'to', 'q', 'st')) ?>
<?= App\Core\View::partial('fiscal::_certtable', ['p' => $p, 'actions' => true]) ?>
