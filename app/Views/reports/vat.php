<?php $lbl = App\Services\Tax::label(); $t = $vat['total']; ?>
<?= App\Core\View::partial('reports/_filters', get_defined_vars()) ?>
<div class="alert alert-light border small d-print-none">
  Mode actuel : <b><?= e(App\Services\Tax::MODES[App\Services\Tax::mode()]) ?></b><?= App\Services\Tax::mode() === 'general' ? ' — ' . e(App\Services\Tax::rateLabel(App\Services\Tax::generalRate())) : '' ?>.
  Les prix sont TTC : la <?= e($lbl) ?> de chaque ligne est extraite du montant (TTC × taux ÷ (100 + taux)) au moment de la vente.
  Ventes annulées exclues ; retours déduits à leur date. <?php if (can('settings.manage')): ?><a href="<?= e(url('/settings')) ?>">Paramétrer la <?= e($lbl) ?></a><?php endif; ?>
</div>
<div class="row g-3 mb-3">
  <?php foreach ([['Ventes TTC', $t['ttc'], 'primary'], ['Retours TTC', -$t['ret_ttc'], 'warning'], ['Base HT nette', $t['net_ht'], 'secondary'], [$lbl . ' collectée nette', $t['net_tax'], 'success']] as [$k, $v, $c]): ?>
    <div class="col-6 col-lg-3"><div class="card border-<?= $c ?>"><div class="card-body py-2"><div class="small text-secondary"><?= e($k) ?></div><div class="fs-4 fw-bold"><?= money($v) ?></div></div></div></div>
  <?php endforeach; ?>
</div>
<div class="row g-3">
  <div class="col-12"><div class="card"><div class="card-header d-flex"><span>Par taux</span><?php if (can('reports.export')): ?><span class="ms-auto d-print-none"><a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a></span><?php endif; ?></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Taux</th><th class="num">Ventes TTC</th><th class="num">Retours</th><th class="num">Base HT</th><th class="num"><?= e($lbl) ?></th><th class="num">TTC net</th></tr></thead><tbody>
      <?php foreach ($vat['rates'] as $r): ?><tr><td><?= $r['rate'] > 0 ? e(App\Services\Tax::rateLabel($r['rate'])) : 'Exonéré / 0 %' ?></td><td class="num"><?= money($r['ttc']) ?></td><td class="num"><?= $r['ret_ttc'] > 0 ? '− ' . money($r['ret_ttc']) : '' ?></td><td class="num"><?= money($r['net_ht']) ?></td><td class="num fw-semibold"><?= money($r['net_tax']) ?></td><td class="num"><?= money($r['net_ttc']) ?></td></tr><?php endforeach; ?>
      <?php if (!$vat['rates']): ?><tr><td colspan="6" class="text-center text-secondary py-3">Aucune vente sur la période.</td></tr><?php endif; ?>
    </tbody><tfoot><tr class="fw-bold"><td>TOTAL</td><td class="num"><?= money($t['ttc']) ?></td><td class="num"><?= $t['ret_ttc'] > 0 ? '− ' . money($t['ret_ttc']) : '' ?></td><td class="num"><?= money($t['net_ht']) ?></td><td class="num"><?= money($t['net_tax']) ?></td><td class="num"><?= money($t['net_ttc']) ?></td></tr></tfoot></table></div></div></div>
  <div class="col-12"><div class="card"><div class="card-header d-flex"><span>Par mois</span><?php if (can('reports.export')): ?><span class="ms-auto d-print-none"><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv', 'by' => 'month'])) ?>"><i class="bi bi-filetype-csv"></i></a> <a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf', 'by' => 'month'])) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a></span><?php endif; ?></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Mois</th><th class="num">Ventes TTC</th><th class="num">Retours</th><th class="num">Base HT</th><th class="num"><?= e($lbl) ?></th></tr></thead><tbody>
      <?php foreach ($vat['months'] as $m): ?><tr><td><?= e(substr($m['m'], 5, 2) . '/' . substr($m['m'], 0, 4)) ?></td><td class="num"><?= money($m['ttc']) ?></td><td class="num"><?= $m['ret_ttc'] > 0 ? '− ' . money($m['ret_ttc']) : '' ?></td><td class="num"><?= money($m['net_ht']) ?></td><td class="num fw-semibold"><?= money($m['net_tax']) ?></td></tr><?php endforeach; ?>
      <?php if (!$vat['months']): ?><tr><td colspan="5" class="text-center text-secondary py-3">—</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
</div>
<p class="small text-secondary mt-3">Document d'aide à la déclaration : à rapprocher de votre comptabilité. POS Marwa ne transmet rien à l'administration fiscale.</p>
