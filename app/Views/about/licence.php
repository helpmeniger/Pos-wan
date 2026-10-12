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
      <div class="mb-3"><div class="small text-secondary">Identifiant de cette installation (à communiquer pour obtenir la clé)</div>
        <div class="d-flex gap-2 align-items-center"><code class="fs-5" id="installId"><?= e($lic['install_id']) ?></code><button type="button" class="btn btn-sm btn-outline-secondary" data-copy="#installId"><i class="bi bi-clipboard"></i> Copier</button></div></div>
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
      <div class="text-secondary">La clé est liée à cette installation : copiée sur un autre ordinateur, elle est refusée. Changement d'ordinateur : demandez une nouvelle clé.</div>
      <a class="btn btn-sm btn-outline-primary mt-3" href="<?= e(url('/about/contrat')) ?>">Lire le contrat de licence</a>
      <div class="text-secondary mt-3" style="font-size:.75rem">Vérification des signatures : <?= function_exists('sodium_crypto_sign_verify_detached') ? 'extension PHP sodium' : 'bibliothèque PHP intégrée (extension sodium non activée)' ?> · POS Wan v<?= e(config('app.version')) ?></div>
    </div></div>
  </div>
</div>
