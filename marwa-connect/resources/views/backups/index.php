<?php $title = 'Sauvegardes'; ?>
<div class="mc-page-head"><div><h1><span class="mc-accent">Sauvegardes</span></h1><p>Sauvegardes compressées de la base de données (<?= $free !== false && $free !== null ? e(bytes_human($free)) . ' libres' : '' ?>). Conservez aussi une copie du fichier <code>.env</code> hors du serveur.</p></div>
    <form method="post" action="<?= e(url('/admin/backups')) ?>"><?= csrf_field() ?><button class="btn btn-primary"><i class="bi bi-database-add me-1"></i> Sauvegarder maintenant</button></form></div>
<div class="mc-card"><div class="table-responsive"><table class="table mc-table table-hover">
    <thead><tr><th>Fichier</th><th>Date</th><th>Type</th><th>Méthode</th><th class="text-end">Taille</th><th>Par</th><th></th></tr></thead><tbody>
    <?php foreach ($backups as $b): ?>
        <tr><td><code class="small"><?= e($b['filename']) ?></code><?= $b['exists'] ? '' : ' <span class="mc-status bad">fichier absent</span>' ?></td><td class="small"><?= e(date_fr($b['created_at'])) ?></td>
            <td class="small"><?= e(['manual' => 'Manuelle', 'scheduled' => 'Planifiée', 'pre_update' => 'Avant mise à jour'][$b['trigger_type']] ?? $b['trigger_type']) ?></td><td class="small"><?= e($b['method']) ?></td>
            <td class="text-end small"><?= e(bytes_human($b['size_bytes'])) ?></td><td class="small"><?= e($b['full_name'] ?? 'Planificateur') ?></td>
            <td class="text-end text-nowrap"><?php if ($b['exists']): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/backups/' . $b['id'] . '/download')) ?>"><i class="bi bi-download"></i></a><?php endif; ?>
                <form class="d-inline" method="post" action="<?= e(url('/admin/backups/' . $b['id'])) ?>" data-confirm="Supprimer cette sauvegarde ?"><?= csrf_field() ?><?= method_field('DELETE') ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$backups): ?><tr><td colspan="7"><div class="mc-empty"><i class="bi bi-database"></i>Aucune sauvegarde.</div></td></tr><?php endif; ?>
    </tbody></table></div></div>
<p class="small text-muted mt-3">Restauration : décompressez le fichier puis importez-le dans phpMyAdmin (onglet Importer) ou avec <code>mysql marwa_connect &lt; fichier.sql</code>.</p>
