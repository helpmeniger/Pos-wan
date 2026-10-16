<?php use Addons\Clinique\ClinicService as CS; $todo = in_array($pr['status'], ['new', 'partial'], true); ?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <a class="btn btn-outline-secondary" href="<?= e(url('/clinique/pharmacie')) ?>"><i class="bi bi-arrow-left"></i> Ordonnances</a>
  <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="<?= e(url('/clinique/ordonnances/' . $pr['id'] . '/print')) ?>"><i class="bi bi-printer"></i> Ordonnance</a>
  <span class="ms-auto"><?= App\Core\View::partial('clinique::_presc_badge', ['st' => $pr['status']]) ?></span>
</div>
<div class="card mb-3"><div class="card-body py-2 small">
  <b><?= e($pr['number']) ?></b> du <?= e(fdate($pr['created_at'])) ?> · Dr <?= e($pr['prescriber']) ?> · Patient : <b><?= e($pr['last_name'] . ' ' . $pr['first_name']) ?></b> (<?= e(implode(', ', array_filter([$pr['sex'], CS::age($pr['birth_date']), $pr['pnum']]))) ?>)
  <?php if ($pr['allergies']): ?><div class="text-danger fw-semibold mt-1"><i class="bi bi-exclamation-octagon"></i> Allergies : <?= e($pr['allergies']) ?></div><?php endif; ?>
  <?php if ($pr['note']): ?><div class="mt-1"><i class="bi bi-chat-left-text"></i> <?= e($pr['note']) ?></div><?php endif; ?>
</div></div>
<form method="post" action="<?= e(url('/clinique/pharmacie/' . $pr['id'])) ?>" data-dispense-form><?= csrf_field() ?><input type="hidden" name="client_uuid" value="<?= e($uuid) ?>">
<div class="row g-3">
  <div class="col-lg-8"><div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Médicament</th><th>Posologie</th><th class="num">Prescrit</th><th class="num">Déjà délivré</th><th class="num">Stock</th><th class="num">Prix</th><th style="width:110px">À délivrer</th></tr></thead><tbody>
  <?php foreach ($items as $i): $left = (int) $i['quantity'] - (int) $i['dispensed_qty']; ?>
    <tr class="<?= !$i['product_id'] ? 'text-secondary' : '' ?>">
      <td><b><?= e($i['drug_name']) ?></b><?= !$i['product_id'] ? '<br><small>hors stock : à acheter ailleurs</small>' : '' ?><?= (int) ($i['requires_prescription'] ?? 0) ? ' <span class="badge bg-danger">Liste</span>' : '' ?></td>
      <td class="small"><?= e($i['posology'] ?? '') ?><?= $i['duration'] ? ' · ' . e($i['duration']) : '' ?></td>
      <td class="num"><?= (int) $i['quantity'] ?></td>
      <td class="num"><?= (int) $i['dispensed_qty'] ?></td>
      <td class="num <?= $i['product_id'] && (float) $i['stock'] < $left ? 'text-danger fw-semibold' : '' ?>"><?= $i['product_id'] ? e(qty($i['stock'])) : '—' ?></td>
      <td class="num"><?= $i['product_id'] ? money($i['sale_price']) : '' ?></td>
      <td><?php if ($i['product_id'] && $left > 0 && $todo): ?><input name="qty[<?= (int) $i['id'] ?>]" data-codes="<?= e(implode('|', array_filter([$i['barcode'] ?? '', $i['reference'] ?? '']))) ?>" data-drug-name="<?= e($i['drug_name']) ?>" value="<?= (int) min($left, max(0, (int) floor((float) $i['stock']))) ?>" class="form-control form-control-sm text-end" inputmode="numeric" data-dq data-price="<?= e((string) (float) $i['sale_price']) ?>" data-max="<?= $left ?>"><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
  <?php if ($sales): ?><div class="card mt-3"><div class="card-header">Délivrances</div><ul class="list-group list-group-flush small"><?php foreach ($sales as $sd): ?><li class="list-group-item d-flex"><a href="<?= e(url('/sales/' . $sd['sale_id'])) ?>"><?= e($sd['number']) ?></a>&nbsp;· <?= e(fdate($sd['created_at'])) ?> · <?= e($sd['full_name']) ?><span class="ms-auto"><?= money($sd['total']) ?></span></li><?php endforeach; ?></ul></div><?php endif; ?>
  </div>
  <div class="col-lg-4">
    <?php if ($todo): ?>
    <div class="card border-success"><div class="card-header bg-success text-white"><i class="bi bi-cash-coin"></i> Délivrer et encaisser</div><div class="card-body">
      <button type="button" class="btn btn-outline-secondary w-100 mb-2" id="dispenseScan"><i class="bi bi-camera"></i> Scanner les boîtes délivrées</button>
      <div class="d-flex justify-content-between mb-2"><span>Total estimé</span><b data-dispense-total>—</b></div>
      <div class="row g-1"><?= App\Core\View::partial('clinique::_pay') ?></div>
      <button class="btn btn-success w-100 mt-2"><i class="bi bi-check2-circle"></i> Valider la délivrance</button>
      <div class="small text-secondary mt-2">Une vente est créée dans la caisse (prix, remises et lots FEFO calculés par le serveur ; le total exact figure sur la vente). Le registre des ordonnances est rempli automatiquement.</div>
    </div></div>
    <?php endif; ?>
  </div>
</div>
</form>
<?php if ($pr['status'] === 'new'): ?>
<form method="post" action="<?= e(url('/clinique/pharmacie/' . $pr['id'] . '/cancel')) ?>" data-confirm="Annuler l'ordonnance <?= e($pr['number']) ?> ?" class="mt-3"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-octagon"></i> Annuler l'ordonnance</button></form>
<?php endif; ?>
