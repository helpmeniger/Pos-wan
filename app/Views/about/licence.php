<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-body">
      <?php if ($lic['state'] === 'licensed'): ?>
        <div class="alert alert-success mb-3"><i class="bi bi-patch-check-fill"></i> <b>Licence activée</b> pour <b><?= e($lic['client']) ?></b><?= $lic['number'] ? ' (n° ' . e($lic['number']) . ')' : '' ?><?= $lic['expires'] ? ' — valable jusqu\'au ' . e(fdate($lic['expires'], false)) . ' (' . (int) $lic['days_left'] . ' jours)' : ' — sans limite de durée' ?>.</div>
      <?php elseif ($lic['state'] === 'trial'): ?>
        <div class="alert alert-warning mb-3"><i class="bi bi-hourglass-split"></i> <b>Version d'essai</b> : <?= (int) $lic['days_left'] ?> jour(s) restant(s). Ensuite, le logiciel passe en lecture seule jusqu'à l'activation.</div>
      <?php else: ?>
        <div class="alert alert-danger mb-3"><i class="bi bi-lock-fill"></i> <b>Licence non activée : mode lecture seule.</b> Les données restent consultables et sauvegardables ; les ventes et modifications sont suspendues jusqu'à l'activation.</div>
      <?php endif; ?>
      <?php if ($lic['error']): ?><div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-octagon"></i> Clé enregistrée refusée : <?= e($lic['error']) ?></div><?php endif; ?>
      <div class="mb-3"><div class="small text-secondary">Code de cette installation et de cet ordinateur (à communiquer pour obtenir la clé)</div>
        <div class="d-flex gap-2 align-items-center flex-wrap"><code class="fs-5" id="installId"><?= e($lic['request_code']) ?></code><button type="button" class="btn btn-sm btn-outline-secondary" data-copy="#installId"><i class="bi bi-clipboard"></i> Copier</button></div>
        <?php if (!$lic['poste']): ?><div class="small text-warning mt-1"><i class="bi bi-exclamation-triangle"></i> L'identifiant de cet ordinateur est illisible sur ce serveur : la licence sera liée à l'installation seulement.</div><?php endif; ?>
        <div class="small text-secondary mt-1">Ordinateurs autorisés : <b><?= (int) $lic['postes'] ?></b> (poste principal compris)<?= $lic['state'] === 'trial' ? ' pendant l\'essai' : '' ?> · téléphones et tablettes : libres.</div></div>
      <?php if (can('licence.manage')): ?>
      <form method="post" action="<?= e(url('/licence')) ?>"><?= csrf_field() ?>
        <label class="form-label">Clé de licence</label>
        <textarea name="licence_key" rows="3" class="form-control font-monospace small" placeholder="PWL1.xxxxx.xxxxx" required></textarea>
        <button class="btn btn-primary mt-2"><i class="bi bi-key"></i> Activer</button>
      </form>
      <?php else: ?><div class="small text-secondary">L'activation est faite par l'administrateur général.</div><?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card"><div class="card-body small">
      <div class="fw-semibold mb-2"><i class="bi bi-info-circle"></i> Obtenir une licence</div>
      <ol class="ps-3 mb-3"><li>Contactez l'auteur : <b><?= e($author['name']) ?></b>, <?= e(implode(' / ', $author['phones'])) ?>.</li><li>Communiquez le nom de votre entreprise et l'identifiant d'installation ci-contre.</li><li>Collez la clé reçue et cliquez sur « Activer ».</li></ol>
      <div class="text-secondary">La clé est liée à cette installation et à cet ordinateur : le dossier ou la base copiés sur un autre ordinateur passent en lecture seule. Changement d'ordinateur : demandez le transfert de la licence. Autres ordinateurs du réseau : licence multiposte (téléphones et tablettes : libres).</div>
      <a class="btn btn-sm btn-outline-primary mt-3" href="<?= e(url('/about/contrat')) ?>">Lire le contrat de licence</a>
      <div class="text-secondary mt-3" style="font-size:.75rem">Vérification des signatures : <?= function_exists('sodium_crypto_sign_verify_detached') ? 'extension PHP sodium' : 'bibliothèque PHP intégrée (extension sodium non activée)' ?> · POS Marwa v<?= e(config('app.version')) ?></div>
    </div></div>
  </div>

  <div class="col-12">
    <div class="card"><div class="card-body">
      <div class="fw-semibold mb-1"><i class="bi bi-pc-display"></i> Ordinateurs du réseau</div>
      <div class="small text-secondary mb-2">Places pour d'autres ordinateurs : <b><?= (int) $remoteSeats ?></b> · utilisées : <b><?= count(array_filter($postes, fn($p) => $p['status'] === 'actif')) ?></b>. Le poste principal, les téléphones et les tablettes ne sont pas comptés.</div>
      <?php if (!$postes): ?><div class="small text-secondary">Aucun autre ordinateur n'a encore utilisé POS Marwa.</div><?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Ordinateur</th><th>Adresse</th><th>Première utilisation</th><th>Dernière utilisation</th><th>Place</th><th></th></tr></thead><tbody>
        <?php foreach ($postes as $p): ?><tr>
          <td><?= e($p['label']) ?></td><td><?= e($p['ip']) ?></td><td><?= e(fdate($p['first_seen_at'])) ?></td><td><?= e(fdate($p['last_seen_at'])) ?></td>
          <td><?= $p['status'] === 'actif' ? '<span class="badge text-bg-success">occupée</span>' : '<span class="badge text-bg-secondary">libérée</span>' ?></td>
          <td class="text-end"><?php if ($p['status'] === 'actif' && can('licence.manage')): ?><form method="post" action="<?= e(url('/licence/postes/liberer')) ?>" class="d-inline" data-confirm="Libérer la place de cet ordinateur ? Il ne pourra plus utiliser POS Marwa tant qu'une place n'est pas libre."><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn btn-sm btn-outline-danger">Libérer</button></form><?php endif; ?></td>
        </tr><?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </div></div>
  </div>
</div>
