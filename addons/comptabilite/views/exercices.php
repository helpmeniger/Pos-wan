<?php use Addons\Comptabilite\Compta; require __DIR__ . '/_nav.php'; ?>
<div class="row g-3">
<div class="col-xl-8">
<?php foreach ($rows as $x): $issues = $checks[$x['id']]; ?>
  <div class="card mb-2"><div class="card-body">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <div><div class="fw-semibold"><?= e($x['libelle']) ?> <span class="cpt-mono text-secondary">(<?= e($x['code']) ?>)</span> <?= $x['statut'] === 'cloture' ? '<span class="badge bg-secondary">clôturé</span>' : '<span class="badge bg-success">ouvert</span>' ?></div>
        <div class="small text-secondary">Du <?= e(fdate($x['date_debut'], false)) ?> au <?= e(fdate($x['date_fin'], false)) ?> · <?= (int) $x['nb'] ?> écriture(s)<?= (int) $x['nb_brouillard'] ? ', dont ' . (int) $x['nb_brouillard'] . ' au brouillard' : '' ?>
        <?= $x['statut'] === 'cloture' ? ' · clôturé le ' . e(fdate($x['cloture_le'])) . ' · résultat ' . money($x['resultat']) : '' ?></div></div>
      <div class="ms-auto d-flex gap-1"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/compta/etats/balance', ['exercice' => $x['id']])) ?>">Balance</a>
        <?php if ($x['statut'] === 'ouvert'): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/compta/exercices', ['edit' => $x['id']])) ?>"><i class="bi bi-pencil"></i></a><?php endif; ?></div>
    </div>
    <?php if ($x['statut'] === 'ouvert'): ?>
    <div class="row g-2 mt-1">
      <div class="col-md-5"><form method="post" action="<?= e(url('/compta/exercices/' . $x['id'] . '/verrou')) ?>" class="border rounded p-2 small h-100"><?= csrf_field() ?>
        <div class="fw-semibold mb-1"><i class="bi bi-lock"></i> Verrouiller une période</div>
        <div class="text-secondary mb-1">Plus aucune écriture ne peut être ajoutée, modifiée ou validée jusqu'à cette date (clôture mensuelle, déclaration de TVA faite…).</div>
        <div class="d-flex gap-1"><input type="date" name="verrou_au" class="form-control form-control-sm" min="<?= e($x['date_debut']) ?>" max="<?= e($x['date_fin']) ?>" value="<?= e((string) $x['verrou_au']) ?>"><button class="btn btn-sm btn-outline-primary">Appliquer</button></div>
        <?php if ($x['verrou_au']): ?><div class="mt-1">Verrouillé jusqu'au <b><?= e(fdate($x['verrou_au'], false)) ?></b> — videz la date et appliquez pour lever le verrou.</div><?php endif; ?>
      </form></div>
      <div class="col-md-7"><form method="post" action="<?= e(url('/compta/exercices/' . $x['id'] . '/cloturer')) ?>" class="border rounded p-2 small h-100 <?= $issues ? 'border-warning' : 'border-danger' ?>" data-confirm="Clôturer définitivement l'exercice <?= e($x['code']) ?> ? Une sauvegarde complète est faite d'abord ; l'exercice ne pourra plus recevoir d'écritures."><?= csrf_field() ?>
        <div class="fw-semibold mb-1"><i class="bi bi-door-closed"></i> Clôture de l'exercice</div>
        <?php if ($issues): ?><ul class="mb-0 text-warning-emphasis"><?php foreach ($issues as $i): ?><li><?= e($i) ?></li><?php endforeach; ?></ul>
        <?php else: ?>
          <div class="text-secondary mb-1">Reporte les soldes des comptes de bilan (classes 1 à 5) en à-nouveaux au premier jour de l'exercice suivant (créé s'il n'existe pas), détaillés ligne par ligne pour les comptes lettrables non lettrés, et le résultat au compte <?= e(implode(' / ', Addons\Comptabilite\Plans::RESULTAT[Compta::referentiel()])) ?>. Irréversible.</div>
          <div class="d-flex gap-1"><input name="confirmation" class="form-control form-control-sm" placeholder="Tapez CLOTURER" required pattern="CLOTURER" autocomplete="off"><button class="btn btn-sm btn-danger text-nowrap">Clôturer</button></div>
        <?php endif; ?>
      </form></div>
    </div>
    <?php endif; ?>
  </div></div>
<?php endforeach; ?>
</div>
<div class="col-xl-4"><form method="post" action="<?= e(url($edit ? '/compta/exercices/' . $edit['id'] : '/compta/exercices')) ?>" class="card"><div class="card-body"><?= csrf_field() ?>
  <h3 class="h6"><?= $edit ? 'Modifier l\'exercice ' . e($edit['code']) : 'Nouvel exercice' ?></h3>
  <?php $d0 = $edit['date_debut'] ?? $nextStart; $d1 = $edit['date_fin'] ?? date('Y-m-d', strtotime($nextStart . ' +1 year -1 day')); $code = $edit['code'] ?? substr($d1, 0, 4); ?>
  <div class="row g-2">
    <div class="col-6"><label class="form-label small mb-0">Code</label><input name="code" class="form-control form-control-sm" required maxlength="20" value="<?= e($code) ?>"></div>
    <div class="col-6"><label class="form-label small mb-0">Libellé</label><input name="libelle" class="form-control form-control-sm" maxlength="100" value="<?= e($edit['libelle'] ?? 'Exercice ' . $code) ?>"></div>
    <div class="col-6"><label class="form-label small mb-0">Début</label><input type="date" name="date_debut" class="form-control form-control-sm" required value="<?= e($d0) ?>"></div>
    <div class="col-6"><label class="form-label small mb-0">Fin</label><input type="date" name="date_fin" class="form-control form-control-sm" required value="<?= e($d1) ?>"></div>
  </div>
  <div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-primary">Enregistrer</button><?php if ($edit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/compta/exercices')) ?>">Annuler</a><?php endif; ?></div>
  <p class="small text-secondary mt-2 mb-0">Un exercice dure en principe 12 mois (24 au plus). L'exercice suivant est aussi créé automatiquement à la clôture.</p>
</div></form></div>
</div>
