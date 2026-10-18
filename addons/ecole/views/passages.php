<?php use Addons\Ecole\Presences; $st = ['inconnu' => ['Inconnu', 'bg-warning text-dark'], 'reconnu' => ['Reconnu', 'bg-success'], 'confirme' => ['Confirmé', 'bg-primary'], 'corrige' => ['Corrigé', 'bg-info text-dark'], 'ignore' => ['Ignoré', 'bg-secondary']]; ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <div class="btn-group btn-group-sm"><?php foreach (['inconnu' => 'À vérifier (inconnus)', 'reconnu' => 'Reconnus', 'tous' => 'Tous'] as $k => $l): ?><a class="btn <?= $f === $k ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(url('/ecole/passages', ['f' => $k])) ?>"><?= e($l) ?></a><?php endforeach; ?></div>
  <span class="small text-secondary ms-2">Vignettes conservées <?= (int) setting('ecole_conservation', '30') ?> jour(s), puis effacées. Attribuer un passage pointe la personne à l'heure du passage.</span>
</div>
<div class="row g-2" id="ecPassages">
<?php foreach ($rows as $p): [$lib, $cls] = $st[$p['statut']] ?? [$p['statut'], 'bg-secondary']; ?>
  <div class="col-6 col-md-4 col-xl-3" data-passage="<?= (int) $p['id'] ?>"><div class="card h-100">
    <?php if ($p['thumb']): ?><img src="<?= e($p['thumb']) ?>" class="card-img-top ec-thumb" alt=""><?php else: ?><div class="ec-thumb ec-mini-empty"><i class="bi bi-image"></i></div><?php endif; ?>
    <div class="card-body p-2 small">
      <div><span class="badge <?= e($cls) ?>" data-badge><?= e($lib) ?></span> <?= e(fdate($p['vu_a'])) ?> <?= e(substr($p['vu_a'], 11, 5)) ?></div>
      <div class="text-secondary"><?= e($p['point'] ?: 'Poste de pointage') ?> · <?= e(Presences::ROLES[$p['role']] ?? $p['role']) ?></div>
      <?php if ($p['personne']): ?><div class="fw-bold" data-nom><?= e($p['personne']) ?></div><?php endif; ?>
      <?php if ($p['distance'] !== null): ?><div class="text-secondary">distance <?= e(number_format((float) $p['distance'], 3, ',', '')) ?><?= $p['marge'] !== null ? ' · écart ' . e(number_format((float) $p['marge'], 3, ',', '')) : '' ?></div><?php endif; ?>
      <?php if ($p['statut'] !== 'ignore'): ?>
      <div class="mt-1 position-relative"><input class="form-control form-control-sm" placeholder="Attribuer à… (nom, matricule)" data-qui autocomplete="off"><div class="list-group ec-sugg" data-sugg></div></div>
      <?php if ($p['statut'] === 'inconnu'): ?><button type="button" class="btn btn-sm btn-link text-secondary p-0 mt-1" data-ignorer>Ignorer</button><?php endif; ?>
      <?php endif; ?>
      <div class="text-danger" data-err></div>
    </div></div></div>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="col-12 text-secondary">Aucun passage.</div><?php endif; ?>
</div>
