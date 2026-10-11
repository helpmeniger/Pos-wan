<?php
use Addons\Pressing\PressingService as PS;
$valid = array_filter($orders, fn($o) => $o['status'] !== 'cancelled');
$billed = array_sum(array_column($valid, 'total'));
$cashIn = array_sum(array_map(fn($p) => (float) $p['total'], $payments));
$unpaid = array_sum(array_map(fn($o) => max(0, (float) $o['total'] - (float) $o['paid']), $valid));
?>
<div class="card mb-3 d-print-none"><div class="card-body py-2"><form class="row g-2 align-items-end" method="get">
  <?= App\Core\View::partial('partials/period', ['from' => $from, 'to' => $to]) ?>
  <div class="col-auto"><button class="btn btn-sm btn-primary">Afficher</button></div>
  <?php if (can('reports.export')): ?><div class="col-auto ms-auto"><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a> <a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a></div><?php endif; ?>
</form></div></div>
<div class="row g-3 mb-3">
  <?php foreach ([['Dépôts', count($valid), false], ['Pièces', array_sum(array_column($valid, 'pieces')), false], ['Montant facturé', $billed, true], ['Encaissé (période)', $cashIn, true], ['Reste à encaisser', $unpaid, true]] as [$k, $v, $m]): ?>
  <div class="col-6 col-lg"><div class="card"><div class="card-body py-2"><div class="small text-secondary"><?= e($k) ?></div><div class="fs-4 fw-bold"><?= $m ? money($v) : (int) $v ?></div></div></div></div>
  <?php endforeach; ?>
</div>
<div class="row g-3">
  <div class="col-lg-4"><div class="card h-100"><div class="card-header">Par service</div><table class="table table-sm mb-0"><thead><tr><th>Service</th><th class="num">Pièces</th><th class="num">Montant</th></tr></thead><tbody>
    <?php foreach ($services as $s): ?><tr><td><?= e(PS::SERVICES[$s['service']] ?? $s['service']) ?></td><td class="num"><?= (int) $s['qty'] ?></td><td class="num"><?= money($s['total']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header">Articles les plus déposés</div><table class="table table-sm mb-0"><tbody>
    <?php foreach ($articles as $a): ?><tr><td><?= e($a['article_name']) ?></td><td class="num"><?= (int) $a['qty'] ?></td><td class="num"><?= money($a['total']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header">Encaissements par mode</div><table class="table table-sm mb-0"><tbody>
    <?php foreach ($payments as $p): ?><tr><td><?= e(method_label($p['method'])) ?> <small class="text-secondary"><?= e(['deposit' => 'acomptes', 'payment' => 'paiements', 'refund' => 'remboursements'][$p['kind']]) ?></small></td><td class="num"><?= (int) $p['n'] ?></td><td class="num"><?= money($p['total']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
</div>
