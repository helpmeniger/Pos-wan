<?php use Addons\Banques\BankService as BS; $recu = (int) ($_GET['recu'] ?? 0); ?>
<link rel="stylesheet" href="<?= e(asset('addons/banques/banques.css')) ?>">
<?php if ($recu): ?><div class="alert alert-success d-flex align-items-center gap-2 py-2"><i class="bi bi-check-circle"></i> <?= e(t('Versement enregistré.')) ?>
  <a class="btn btn-sm btn-success ms-auto" href="<?= e(url('/banques/operations/' . $recu, ['auto' => 1])) ?>"><i class="bi bi-printer"></i> <?= e(t('Imprimer le reçu')) ?></a></div><?php endif; ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <a href="<?= e(url('/banques')) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div class="small text-secondary"><?= e(t('Argent compté à la clôture de chaque caisse : versez-le à la banque, au coffre ou au propriétaire. Le reste à verser comprend le fond de caisse éventuellement laissé dans le tiroir.')) ?></div>
  <a class="btn btn-sm <?= $all ? 'btn-dark' : 'btn-outline-secondary' ?> ms-auto" href="<?= e(url('/banques/versements', $all ? [] : ['tout' => 1])) ?>"><?= e($all ? t('Seulement à verser') : t('Toutes les clôtures')) ?></a>
</div>
<?php if (!$accounts): ?><div class="alert alert-warning"><?= e(t('Créez d\'abord un compte (banque, coffre, propriétaire).')) ?> <a href="<?= e(url('/banques', ['op' => 'compte'])) ?>"><?= e(t('Nouveau compte')) ?></a></div><?php endif; ?>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0 small"><thead><tr><th><?= e(t('Clôture')) ?></th><th><?= e(t('Caisse')) ?></th><th><?= e(t('Caissier')) ?></th><th class="num"><?= e(t('Compté')) ?></th><th class="num"><?= e(t('Écart')) ?></th><th class="num"><?= e(t('Déjà versé')) ?></th><th class="num"><?= e(t('Reste')) ?></th><th style="min-width:420px"><?= e(t('Verser')) ?></th></tr></thead><tbody>
<?php foreach ($sessions as $s): ?>
  <tr>
    <td class="text-nowrap">n° <?= (int) $s['id'] ?><br><span class="text-secondary"><?= e(fdate($s['closed_at'])) ?></span></td>
    <td><?= e($s['register_name']) ?></td>
    <td><?= e($s['cashier']) ?><?= $s['closed_name'] && $s['closed_name'] !== $s['cashier'] ? '<br><span class="text-secondary">' . e(t('clôturée par')) . ' ' . e($s['closed_name']) . '</span>' : '' ?></td>
    <td class="num"><?= money($s['available']) ?><?= $s['counted_amount'] === null ? '<br><span class="text-warning-emphasis">' . e(t('attendu (non compté)')) . '</span>' : '' ?></td>
    <td class="num <?= (float) $s['difference'] < 0 ? 'text-danger' : '' ?>"><?= $s['difference'] !== null ? money($s['difference']) : '—' ?></td>
    <td class="num"><?= money($s['deposited']) ?></td>
    <td class="num fw-bold <?= $s['remaining'] > 0 ? 'text-primary' : 'text-success' ?>"><?= $s['remaining'] > 0 ? money($s['remaining']) : e(t('Versée')) ?></td>
    <td><?php if ($s['remaining'] > 0 && $accounts): ?>
      <form method="post" action="<?= e(url('/banques/versements/' . $s['id'])) ?>" class="d-flex flex-wrap gap-1"><?= csrf_field() ?>
        <select name="account_id" class="form-select form-select-sm" style="width:12rem" required><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select>
        <input name="amount" class="form-control form-control-sm text-end" style="width:8rem" inputmode="decimal" value="<?= e(number_format($s['remaining'], 0, '', '')) ?>" required>
        <select name="method" class="form-select form-select-sm" style="width:7rem"><?php foreach (BS::METHODS as $k => $l): ?><option value="<?= e($k) ?>"><?= e(t($l)) ?></option><?php endforeach; ?></select>
        <input name="reference" class="form-control form-control-sm" style="width:8rem" maxlength="80" placeholder="<?= e(t('N° bordereau')) ?>">
        <input name="person" class="form-control form-control-sm" style="width:9rem" maxlength="120" placeholder="<?= e(t('Versé par')) ?>">
        <button class="btn btn-sm btn-success"><i class="bi bi-box-arrow-in-down"></i> <?= e(t('Verser')) ?></button>
      </form>
    <?php endif; ?></td>
  </tr>
<?php endforeach; ?>
<?php if (!$sessions): ?><tr><td colspan="8" class="text-center text-secondary py-4"><?= e(t('Toutes les clôtures de caisse ont été versées.')) ?></td></tr><?php endif; ?>
</tbody></table></div></div>
