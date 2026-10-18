<?php use Addons\Ecole\Notes; $annuelPossible = true; $f = fn($v) => $v === null ? '—' : number_format((float) $v, 2, ',', ' ');
$pq = $annuel ? 'annee' : $periode; $univ = $res && array_filter($res['matieres'], fn($m) => $m['credits'] !== null); ?>
<?php if (!$annee || !$classes): ?><div class="alert alert-warning">Créez d'abord l'année scolaire et les classes.</div><?php return; endif; ?>
<?php include __DIR__ . '/_choix.php'; ?>
<?php if ($res): $s = $res['stat']; $rows = $res['eleves']; uasort($rows, fn($a, $b) => [$a['rang'] ?? 9999, $a['eleve']['nom'], $a['eleve']['prenom']] <=> [$b['rang'] ?? 9999, $b['eleve']['nom'], $b['eleve']['prenom']]); ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-2">
  <div class="small">Effectif <b><?= (int) $s['effectif'] ?></b> · classés <b><?= (int) $s['classes'] ?></b> · moyenne de la classe <b><?= $f($s['moy']) ?></b> · plus faible <?= $f($s['min']) ?> · plus forte <?= $f($s['max']) ?> · moyenne ≥ <?= e(setting('ecole_seuil_passage', '10')) ?> : <b><?= (int) $s['admis'] ?></b></div>
  <div class="ms-auto d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(query_with(['export' => 'csv'])) ?>">CSV</a><a class="btn btn-sm btn-outline-secondary" href="<?= e(query_with(['export' => 'pdf'])) ?>">PDF</a>
    <?php if ($s['classes']): ?><a class="btn btn-sm btn-danger" href="<?= e(url('/ecole/bulletins/imprimer', ['classe' => $classe, 'periode' => $pq])) ?>"><i class="bi bi-printer"></i> Imprimer tous les bulletins</a><?php endif; ?>
  </div>
</div>
<form method="post" action="<?= e(url('/ecole/bulletins/appreciations')) ?>" class="card"><?= csrf_field() ?>
  <input type="hidden" name="classe_id" value="<?= (int) $classe ?>"><input type="hidden" name="periode_id" value="<?= (int) $periode ?>">
  <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 ec-resultats"><thead><tr><th>Rang</th><th>Nom et prénom(s)</th>
    <?php foreach ($res['matieres'] as $m): ?><th class="text-center small" title="<?= e($m['nom']) ?> · coefficient <?= e(Addons\Ecole\Ecole::nb($m['coefficient'])) ?>"><?= e($m['code'] ?: mb_substr($m['nom'], 0, 6)) ?><br><span class="text-secondary">×<?= e(Addons\Ecole\Ecole::nb($m['coefficient'])) ?></span></th><?php endforeach; ?>
    <th class="text-center">Moy.</th><?php if ($univ): ?><th class="text-center">Crédits</th><?php endif; ?><?php if (!$annuel): ?><th>Appréciation du conseil · conduite · décision</th><?php endif; ?><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $el = $r['eleve']; $b = $app[$el['inscription_id']] ?? []; ?>
    <tr><td class="text-nowrap"><?= $r['rang'] ? '<b>' . (int) $r['rang'] . '</b>' . ($r['rang'] === 1 ? '<sup>er</sup>' : '<sup>e</sup>') . ($r['ex_aequo'] ? ' <span class="small text-secondary">ex</span>' : '') : '—' ?></td>
      <td><a href="<?= e(url('/ecole/eleves/' . $el['id'])) ?>"><?= e($el['nom'] . ' ' . $el['prenom']) ?></a></td>
      <?php foreach ($res['matieres'] as $m): $v = $r['matieres'][(int) $m['matiere_id']]; ?><td class="text-center <?= $v !== null && $v < 10 ? 'text-danger' : '' ?>"><?= $f($v) ?></td><?php endforeach; ?>
      <td class="text-center fw-bold <?= $r['moyenne'] !== null && $r['moyenne'] < (float) setting('ecole_seuil_passage', '10') ? 'text-danger' : '' ?>" title="<?= e(Notes::appreciation($r['moyenne'])) ?>"><?= $f($r['moyenne']) ?></td>
      <?php if ($univ): ?><td class="text-center"><?= e(Addons\Ecole\Ecole::nb($r['credits'])) ?></td><?php endif; ?>
      <?php if (!$annuel): ?><td><div class="d-flex gap-1"><input name="b[<?= (int) $el['inscription_id'] ?>][appreciation]" class="form-control form-control-sm" maxlength="500" value="<?= e((string) ($b['appreciation'] ?? '')) ?>" placeholder="<?= e(Notes::appreciation($r['moyenne'])) ?>">
        <input name="b[<?= (int) $el['inscription_id'] ?>][conduite]" class="form-control form-control-sm" style="max-width:110px" maxlength="100" value="<?= e((string) ($b['conduite'] ?? '')) ?>" placeholder="Conduite">
        <input name="b[<?= (int) $el['inscription_id'] ?>][decision]" class="form-control form-control-sm" style="max-width:130px" maxlength="100" value="<?= e((string) ($b['decision'] ?? '')) ?>" placeholder="Décision" list="ecDecisions"></div></td><?php endif; ?>
      <td><?php if ($r['moyenne'] !== null): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/ecole/bulletins/imprimer', ['classe' => $classe, 'periode' => $pq, 'eleve' => $el['id']])) ?>" title="Imprimer le bulletin"><i class="bi bi-printer"></i></a><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="99" class="text-secondary text-center py-3">Aucun inscrit dans cette classe.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if (!$annuel && $rows): ?><div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Enregistrer les appréciations</button> <span class="small text-secondary">Laissée vide, l'appréciation est déduite de la moyenne sur le bulletin.</span></div><?php endif; ?>
  <datalist id="ecDecisions"><option value="Admis(e) en classe supérieure"><option value="Redouble"><option value="Exclu(e)"><option value="Avertissement"><option value="Blâme"><option value="Session validée"><option value="Ajourné(e)"></datalist>
</form>
<?php endif; ?>
