<?php use Addons\Comptabilite\Compta; require __DIR__ . '/_nav.php'; $o = fn($k, $d = '') => App\Core\Session::old($k, (string) ($edit[$k] ?? $d)); ?>
<div class="row g-3">
<div class="col-lg-8"><div class="card"><div class="table-responsive"><table class="table table-sm mb-0 small cpt-table">
  <thead><tr><th>Code</th><th>Libellé</th><th>Type</th><th>Contrepartie</th><th class="num">Écritures</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $j): ?><tr class="<?= (int) $j['actif'] ? '' : 'text-secondary' ?>"><td class="cpt-mono fw-semibold"><?= e($j['code']) ?></td><td><?= e($j['libelle']) ?><?= (int) $j['actif'] ? '' : ' (désactivé)' ?></td><td><?= e(Compta::JOURNAL_TYPES[$j['type']]) ?></td><td class="cpt-mono"><?= e((string) $j['compte_numero']) ?></td><td class="num"><?= (int) $j['nb'] ?></td>
    <td class="text-end"><a class="btn btn-sm btn-link p-0" href="<?= e(url('/compta/journaux', ['edit' => $j['id']])) ?>"><i class="bi bi-pencil"></i></a></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
  <p class="small text-secondary mt-2">Le compte de contrepartie d'un journal de trésorerie (caisse, banque, mobile money) est proposé par le bouton « Équilibrer » de la saisie. La numérotation des écritures validées est chronologique et propre à chaque journal et à chaque exercice.</p></div>
<div class="col-lg-4"><form method="post" action="<?= e(url($edit ? '/compta/journaux/' . $edit['id'] : '/compta/journaux')) ?>" class="card"><div class="card-body"><?= csrf_field() ?>
  <h3 class="h6"><?= $edit ? 'Modifier le journal ' . e($edit['code']) : 'Nouveau journal' ?></h3>
  <div class="row g-2">
    <div class="col-4"><label class="form-label small mb-0">Code</label><input name="code" class="form-control form-control-sm cpt-mono text-uppercase" required maxlength="8" pattern="[A-Za-z0-9]{1,8}" value="<?= e($o('code')) ?>" placeholder="BQ2"></div>
    <div class="col-8"><label class="form-label small mb-0">Libellé</label><input name="libelle" class="form-control form-control-sm" required maxlength="100" value="<?= e($o('libelle')) ?>" placeholder="Banque Ecobank"></div>
    <div class="col-12"><label class="form-label small mb-0">Type</label><select name="type" class="form-select form-select-sm"><?php foreach (Compta::JOURNAL_TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= $o('type', 'od') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-12"><label class="form-label small mb-0">Compte de contrepartie (trésorerie, facultatif)</label><input name="compte" list="dl-jc" class="form-control form-control-sm" value="<?= e($o('compte', (string) ($edit['compte_numero'] ?? ''))) ?>"></div>
    <div class="col-12 form-check small ms-1"><input type="hidden" name="actif" value="0"><input class="form-check-input" type="checkbox" name="actif" value="1" id="ja" <?= $o('actif', '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="ja">Actif</label></div>
  </div>
  <div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-primary">Enregistrer</button><?php if ($edit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/compta/journaux')) ?>">Nouveau</a><?php endif; ?></div>
</div></form></div>
</div>
<datalist id="dl-jc"><?php foreach ($comptes as $c): if ((int) $c['numero'][0] !== 5) continue; ?><option value="<?= e($c['numero']) ?>"><?= e($c['libelle']) ?></option><?php endforeach; ?></datalist>
