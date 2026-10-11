<div class="page-header"><h1><i class="bi bi-hdd-stack"></i> Sauvegardes</h1>
    <form method="post" action="<?= e(url('backups.create')) ?>"><?= csrf_field() ?>
        <button class="btn btn-primary" type="submit"><i class="bi bi-plus-circle"></i> Créer une sauvegarde</button></form>
</div>
<div class="alert alert-info small">
    Les sauvegardes contiennent la base complète, les photographies et les documents. Elles sont chiffrées (clé « backup ») et vérifiées par empreintes.
    <strong>Conservez les fichiers de clés (<code>storage.key</code> et <code>backup.key</code>) sur un support séparé et protégé</strong> :
    sans eux, aucune restauration n'est possible. Copiez régulièrement les sauvegardes hors de ce poste.
</div>
<?php if ($inspect): ?>
    <div class="alert alert-<?= $inspect['ok'] ? 'success' : 'danger' ?> small">
        Vérification de <code><?= e($inspect['name']) ?></code> :
        <?php if ($inspect['ok']): $m = $inspect['manifest']; ?>
            conforme — créée le <?= e($m['created_at']) ?>, schéma <?= e($m['schema_version']) ?>,
            <?= (int) ($m['tables']['persons']['rows'] ?? 0) ?> fiche(s), <?= count($m['files']) ?> fichier(s).
        <?php else: ?><?= e($inspect['error']) ?><?php endif; ?>
    </div>
<?php endif; ?>
<div class="card mb-4"><div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Fichier</th><th>Date</th><th>Taille</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php if (!$backups): ?><tr><td colspan="4" class="text-muted text-center py-4">Aucune sauvegarde.</td></tr><?php endif; ?>
    <?php foreach ($backups as $b): ?>
        <tr><td><code><?= e($b['name']) ?></code></td><td><?= e(date('d/m/Y H:i', $b['mtime'])) ?></td><td><?= e(human_size((int) $b['size'])) ?></td>
            <td class="text-end text-nowrap">
                <form method="post" class="d-inline" action="<?= e(url('backups.verify')) ?>"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($b['name']) ?>">
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Vérifier</button></form>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(url('backups.download', ['name' => $b['name']])) ?>">Télécharger</a>
                <form method="post" class="d-inline" action="<?= e(url('backups.restore')) ?>"
                      data-confirm="Restaurer cette sauvegarde ? TOUTES les données actuelles seront remplacées (une sauvegarde de sécurité sera créée automatiquement)."
                      data-prompt="Saisissez RESTAURER pour confirmer" data-prompt-field="confirm">
                    <?= csrf_field() ?><input type="hidden" name="name" value="<?= e($b['name']) ?>"><input type="hidden" name="confirm" value="">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Restaurer</button></form>
            </td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>

<div class="card"><div class="card-header">Restaurer depuis un fichier</div><div class="card-body">
<form method="post" enctype="multipart/form-data" action="<?= e(url('backups.restore')) ?>" class="row g-2">
    <?= csrf_field() ?>
    <div class="col-md-5"><input class="form-control" type="file" name="backup_file" accept=".didbak" required></div>
    <div class="col-md-4"><input class="form-control" name="confirm" placeholder="Saisissez RESTAURER" required autocomplete="off"></div>
    <div class="col-md-3"><button class="btn btn-danger w-100" type="submit">Restaurer</button></div>
    <div class="col-12 small text-muted">La taille maximale dépend de <code>upload_max_filesize</code> et <code>post_max_size</code> (php.ini). Pour les fichiers volumineux, utilisez <code>scripts/backup.php --restore</code>.</div>
</form>
</div></div>
