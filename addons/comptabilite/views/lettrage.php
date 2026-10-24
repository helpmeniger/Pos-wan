<?php
use Addons\Comptabilite\Compta;
require __DIR__ . '/_nav.php';
$retour = http_build_query(array_filter(['compte' => $compte['numero'] ?? '', 'tiers' => $tiers['code'] ?? '', 'mode' => $mode, 'exercice' => $ex['id'], 'tous' => App\Core\Request::query('tous')]));
$canL = $compte && (int) $compte['lettrable'] && can('compta.valider');
$td = $tc = 0.0;
$codes = [];
foreach ($lines as $l) { $td += (float) $l['debit']; $tc += (float) $l['credit']; if ($l['lettrage']) { $codes[$l['lettrage']] = true; } }
?>
<form method="get" class="card mb-2"><div class="card-body py-2"><div class="row g-2 align-items-end small">
  <div class="col-md-4"><label class="form-label mb-0">Compte</label><input name="compte" list="dl-lc" class="form-control form-control-sm" value="<?= e($compte ? $compte['numero'] . ' — ' . $compte['libelle'] : (string) App\Core\Request::query('compte', '')) ?>" placeholder="N° ou nom du compte" required></div>
  <?php if ($tiersList): ?><div class="col-md-3"><label class="form-label mb-0">Tiers</label><select name="tiers" class="form-select form-select-sm"><option value="">Tous les tiers</option><?php foreach ($tiersList as $t): ?><option value="<?= e($t['code']) ?>" <?= ($tiers['code'] ?? '') === $t['code'] ? 'selected' : '' ?>><?= e($t['code'] . ' — ' . $t['nom']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="col-6 col-md-2"><label class="form-label mb-0">Lignes</label><select name="mode" class="form-select form-select-sm"><option value="toutes">Toutes</option><option value="non_lettrees" <?= $mode === 'non_lettrees' ? 'selected' : '' ?>>Non lettrées</option></select></div>
  <div class="col-6 col-md-2"><label class="form-label mb-0">Exercice</label><select name="exercice" class="form-select form-select-sm"><?php foreach ($exercices as $x): ?><option value="<?= (int) $x['id'] ?>" <?= (int) $x['id'] === (int) $ex['id'] ? 'selected' : '' ?>><?= e($x['code']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-1"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-search"></i></button></div>
  <div class="col-12"><label class="form-check-label"><input type="checkbox" class="form-check-input" name="tous" value="1" <?= App\Core\Request::query('tous') ? 'checked' : '' ?>> Tous les exercices (sinon : l'exercice choisi, à-nouveaux compris)</label></div>
</div></div></form>
<datalist id="dl-lc"><?php foreach ($comptes as $c): ?><option value="<?= e($c['numero'] . ' — ' . $c['libelle']) ?>"></option><?php endforeach; ?></datalist>
<?php if (!$compte): ?>
  <div class="card"><div class="card-body text-secondary">Choisissez un compte pour voir ses mouvements et son solde. Les comptes de tiers (clients 411, fournisseurs 401…) se lettrent : on rapproche une facture et son règlement pour savoir ce qui reste dû.</div></div>
<?php else: ?>
<div class="d-flex flex-wrap align-items-center gap-3 mb-2">
  <div class="fs-5 fw-semibold"><span class="cpt-mono"><?= e($compte['numero']) ?></span> <?= e($compte['libelle']) ?><?= $tiers ? ' · ' . e($tiers['code'] . ' ' . $tiers['nom']) : '' ?></div>
  <div class="small">Débit <b><?= Compta::num($td) ?></b> · Crédit <b><?= Compta::num($tc) ?></b> · Solde <b class="<?= $td - $tc < 0 ? 'text-danger' : '' ?>"><?= Compta::num(abs($td - $tc)) ?: '0' ?> <?= $td - $tc > 0.004 ? 'débiteur' : ($td - $tc < -0.004 ? 'créditeur' : '') ?></b></div>
  <?php if (!(int) $compte['lettrable']): ?><span class="badge bg-secondary">compte non lettrable</span><?php endif; ?>
  <a class="btn btn-sm btn-outline-secondary ms-auto" href="<?= e(url('/compta/etats/grand-livre', ['exercice' => $ex['id'], 'du_compte' => $compte['numero'], 'au_compte' => $compte['numero']])) ?>"><i class="bi bi-book"></i> Grand livre</a>
</div>
<form method="post" action="<?= e(url('/compta/lettrage')) ?>" data-cpt-lettrage>
  <?= csrf_field() ?><input type="hidden" name="retour" value="<?= e($retour) ?>">
  <div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 small cpt-table">
    <thead><tr><th></th><th>Date</th><th>N°</th><th>Pièce</th><th>Tiers</th><th>Libellé</th><th>Échéance</th><th class="num">Débit</th><th class="num">Crédit</th><th class="num">Solde</th><th>Let.</th></tr></thead><tbody>
    <?php $s = 0.0; foreach ($lines as $l): $s += (float) $l['debit'] - (float) $l['credit']; ?>
      <tr class="<?= $l['lettrage'] ? 'text-secondary' : '' ?>">
        <td><?php if ($canL && !$l['lettrage'] && $l['statut'] === 'valide'): ?><input type="checkbox" class="form-check-input" name="lignes[]" value="<?= (int) $l['id'] ?>" data-d="<?= (float) $l['debit'] ?>" data-c="<?= (float) $l['credit'] ?>"><?php endif; ?></td>
        <td class="text-nowrap"><?= e(fdate($l['date_ecriture'], false)) ?></td>
        <td class="text-nowrap"><a href="<?= e(url('/compta/ecritures/' . $l['ecriture_id'])) ?>"><?= $l['numero'] ? e($l['journal_code'] . '-' . str_pad((string) $l['numero'], 5, '0', STR_PAD_LEFT)) : 'Brouillard' ?></a></td>
        <td><?= e((string) $l['piece']) ?></td><td><?= e((string) $l['tiers_code']) ?></td><td><?= e($l['libelle']) ?></td><td><?= $l['echeance'] ? e(fdate($l['echeance'], false)) : '' ?></td>
        <td class="num"><?= Compta::num((float) $l['debit']) ?></td><td class="num"><?= Compta::num((float) $l['credit']) ?></td><td class="num"><?= Compta::num($s) ?: '0' ?></td>
        <td class="cpt-lettre"><?= e((string) $l['lettrage']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lines): ?><tr><td colspan="11" class="text-center text-secondary py-4">Aucun mouvement sur ce compte pour ces critères.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  <?php if ($canL): ?><div class="cpt-sel-bar d-flex flex-wrap align-items-center gap-2 mt-2"><span data-sel-total class="text-secondary"></span>
    <button class="btn btn-sm btn-success ms-auto" data-sel-submit disabled><i class="bi bi-link-45deg"></i> Lettrer la sélection</button></div><?php endif; ?>
</form>
<?php if ($codes && can('compta.valider')): ?>
<form method="post" action="<?= e(url('/compta/lettrage/delettrer')) ?>" class="d-flex flex-wrap gap-2 align-items-end mt-2 small" data-confirm="Supprimer ce lettrage ? Les lignes redeviendront non lettrées.">
  <?= csrf_field() ?><input type="hidden" name="retour" value="<?= e($retour) ?>"><input type="hidden" name="compte_id" value="<?= (int) $compte['id'] ?>">
  <div><label class="form-label mb-0">Délettrer le code</label><select name="code" class="form-select form-select-sm"><?php foreach (array_keys($codes) as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-sm btn-outline-danger">Délettrer</button>
</form>
<?php endif; ?>
<?php endif; ?>
<script src="<?= e(asset('addons/comptabilite/compta.js')) ?>"></script>
