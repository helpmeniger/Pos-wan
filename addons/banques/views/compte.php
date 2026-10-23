<?php use Addons\Banques\BankService as BS; $a = $st['account']; ?>
<link rel="stylesheet" href="<?= e(asset('addons/banques/banques.css')) ?>">
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a href="<?= e(url('/banques')) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <i class="bi <?= e(BS::KIND_ICONS[$a['kind']]) ?> fs-3"></i>
  <div><div class="fs-5 fw-semibold"><?= e($a['name']) ?><?= (int) $a['active'] ? '' : ' <span class="badge bg-secondary">' . e(t('désactivé')) . '</span>' ?></div>
  <div class="small text-secondary"><?= e(t(BS::KINDS[$a['kind']])) ?><?= $a['bank_name'] ? ' · ' . e($a['bank_name']) : '' ?><?= $a['account_number'] ? ' · ' . e(t('N°')) . ' ' . e($a['account_number']) : '' ?><?= $a['holder'] ? ' · ' . e($a['holder']) : '' ?></div></div>
  <div class="ms-auto text-end"><div class="small text-secondary"><?= e(t('Solde actuel')) ?></div><div class="fs-3 fw-bold <?= (float) $a['balance'] < 0 ? 'text-danger' : '' ?>"><?= money($a['balance']) ?></div></div>
</div>
<div class="d-flex flex-wrap gap-2 mb-2 align-items-end">
  <form class="d-flex flex-wrap gap-2 align-items-end" method="get">
    <div><label class="form-label small mb-0"><?= e(t('Du')) ?></label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
    <div><label class="form-label small mb-0"><?= e(t('Au')) ?></label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
    <div><label class="form-label small mb-0"><?= e(t('Opération')) ?></label><select name="type" class="form-select form-select-sm"><option value=""><?= e(t('Toutes')) ?></option><?php foreach (BS::TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i></button>
  </form>
  <?php if (can('reports.export')): ?><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a><a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a><?php endif; ?>
  <?php if (can('banques.operate') && (int) $a['active']): ?><a class="btn btn-sm btn-success ms-auto" href="<?= e(url('/banques', ['op' => 'depot'])) ?>"><i class="bi bi-box-arrow-in-down"></i> <?= e(t('Dépôt')) ?></a><a class="btn btn-sm btn-danger" href="<?= e(url('/banques', ['op' => 'retrait'])) ?>"><i class="bi bi-box-arrow-up"></i> <?= e(t('Retrait')) ?></a><?php endif; ?>
  <?php if (can('banques.manage')): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/banques', ['edit' => $a['id']])) ?>"><i class="bi bi-pencil"></i> <?= e(t('Modifier le compte')) ?></a><?php endif; ?>
</div>
<div class="row g-2 mb-2 small">
  <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><?= e(t('Solde au début')) ?><div class="fw-bold fs-6"><?= money($st['opening']) ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><?= e(t('Entrées')) ?><div class="fw-bold fs-6 text-success"><?= money($st['in']) ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><?= e(t('Sorties')) ?><div class="fw-bold fs-6 text-danger"><?= money($st['out']) ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><?= e(t('Solde à la fin')) ?><div class="fw-bold fs-6"><?= money($st['closing']) ?></div></div></div></div>
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0 small"><thead><tr><th><?= e(t('Date')) ?></th><th>N°</th><th><?= e(t('Opération')) ?></th><th><?= e(t('Mode')) ?></th><th><?= e(t('Personne / libellé')) ?></th><th class="num"><?= e(t('Entrée')) ?></th><th class="num"><?= e(t('Sortie')) ?></th><th class="num"><?= e(t('Solde')) ?></th><th></th></tr></thead><tbody>
<?php foreach ($st['rows'] as $r): $showAccount = false; ?>
  <?php ob_start(); require __DIR__ . '/_op_row.php'; $row = ob_get_clean(); echo substr($row, 0, strrpos($row, '</tr>')); ?>
  <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/banques/operations/' . $r['id'])) ?>" title="<?= e(t('Reçu')) ?>"><i class="bi bi-printer"></i></a>
  <?php if (can('banques.manage') && !$r['cancelled_at']): ?><button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#cx<?= (int) $r['id'] ?>" title="<?= e(t('Annuler')) ?>"><i class="bi bi-x-circle"></i></button><?php endif; ?></td></tr>
  <?php if (can('banques.manage') && !$r['cancelled_at']): ?><tr class="collapse" id="cx<?= (int) $r['id'] ?>"><td colspan="9"><form method="post" action="<?= e(url('/banques/operations/' . $r['id'] . '/annuler')) ?>" class="d-flex gap-2"><?= csrf_field() ?>
    <input name="reason" class="form-control form-control-sm" required minlength="3" maxlength="255" placeholder="<?= e(t('Motif de l\'annulation (obligatoire)')) ?>"><button class="btn btn-sm btn-danger text-nowrap"><?= e(t('Annuler l\'opération')) ?><?= $r['transfer_id'] ? ' ' . e(t('(les deux comptes)')) : '' ?></button></form></td></tr><?php endif; ?>
<?php endforeach; ?>
<?php if (!$st['rows']): ?><tr><td colspan="9" class="text-center text-secondary py-4"><?= e(t('Aucune opération sur cette période.')) ?></td></tr><?php endif; ?>
</tbody></table></div></div>
