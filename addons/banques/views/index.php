<?php use Addons\Banques\BankService as BS;
$active = array_values(array_filter($accounts, fn($a) => (int) $a['active']));
$opts = fn(?int $sel = null) => implode('', array_map(fn($a) => '<option value="' . (int) $a['id'] . '"' . ($sel === (int) $a['id'] ? ' selected' : '') . '>' . e($a['name']) . ' — ' . e(money($a['balance'])) . '</option>', $active));
$methods = implode('', array_map(fn($k, $l) => '<option value="' . e($k) . '">' . e(t($l)) . '</option>', array_keys(BS::METHODS), BS::METHODS));
$tabs = ['depot' => ['Dépôt', 'bi-box-arrow-in-down'], 'retrait' => ['Retrait', 'bi-box-arrow-up'], 'virement' => ['Virement', 'bi-arrow-left-right'], 'frais' => ['Frais / intérêts', 'bi-percent']];
if (can('banques.manage')) { $tabs += ['ajustement' => ['Ajuster un solde', 'bi-sliders'], 'compte' => ['Nouveau compte', 'bi-plus-circle']]; }
if (!isset($tabs[$tab])) { $tab = 'depot'; }
$recu = (int) ($_GET['recu'] ?? 0);
?>
<link rel="stylesheet" href="<?= e(asset('addons/banques/banques.css')) ?>">
<?php if ($recu): ?><div class="alert alert-success d-flex align-items-center gap-2 py-2"><i class="bi bi-check-circle"></i> <?= e(t('Opération enregistrée.')) ?>
  <a class="btn btn-sm btn-success ms-auto" href="<?= e(url('/banques/operations/' . $recu, ['auto' => 1])) ?>"><i class="bi bi-printer"></i> <?= e(t('Imprimer le reçu')) ?></a></div><?php endif; ?>
<?php if ($checks): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= e(t('Incohérence de solde détectée')) ?> : <?php foreach ($checks as $c): ?><?= e($c['name']) ?> (<?= money($c['balance']) ?> ≠ <?= money($c['computed']) ?>) <?php endforeach; ?></div><?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card bq-total h-100"><div class="card-body"><div class="small text-secondary"><?= e(t('Total hors caisse (comptes actifs)')) ?></div><div class="fs-3 fw-bold"><?= money($total) ?></div><div class="small text-secondary"><?= count($active) ?> <?= e(t('compte(s)')) ?></div></div></div></div>
  <?php if (can('banques.operate')): ?>
  <div class="col-md-4"><a class="card text-decoration-none h-100 <?= $pending ? 'border-warning' : '' ?>" href="<?= e(url('/banques/versements')) ?>"><div class="card-body"><div class="small text-secondary"><?= e(t('Clôtures de caisse à verser')) ?></div><div class="fs-3 fw-bold text-body"><?= money($pendingTotal) ?></div><div class="small <?= $pending ? 'text-warning-emphasis' : 'text-secondary' ?>"><?= count($pending) ?> <?= e(t('clôture(s) non entièrement versée(s)')) ?></div></div></a></div>
  <?php endif; ?>
  <div class="col-md-4 d-flex flex-column gap-2 justify-content-center">
    <a class="btn btn-outline-primary" href="<?= e(url('/banques/historique')) ?>"><i class="bi bi-clock-history"></i> <?= e(t('Historique de toutes les opérations')) ?></a>
    <?php if (can('banques.operate')): ?><a class="btn btn-success" href="<?= e(url('/banques/versements')) ?>"><i class="bi bi-safe"></i> <?= e(t('Verser une clôture de caisse')) ?></a><?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-<?= can('banques.operate') ? '7' : '12' ?>">
    <div class="bq-accounts mb-3">
      <?php foreach ($accounts as $a): ?>
      <a class="card bq-acc text-decoration-none <?= (int) $a['active'] ? '' : 'opacity-50' ?> kind-<?= e($a['kind']) ?>" href="<?= e(url('/banques/comptes/' . $a['id'])) ?>"><div class="card-body py-2">
        <div class="d-flex align-items-center gap-2"><i class="bi <?= e(BS::KIND_ICONS[$a['kind']]) ?> fs-4"></i><div class="flex-grow-1"><div class="fw-semibold text-body"><?= e($a['name']) ?></div>
        <div class="small text-secondary"><?= e(t(BS::KINDS[$a['kind']])) ?><?= $a['bank_name'] ? ' · ' . e($a['bank_name']) : '' ?><?= $a['account_number'] ? ' · ' . e($a['account_number']) : '' ?><?= (int) $a['active'] ? '' : ' · ' . e(t('désactivé')) ?></div></div>
        <div class="fs-5 fw-bold <?= (float) $a['balance'] < 0 ? 'text-danger' : 'text-body' ?>"><?= money($a['balance']) ?></div></div>
      </div></a>
      <?php endforeach; ?>
      <?php if (!$accounts): ?><div class="card"><div class="card-body text-secondary"><?= e(t('Aucun compte. Créez d\'abord vos comptes : banque, coffre, argent gardé par le propriétaire…')) ?><?= can('banques.manage') ? ' <a href="' . e(url('/banques', ['op' => 'compte'])) . '">' . e(t('Nouveau compte')) . '</a>' : '' ?></div></div><?php endif; ?>
    </div>
    <div class="card"><div class="card-header d-flex"><span><i class="bi bi-list-ul"></i> <?= e(t('Dernières opérations')) ?></span><a class="ms-auto small" href="<?= e(url('/banques/historique')) ?>"><?= e(t('Tout voir')) ?></a></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0 small"><thead><tr><th><?= e(t('Date')) ?></th><th>N°</th><th><?= e(t('Compte')) ?></th><th><?= e(t('Opération')) ?></th><th><?= e(t('Libellé')) ?></th><th class="num"><?= e(t('Montant')) ?></th></tr></thead><tbody>
      <?php foreach ($recent as $r) { $showAccount = true; $compact = true; require __DIR__ . '/_op_row.php'; } ?>
      <?php if (!$recent): ?><tr><td colspan="6" class="text-center text-secondary py-3"><?= e(t('Aucune opération.')) ?></td></tr><?php endif; ?>
      </tbody></table></div></div>
  </div>

  <?php if (can('banques.operate')): ?>
  <div class="col-lg-5"><div class="card"><div class="card-header p-1"><ul class="nav nav-pills small flex-wrap">
    <?php foreach ($tabs as $k => [$l, $ic]): ?><li class="nav-item"><a class="nav-link py-1 px-2 <?= $tab === $k ? 'active' : '' ?>" href="<?= e(url('/banques', ['op' => $k])) ?>"><i class="bi <?= e($ic) ?>"></i> <?= e(t($l)) ?></a></li><?php endforeach; ?>
  </ul></div><div class="card-body">
  <?php if ($tab === 'compte' || $edit): $ea = $edit ? (array_values(array_filter($accounts, fn($a) => (int) $a['id'] === $edit))[0] ?? null) : null; ?>
    <form method="post" action="<?= e(url($ea ? '/banques/comptes/' . $ea['id'] : '/banques/comptes')) ?>" class="row g-2"><?= csrf_field() ?>
      <div class="col-12 fw-semibold"><?= e($ea ? t('Modifier le compte') : t('Nouveau compte')) ?></div>
      <div class="col-12"><label class="form-label required"><?= e(t('Nom du compte')) ?></label><input name="name" class="form-control" maxlength="100" required value="<?= e(App\Core\Session::old('name', $ea['name'] ?? '')) ?>" placeholder="ex. BIA Niger — compte courant"></div>
      <div class="col-6"><label class="form-label"><?= e(t('Type')) ?></label><select name="kind" class="form-select"><?php foreach (BS::KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= ($ea['kind'] ?? 'banque') === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label"><?= e(t('Banque / opérateur')) ?></label><input name="bank_name" class="form-control" maxlength="100" value="<?= e($ea['bank_name'] ?? '') ?>"></div>
      <div class="col-6"><label class="form-label"><?= e(t('N° de compte')) ?></label><input name="account_number" class="form-control" maxlength="60" value="<?= e($ea['account_number'] ?? '') ?>"></div>
      <div class="col-6"><label class="form-label"><?= e(t('Titulaire / gardien')) ?></label><input name="holder" class="form-control" maxlength="120" value="<?= e($ea['holder'] ?? '') ?>"></div>
      <?php if (!$ea): ?><div class="col-6"><label class="form-label"><?= e(t('Solde actuel')) ?></label><input name="opening_balance" class="form-control text-end" inputmode="decimal" value="0"></div><?php endif; ?>
      <div class="col-<?= $ea ? '12' : '6' ?>"><label class="form-label"><?= e(t('Note')) ?></label><input name="note" class="form-control" maxlength="255" value="<?= e($ea['note'] ?? '') ?>"></div>
      <?php if ($ea): ?><div class="col-12"><label><input type="checkbox" name="active" value="1" <?= (int) $ea['active'] ? 'checked' : '' ?>> <?= e(t('Compte actif')) ?></label></div><?php endif; ?>
      <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-check2"></i> <?= e(t('Enregistrer')) ?></button></div>
    </form>
  <?php elseif (!$active): ?>
    <div class="text-secondary"><?= e(t('Créez d\'abord un compte.')) ?></div>
  <?php elseif ($tab === 'virement'): ?>
    <form method="post" action="<?= e(url('/banques/virements')) ?>" class="row g-2"><?= csrf_field() ?>
      <div class="col-12"><label class="form-label required"><?= e(t('Depuis le compte')) ?></label><select name="from_account_id" class="form-select" required><?= $opts() ?></select></div>
      <div class="col-12"><label class="form-label required"><?= e(t('Vers le compte')) ?></label><select name="to_account_id" class="form-select" required><?= $opts($active[1]['id'] ?? null) ?></select></div>
      <?php require __DIR__ . '/_op_fields.php'; ?>
      <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-arrow-left-right"></i> <?= e(t('Enregistrer le virement')) ?></button></div>
    </form>
  <?php elseif ($tab === 'ajustement'): ?>
    <form method="post" action="<?= e(url('/banques/ajustements')) ?>" class="row g-2"><?= csrf_field() ?>
      <div class="col-12 small text-secondary"><?= e(t('Après comparaison avec le relevé de la banque : le solde est corrigé par une opération « Ajustement » visible dans l\'historique.')) ?></div>
      <div class="col-12"><label class="form-label required"><?= e(t('Compte')) ?></label><select name="account_id" class="form-select" required><?= $opts() ?></select></div>
      <div class="col-6"><label class="form-label required"><?= e(t('Solde réel')) ?></label><input name="new_balance" class="form-control text-end" inputmode="decimal" required></div>
      <div class="col-6"><label class="form-label"><?= e(t('Date')) ?></label><input type="date" name="op_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>"></div>
      <div class="col-12"><label class="form-label required"><?= e(t('Motif')) ?></label><input name="description" class="form-control" maxlength="255" required placeholder="ex. relevé BIA du 30/09"></div>
      <div class="col-12"><button class="btn btn-warning w-100"><?= e(t('Ajuster le solde')) ?></button></div>
    </form>
  <?php else: $type = $tab === 'frais' ? 'frais' : $tab; ?>
    <form method="post" action="<?= e(url('/banques/operations')) ?>" class="row g-2"><?= csrf_field() ?>
      <?php if ($tab === 'frais'): ?><div class="col-12"><label class="form-label"><?= e(t('Opération')) ?></label><select name="type" class="form-select"><option value="frais"><?= e(t('Frais bancaires (sortie)')) ?></option><option value="interets"><?= e(t('Intérêts (entrée)')) ?></option></select></div>
      <?php else: ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
      <div class="col-12"><label class="form-label required"><?= e(t('Compte')) ?></label><select name="account_id" class="form-select" required><?= $opts() ?></select></div>
      <?php $personLabel = $tab === 'retrait' ? 'Bénéficiaire / retiré par' : 'Déposé par'; require __DIR__ . '/_op_fields.php'; ?>
      <div class="col-12"><button class="btn btn-<?= $tab === 'retrait' ? 'danger' : 'primary' ?> w-100"><i class="bi <?= e($tabs[$tab][1]) ?>"></i> <?= e(t($tab === 'retrait' ? 'Enregistrer le retrait' : ($tab === 'frais' ? 'Enregistrer' : 'Enregistrer le dépôt'))) ?></button></div>
    </form>
  <?php endif; ?>
  </div></div></div>
  <?php endif; ?>
</div>
