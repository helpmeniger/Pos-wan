<script type="application/json" id="update-limits"><?= json_encode($limits) ?></script>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-cloud-arrow-up"></i> Installer une nouvelle version de POS Wan</div><div class="card-body">
      <p class="small mb-2">Version installée : <b>v<?= e(config('app.version')) ?></b>. Sélectionnez le <b>dossier complet</b> de la nouvelle version (celui qui contient <code>app</code>, <code>public</code>, <code>config</code>…), par exemple le dossier <code>pos-wan</code> obtenu en décompressant le fichier ZIP reçu.</p>
      <ul class="small text-secondary mb-3">
        <li>Vos données ne sont pas touchées : base de données, <code>.env</code>, sauvegardes, photos (<code>public/uploads</code>), modules ajoutés.</li>
        <li>Avant la copie : <b>sauvegarde de la base</b> et <b>sauvegarde du code actuel</b> (retour en arrière possible ci-dessous).</li>
        <li>La base de données est mise à jour automatiquement à la fin. Faites l'opération hors des heures de vente : les autres utilisateurs patientent quelques instants.</li>
      </ul>
      <?php if (!$zip): ?><div class="alert alert-danger py-2 small">L'extension PHP <b>zip</b> est désactivée : elle est nécessaire pour sauvegarder le code avant la mise à jour (WampServer : clic sur l'icône → PHP → Extensions PHP → zip).</div><?php endif; ?>
      <div class="row g-2 align-items-end" id="upStep1">
        <div class="col-md-7"><label class="form-label small mb-0">Dossier de la nouvelle version</label><input type="file" id="upFolder" class="form-control" webkitdirectory directory multiple></div>
        <div class="col-md-5"><label class="form-label small mb-0">Votre mot de passe (confirmation)</label><input type="password" id="upPassword" class="form-control" autocomplete="current-password"></div>
        <div class="col-12 small text-secondary" id="upSummary">Aucun dossier sélectionné.</div>
        <div class="col-12"><button class="btn btn-primary" id="upSend" disabled><i class="bi bi-upload"></i> Envoyer et vérifier</button></div>
      </div>
      <div class="mt-3 d-none" id="upProgressBox"><div class="progress" style="height:22px"><div class="progress-bar progress-bar-striped progress-bar-animated" id="upBar" style="width:0%">0 %</div></div><div class="small text-secondary mt-1" id="upStatus"></div></div>
      <div class="mt-3 d-none" id="upCheck"></div>
    </div></div>
  </div>
  <div class="col-xl-4">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-arrow-counterclockwise"></i> Revenir à la version précédente</div><div class="card-body small">
      <?php if (!$codeBackups): ?><span class="text-secondary">Aucune sauvegarde de code (elles sont créées à chaque mise à jour).</span><?php else: ?>
      <form method="post" action="<?= e(url('/update/rollback')) ?>" data-confirm="Remettre le code de cette version ? La base de données n'est pas modifiée : si la mise à jour a fait évoluer la base, restaurez aussi la sauvegarde de base correspondante (menu Sauvegardes)."><?= csrf_field() ?>
        <select name="name" class="form-select form-select-sm mb-2" required><?php foreach ($codeBackups as $b): ?><option value="<?= e($b['name']) ?>">v<?= e($b['version']) ?> — <?= e($b['date']) ?> (<?= e(number_format($b['size'] / 1048576, 1, ',', ' ')) ?> Mo)</option><?php endforeach; ?></select>
        <input type="password" name="password" class="form-control form-control-sm mb-2" placeholder="Votre mot de passe" required autocomplete="current-password">
        <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-arrow-counterclockwise"></i> Restaurer ce code</button>
      </form>
      <?php endif; ?>
    </div></div>
    <div class="card"><div class="card-body small text-secondary">
      Limites du serveur : <?= (int) $limits['files'] ?> fichiers par envoi, fichier maximum <?= e(number_format($limits['file'] / 1048576, 0)) ?> Mo (les fichiers sont envoyés par lots automatiquement).
      En cas de problème : <?= e(implode(' / ', array_column(support_numbers(), 'label'))) ?>.
    </div></div>
  </div>
</div>
