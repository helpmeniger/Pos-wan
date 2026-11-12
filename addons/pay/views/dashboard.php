<?= App\Core\View::partial('pay::_nav', ['tab' => $tab]) ?>
<?php foreach ($warnings as $w): ?><div class="alert alert-warning py-2 small mb-2"><i class="bi bi-exclamation-triangle"></i> <?= e(t($w)) ?></div><?php endforeach; ?>
<div class="alert alert-light border small py-2"><i class="bi bi-shield-lock"></i> <?= e(t('POS Marwa ne reçoit ni ne stocke aucun numéro de carte, CVV ou code PIN. Un paiement n\'est « réussi » que sur confirmation authentifiée du prestataire. Aucun connecteur n\'est déclaré compatible sans sa documentation officielle.')) ?></div>
<form method="get" class="row g-2 mb-3 align-items-end"><?= App\Core\View::partial('partials/period', ['from' => $from, 'to' => $to]) ?><div class="col-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button></div></form>
<div class="row g-2 mb-3">
<?php foreach ([['Total encaissé (réel)', money($k['total']), 'success'], ['Transactions', (int) $k['n'], 'primary'], ['Réussies', (int) $k['ok'], 'success'], ['Refusées', (int) $k['ko'], 'danger'],
    ['En attente / à vérifier', (int) $k['wait'], 'warning'], ['Remboursé', money($k['refunded']), 'dark'], ['Frais', money($k['fees']), 'secondary']] as [$l, $v, $c]): ?>
  <div class="col-6 col-md-4 col-xl"><div class="card border-<?= $c ?> h-100"><div class="card-body py-2"><div class="small text-secondary"><?= e(t($l)) ?></div><div class="fs-5 fw-bold text-<?= $c ?>"><?= e((string) $v) ?></div></div></div></div>
<?php endforeach; ?>
</div>
<?php if ($test): ?><p class="small text-secondary"><span class="badge text-bg-dark">TEST</span> <?= (int) $test ?> <?= e(t('transaction(s) de test sur la période, exclues de tous les totaux.')) ?></p><?php endif; ?>
<div class="row g-3">
<?php foreach ([['Répartition par fournisseur', $byProvider], ['Répartition par moyen de paiement', $byMethod]] as [$title, $rows]): $max = max(1, ...array_map(fn($r) => (float) $r['total'], $rows ?: [['total' => 1]])); ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header fw-semibold"><?= e(t($title)) ?></div><div class="card-body small">
  <?php foreach ($rows as $r): ?><div class="mb-2"><div class="d-flex justify-content-between"><span><?= e($r['label']) ?> <span class="text-secondary">(<?= (int) $r['n'] ?>)</span></span><b><?= e(money($r['total'])) ?></b></div>
    <div class="progress" style="height:6px" role="progressbar" aria-label="<?= e($r['label']) ?>"><div class="progress-bar" style="width:<?= round((float) $r['total'] / $max * 100) ?>%"></div></div></div><?php endforeach; ?>
  <?php if (!$rows): ?><span class="text-secondary"><?= e(t('Aucune transaction réelle sur la période.')) ?></span><?php endif; ?></div></div></div>
<?php endforeach; ?>
  <div class="col-12"><div class="card"><div class="card-header fw-semibold"><?= e(t('Performance des terminaux')) ?></div><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th><?= e(t('Terminal')) ?></th><th><?= e(t('État')) ?></th><th class="text-end"><?= e(t('Transactions')) ?></th><th class="text-end"><?= e(t('Réussies')) ?></th><th class="text-end"><?= e(t('Taux de réussite')) ?></th><th class="text-end"><?= e(t('Durée moyenne')) ?></th></tr></thead><tbody>
    <?php foreach ($terminals as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= e($r['status']) ?></td><td class="text-end"><?= (int) $r['n'] ?></td><td class="text-end"><?= (int) $r['ok'] ?></td><td class="text-end"><?= (int) $r['n'] ? round((int) $r['ok'] / (int) $r['n'] * 100) . ' %' : '—' ?></td><td class="text-end"><?= $r['ms'] !== null ? round((int) $r['ms'] / 1000, 1) . ' s' : '—' ?></td></tr><?php endforeach; ?>
    <?php if (!$terminals): ?><tr><td colspan="6" class="text-secondary small"><?= e(t('Aucun terminal enregistré.')) ?></td></tr><?php endif; ?></tbody></table></div></div></div>
</div>
