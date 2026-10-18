<?php use Addons\Ecole\Presences; $back = url('/ecole/presences', ['date' => $date]); $dir = $type === 'eleve' ? 'eleve' : 'personnel'; ?>
<div class="d-flex flex-wrap gap-2 align-items-end mb-3">
  <form method="get" class="d-flex gap-2 align-items-end"><div><label class="form-label small mb-0">Date</label><input type="date" name="date" class="form-control form-control-sm" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>" data-autosubmit></div></form>
  <a class="ms-auto btn btn-sm btn-outline-secondary" href="<?= e($back) ?>"><i class="bi bi-arrow-left"></i> Présences du jour</a>
</div>
<form method="post" action="<?= e($action) ?>" class="card" id="ecAppel"><?= csrf_field() ?><input type="hidden" name="date" value="<?= e($date) ?>">
  <div class="card-header d-flex flex-wrap gap-2 align-items-center"><b><?= e($title) ?> · <?= e(fdate($date)) ?></b>
    <span class="ms-auto small">Tout marquer :</span>
    <?php foreach (Presences::STATUTS as $k => $l): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-tous="<?= e($k) ?>"><?= e($l) ?></button><?php endforeach; ?>
  </div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th></th><th>Nom et prénom(s)</th><th>Pointage</th><th>Statut</th><th>Observation</th></tr></thead><tbody>
  <?php foreach ($liste as $p): $id = (int) $p['id']; ?>
    <tr><td style="width:46px"><?php if ($p['photo']): ?><img class="ec-mini" src="<?= e(url('/ecole/photo/' . $dir . '/' . $id)) ?>" alt="" loading="lazy"><?php else: ?><span class="ec-mini ec-mini-empty"><i class="bi bi-person"></i></span><?php endif; ?></td>
      <td><?= e($p['nom'] . ' ' . $p['prenom']) ?><div class="small text-secondary"><?= e($p['matricule']) ?><?= !empty($p['poste']) ? ' · ' . e($p['poste']) : '' ?></div></td>
      <td class="small"><?= $p['arrivee'] ? 'arrivée ' . e(substr($p['arrivee'], 0, 5)) : '' ?><?= $p['depart'] ? '<br>départ ' . e(substr($p['depart'], 0, 5)) : '' ?><?= $p['source'] === 'camera' ? ' <i class="bi bi-camera-video text-secondary" title="Pointage caméra"></i>' : '' ?></td>
      <td class="text-nowrap"><?php foreach (Presences::STATUTS as $k => $l): ?><label class="ec-st ec-st-<?= e($k) ?>"><input type="radio" name="statut[<?= $id ?>]" value="<?= e($k) ?>" <?= $p['statut'] === $k ? 'checked' : '' ?>> <?= e($l) ?></label><?php endforeach; ?></td>
      <td><input name="note[<?= $id ?>]" class="form-control form-control-sm" maxlength="255" value="<?= e((string) $p['note']) ?>"></td></tr>
  <?php endforeach; ?>
  <?php if (!$liste): ?><tr><td colspan="5" class="text-secondary">Personne à appeler.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if ($liste): ?><div class="card-footer"><button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer l'appel</button> <span class="small text-secondary">Les personnes sans statut coché ne sont pas modifiées.</span></div><?php endif; ?>
</form>
