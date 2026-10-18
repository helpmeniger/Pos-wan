<?php use Addons\Ecole\Ecole; use Addons\Ecole\Finance; ?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center"><h2 class="h5 mb-0"><?= e($c['nom']) ?> <span class="text-secondary">· <?= e($c['annee']) ?> · <?= count($eleves) ?> <?= e(mb_strtolower(Ecole::apprenant(true))) ?></span></h2>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/ecole/eleves', ['classe' => $c['id']])) ?>"><i class="bi bi-people"></i> Liste</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/ecole/eleves', ['classe' => $c['id'], 'export' => 'pdf'])) ?>"><i class="bi bi-file-pdf"></i> Liste PDF</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/ecole/classes/' . $c['id'] . '/cartes')) ?>"><i class="bi bi-person-vcard"></i> Cartes</a>
    <?php if (can('ecole.presence')): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/ecole/presences/classe/' . $c['id'])) ?>"><i class="bi bi-check2-square"></i> Appel</a><?php endif; ?>
    <?php if (can('ecole.bulletins')): ?><a class="btn btn-sm btn-outline-danger" href="<?= e(url('/ecole/bulletins', ['classe' => $c['id']])) ?>"><i class="bi bi-file-earmark-text"></i> Bulletins</a><?php endif; ?>
  </div></div>
<div class="row g-3">
  <div class="col-xl-7">
    <?php if (can('ecole.manage')): ?>
    <form method="post" action="<?= e(url('/ecole/classes/' . $c['id'] . '/programme')) ?>" class="card mb-3"><?= csrf_field() ?><div class="card-header"><i class="bi bi-book"></i> Programme : matières et coefficients</div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Matière</th><th style="width:90px">Coef.</th><th style="width:90px" title="Université : crédits (ECTS) acquis si moyenne ≥ seuil">Crédits</th><th>Enseignant</th></tr></thead><tbody>
      <?php $prog = array_column($programme, null, 'matiere_id'); foreach ($matieres as $i => $m): $l = $prog[$m['id']] ?? null; ?>
        <tr><td><label><input type="checkbox" name="lignes[<?= $i ?>][matiere_id]" value="<?= (int) $m['id'] ?>" <?= $l ? 'checked' : '' ?>> <?= e($m['nom']) ?></label></td>
          <td><input name="lignes[<?= $i ?>][coefficient]" class="form-control form-control-sm" inputmode="decimal" value="<?= e($l ? rtrim(rtrim((string) $l['coefficient'], '0'), '.') : '1') ?>"></td>
          <td><input name="lignes[<?= $i ?>][credits]" class="form-control form-control-sm" inputmode="decimal" value="<?= e($l && $l['credits'] !== null ? rtrim(rtrim((string) $l['credits'], '0'), '.') : '') ?>"></td>
          <td><select name="lignes[<?= $i ?>][enseignant_id]" class="form-select form-select-sm"><option value="">—</option><?php foreach ($personnel as $p): if ($p['fonction'] !== 'enseignant' && $p['fonction'] !== 'direction') { continue; } ?><option value="<?= (int) $p['id'] ?>" <?= $l && (int) $l['enseignant_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nom'] . ' ' . $p['prenom']) ?></option><?php endforeach; ?></select></td></tr>
      <?php endforeach; ?>
      <?php if (!$matieres): ?><tr><td colspan="4" class="text-secondary">Créez d'abord les matières (Classes et matières).</td></tr><?php endif; ?>
      </tbody></table></div>
      <div class="card-footer"><button class="btn btn-sm btn-primary"><i class="bi bi-check2"></i> Enregistrer le programme</button> <span class="small text-secondary">Une matière déjà évaluée n'est jamais retirée.</span></div>
    </form>
    <?php endif; ?>
    <div class="card"><div class="card-header"><i class="bi bi-people"></i> <?= e(Ecole::apprenant(true)) ?></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody>
      <?php foreach ($eleves as $i => $e): ?><tr><td class="text-secondary small"><?= $i + 1 ?></td><td><a href="<?= e(url('/ecole/eleves/' . $e['id'])) ?>"><?= e($e['nom'] . ' ' . $e['prenom']) ?></a></td><td class="small"><?= e($e['matricule']) ?></td><td class="small"><?= e($e['sexe']) ?></td><td class="small"><?= e(Finance::TYPES_INSCRIPTION[$e['type']]) ?></td></tr><?php endforeach; ?>
      <?php if (!$eleves): ?><tr><td class="text-secondary">Aucun inscrit.</td></tr><?php endif; ?>
      </tbody></table></div></div>
  </div>
  <div class="col-xl-5"><?php if (can('ecole.manage')): ?>
    <form method="post" action="<?= e(url('/ecole/classes/' . $c['id'])) ?>" class="card"><?= csrf_field() ?><div class="card-header"><i class="bi bi-pencil"></i> Classe</div><div class="card-body row g-2 small">
      <div class="col-12"><label class="form-label mb-0">Nom</label><input name="nom" class="form-control" value="<?= e($c['nom']) ?>" required maxlength="80"></div>
      <div class="col-6"><label class="form-label mb-0">Cycle</label><select name="cycle" class="form-select"><?php foreach (Ecole::CYCLES as $k => $l): ?><option value="<?= e($k) ?>" <?= $c['cycle'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label mb-0">Niveau</label><input name="niveau" class="form-control" value="<?= e((string) $c['niveau']) ?>" maxlength="60"></div>
      <div class="col-6"><label class="form-label mb-0">Salle</label><input name="salle" class="form-control" value="<?= e((string) $c['salle']) ?>" maxlength="50"></div>
      <div class="col-6"><label class="form-label mb-0">Capacité</label><input type="number" name="capacite" class="form-control" value="<?= e((string) $c['capacite']) ?>"></div>
      <div class="col-12"><label class="form-label mb-0">Titulaire</label><select name="titulaire_id" class="form-select"><option value="">—</option><?php foreach ($personnel as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $c['titulaire_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nom'] . ' ' . $p['prenom']) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label mb-0">Frais d'inscription</label><input name="frais_inscription" class="form-control" value="<?= e((string) (float) $c['frais_inscription']) ?>"></div>
      <div class="col-6"><label class="form-label mb-0">Scolarité annuelle</label><input name="frais_scolarite" class="form-control" value="<?= e((string) (float) $c['frais_scolarite']) ?>"></div>
      <div class="col-6"><label class="form-label mb-0">Tranches</label><input type="number" name="nb_tranches" min="1" max="12" class="form-control" value="<?= (int) $c['nb_tranches'] ?>"></div>
      <div class="col-6 d-flex align-items-end"><label><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" <?= $c['active'] ? 'checked' : '' ?>> Active</label></div>
      <div class="col-12 form-text">Les nouveaux montants s'appliquent aux prochaines inscriptions ; les échéances déjà créées ne changent pas.</div>
      <div class="col-12"><button class="btn btn-sm btn-primary">Enregistrer</button></div>
    </div></form><?php endif; ?></div>
</div>
