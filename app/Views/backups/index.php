<div class="row g-3">
  <div class="col-xl-8"><div class="card"><div class="card-header d-flex align-items-center"><span><i class="bi bi-hdd-stack"></i> Sauvegardes disponibles</span>
    <form method="post" action="<?= e(url('/backups')) ?>" class="ms-auto"><?= csrf_field() ?><button class="btn btn-sm btn-primary"><i class="bi bi-plus-circle"></i> Sauvegarder maintenant</button></form></div>
    <div class="table-responsive"><table class="table table-sm mb-0 align-middle"><thead><tr><th>Fichier</th><th>Date</th><th class="num">Taille</th><th>Intégrité</th><th></th></tr></thead><tbody>
    <?php foreach ($backups as $b): ?><tr><td class="small"><?= e($b['name']) ?><?= $b['zip'] ? '<br><span class="text-secondary">+ ' . e($b['zip']) . '</span>' : '' ?></td><td><?= e(fdate($b['date'])) ?></td><td class="num"><?= number_format($b['size'] / 1024, 0, ',', ' ') ?> Ko</td>
    <td><?= $b['valid'] ? '<span class="badge bg-success"><i class="bi bi-shield-check"></i> vérifiée</span>' : '<span class="badge bg-danger">corrompue</span>' ?></td>
    <td class="text-end text-nowrap"><form method="post" action="<?= e(url('/backups/download')) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($b['name']) ?>"><button class="btn btn-sm btn-light" title="Télécharger"><i class="bi bi-download"></i></button></form>
    <?php if ($b['zip']): ?><form method="post" action="<?= e(url('/backups/download')) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($b['zip']) ?>"><button class="btn btn-sm btn-light" title="Télécharger les fichiers (photos)"><i class="bi bi-file-zip"></i></button></form><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$backups): ?><tr><td colspan="5" class="text-center text-secondary py-4">Aucune sauvegarde. Créez-en une maintenant.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <div class="alert alert-info small mt-3"><b>Sauvegarde automatique :</b> planifiez <code>php bin\backup.php --copy-to="E:\Sauvegardes"</code> dans le Planificateur de tâches Windows (voir INSTALLATION_WAMPSERVER.md). Conservez une copie hors du poste (clé USB chiffrée, disque externe gardé ailleurs) pour faire face à une panne ou un vol. Dossier local : <code><?= e($dir) ?></code> (inaccessible depuis le navigateur).</div>
  </div>
  <div class="col-xl-4"><div class="card border-danger"><div class="card-header text-danger"><i class="bi bi-exclamation-triangle"></i> Restauration</div><div class="card-body">
    <p class="small">La restauration <b>remplace toutes les données actuelles</b>. Une sauvegarde de sécurité est créée automatiquement juste avant. Les photos se restaurent en décompressant l'archive .zip dans <code>public/uploads</code>.</p>
    <form method="post" action="<?= e(url('/backups/restore')) ?>" class="row g-2" data-confirm="Restaurer cette sauvegarde ? Toutes les données actuelles seront remplacées."><?= csrf_field() ?>
      <div class="col-12"><select name="name" class="form-select" required><?php foreach ($backups as $b): if (!$b['valid']) continue; ?><option value="<?= e($b['name']) ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><input type="password" name="password" class="form-control" placeholder="Votre mot de passe" required autocomplete="current-password"></div>
      <div class="col-12"><input name="confirm" class="form-control" placeholder="Tapez RESTAURER" required pattern="RESTAURER"></div>
      <div class="col-12"><button class="btn btn-danger w-100">Restaurer</button></div>
    </form>
  </div></div></div>
</div>
