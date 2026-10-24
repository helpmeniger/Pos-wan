<?php use Addons\Comptabilite\Compta; require __DIR__ . '/_nav.php'; $total = array_sum(array_map(fn($s) => count($s['pending']), $st['sources'])); ?>
<div class="card mb-3"><div class="card-body">
  <h2 class="h5"><i class="bi bi-magic"></i> Écritures proposées à partir de la caisse</h2>
  <p class="small text-secondary mb-2">Le logiciel prépare une écriture par source et par journée terminée (depuis le <?= e(fdate($st['from'], false)) ?>, jusqu'à hier) : ventes et annulations, règlements des clients et fournisseurs, réceptions d'achats, recettes des modules (clinique, pressing, établissement), dépenses, opérations des banques. Chaque journée n'est passée qu'une fois. Les écritures arrivent au brouillard : contrôlez-les, corrigez si besoin, puis validez-les. Les comptes utilisés se règlent dans <a href="<?= e(url('/compta/parametres')) ?>">Paramètres comptables</a>.</p>
  <form method="post" action="<?= e(url('/compta/generation')) ?>" data-confirm="Générer les écritures des journées en attente ?">
    <?= csrf_field() ?>
    <div class="table-responsive"><table class="table table-sm align-middle small mb-2"><thead><tr><th></th><th>Source</th><th class="text-end">Journées à passer</th><th class="text-end">Déjà passées</th><th>En période close / hors exercice</th></tr></thead><tbody>
    <?php foreach ($st['sources'] as $k => $s): ?>
      <tr><td><input type="checkbox" class="form-check-input" name="sources[]" value="<?= e($k) ?>" <?= $s['pending'] ? 'checked' : 'disabled' ?>></td>
        <td><i class="bi <?= e($s['icon']) ?> me-1"></i><?= e($s['label']) ?></td>
        <td class="text-end fw-semibold"><?= count($s['pending']) ?><?= $s['pending'] ? '<div class="text-secondary fw-normal">' . e(fdate(reset($s['pending']), false)) . (count($s['pending']) > 1 ? ' → ' . e(fdate(end($s['pending']), false)) : '') . '</div>' : '' ?></td>
        <td class="text-end"><?= (int) $s['done'] ?></td>
        <td><?= $s['closed'] ? count($s['closed']) . ' journée(s) — ' . e(fdate(reset($s['closed']), false)) . (count($s['closed']) > 1 ? ' … ' . e(fdate(end($s['closed']), false)) : '') . ' : créez l\'exercice ou levez le verrouillage' : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <div class="d-flex flex-wrap align-items-center gap-3">
      <label class="form-check-label small"><input type="checkbox" class="form-check-input" name="valider" value="1" <?= setting('compta_validation_directe') === '1' ? 'checked' : '' ?>> Valider directement (sinon : au brouillard, pour contrôle)</label>
      <button class="btn btn-primary ms-auto" <?= $total ? '' : 'disabled' ?>><i class="bi bi-magic"></i> Générer <?= $total ?> écriture(s)</button>
    </div>
  </form>
</div></div>
<div class="card"><div class="card-header">Dernières écritures générées</div><div class="table-responsive"><table class="table table-sm mb-0 small cpt-table"><tbody>
<?php foreach ($last as $r): ?><tr><td><?= e(fdate($r['date_ecriture'], false)) ?></td><td><a href="<?= e(url('/compta/ecritures/' . $r['id'])) ?>"><?= e(Compta::pieceNo($r)) ?></a></td><td><?= e($r['libelle']) ?></td><td class="num"><?= Compta::num((float) $r['total']) ?></td>
  <td><?= $r['statut'] === 'valide' ? '<span class="badge bg-success-subtle text-success-emphasis">validée</span>' : '<span class="badge bg-warning-subtle text-warning-emphasis">brouillard</span>' ?></td></tr><?php endforeach; ?>
<?php if (!$last): ?><tr><td class="text-center text-secondary py-3">Aucune pour l'instant.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="card-footer small text-secondary">Une écriture générée et supprimée au brouillard sera proposée de nouveau. Une journée dont l'écriture est validée ne l'est plus : corrigez-la par une extourne.</div></div>
