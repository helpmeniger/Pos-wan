<?php $st = ['paid' => ['success', 'Payée'], 'cancelled' => ['danger', 'Annulée']][$o['status']] ?? ['secondary', $o['status']]; ?>
<div class="d-flex gap-2 mb-3"><a href="<?= e(url('/restaurant')) ?>" class="btn btn-outline-secondary"><i class="bi bi-grid-3x3"></i> Plan de salle</a><a href="<?= e(url('/restaurant/orders')) ?>" class="btn btn-outline-secondary">Commandes</a><a href="<?= e(url('/restaurant/orders/' . $o['id'] . '/bill')) ?>" class="btn btn-outline-primary" target="_blank" rel="noopener"><i class="bi bi-printer"></i> Addition</a></div>
<div class="row g-3">
  <div class="col-lg-8"><div class="card"><div class="card-header d-flex"><span><?= e($o['number']) ?> · <?= e($o['table_name'] ? 'Table ' . $o['table_name'] : App\Services\RestaurantService::TYPES[$o['type']]) ?></span><span class="ms-auto badge bg-<?= $st[0] ?>"><?= e($st[1]) ?></span></div>
    <table class="table table-sm mb-0"><thead><tr><th>Article</th><th class="num">Qté</th><th class="num">P.U.</th><th>État</th><th>Note / motif</th></tr></thead><tbody>
    <?php foreach ($o['items'] as $i): ?><tr class="<?= $i['status'] === 'cancelled' ? 'text-decoration-line-through text-secondary' : '' ?>"><td><?= e($i['name']) ?></td><td class="num"><?= qty($i['quantity']) ?></td><td class="num"><?= money($i['unit_price']) ?></td><td><?= e($i['status']) ?><?= (int) $i['wasted'] === 1 ? ' (perte)' : '' ?></td><td class="small"><?= e($i['note'] ?? '') ?> <?= e($i['cancel_reason'] ?? '') ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  <div class="col-lg-4"><div class="card"><div class="card-body small">
    Serveur : <b><?= e($o['waiter']) ?></b><br>Couverts : <?= (int) $o['covers'] ?><br>Ouverte : <?= e(fdate($o['opened_at'])) ?><br>Clôturée : <?= e(fdate($o['closed_at'])) ?><?= $o['cancel_reason'] ? '<br>Motif : ' . e($o['cancel_reason']) : '' ?>
    <div class="fs-5 mt-2">Total : <b><?= money($o['total']) ?></b></div>
    <?php foreach ($o['sales'] as $s): ?><div><a href="<?= e(url('/sales/' . $s['id'])) ?>"><?= e($s['number']) ?></a> — <?= money($s['total']) ?> <?= $s['status'] === 'cancelled' ? '(annulée)' : '' ?></div><?php endforeach; ?>
  </div></div></div>
</div>
