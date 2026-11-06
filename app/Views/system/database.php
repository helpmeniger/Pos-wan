<?php $issues = count($c['missing_tables']) + count($c['missing_columns']) + count($c['enums']) + count($c['pending_migrations']); ?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card"><div class="card-header"><i class="bi bi-database-check"></i> État de la base « <?= e($db) ?> » — POS Marwa <?= e($version) ?></div><div class="card-body">
      <?php if ($issues === 0): ?>
        <div class="alert alert-success mb-3"><i class="bi bi-check-circle-fill"></i> <b>Base complète.</b> Les <?= (int) $c['tables'] ?> tables attendues par cette version (logiciel et modules installés) sont présentes, avec toutes leurs colonnes.</div>
      <?php else: ?>
        <div class="alert alert-warning mb-3"><i class="bi bi-exclamation-triangle-fill"></i> <b>La base n'est pas à jour pour cette version</b> : <?= (int) $issues ?> élément(s) à synchroniser. Les pages concernées peuvent afficher une erreur tant que la base n'est pas réparée.</div>
        <?php if ($c['pending_migrations']): ?><h6>Mises à jour de la base en attente</h6><ul class="small"><?php foreach ($c['pending_migrations'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if ($c['missing_tables']): ?><h6>Tables manquantes (<?= count($c['missing_tables']) ?>)</h6><p class="small"><?= e(implode(', ', $c['missing_tables'])) ?></p><?php endif; ?>
        <?php if ($c['missing_columns']): ?><h6>Colonnes manquantes (<?= count($c['missing_columns']) ?>)</h6><ul class="small"><?php foreach ($c['missing_columns'] as $mc): ?><li><code><?= e($mc['table'] . '.' . $mc['column']) ?></code></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if ($c['enums']): ?><h6>Listes de valeurs à compléter</h6><ul class="small"><?php foreach ($c['enums'] as $en): ?><li><code><?= e($en['table'] . '.' . $en['column']) ?></code> : + <?= e(implode(', ', $en['added'])) ?></li><?php endforeach; ?></ul><?php endif; ?>
      <?php endif; ?>
      <form method="post" action="<?= e(url('/system/database')) ?>" data-confirm="Synchroniser la base ? Une sauvegarde complète est faite avant ; rien n'est supprimé."><?= csrf_field() ?>
        <button class="btn <?= $issues ? 'btn-warning' : 'btn-outline-secondary' ?>"><i class="bi bi-arrow-repeat"></i> <?= $issues ? 'Réparer et synchroniser maintenant' : 'Vérifier et synchroniser de nouveau' ?></button>
      </form>
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="alert alert-info small"><b>Synchronisation automatique.</b> Après chaque mise à jour du logiciel ou d'un module, POS Marwa compare la structure attendue par la nouvelle version à la base, puis, après une sauvegarde : applique les mises à jour en attente, <b>crée les tables manquantes</b>, <b>ajoute les colonnes manquantes</b> et complète les listes de valeurs. Aucune donnée n'est supprimée ni modifiée. En ligne de commande : <code>php bin/migrate.php</code>.</div>
    <?php if ($last): ?>
      <div class="card"><div class="card-header small"><i class="bi bi-clock-history"></i> Dernière synchronisation : <?= e(fdate($last['at'])) ?></div><div class="card-body small">
        <?php if ($last['backup'] ?? null): ?><div>Sauvegarde préalable : <code><?= e($last['backup']) ?></code></div><?php endif; ?>
        <div>Mises à jour appliquées : <?= $last['migrations'] ? e(implode(', ', $last['migrations'])) : 'aucune' ?></div>
        <div>Tables créées : <?= $last['created'] ? e(implode(', ', $last['created'])) : 'aucune' ?></div>
        <div>Colonnes ajoutées : <?= $last['columns'] ? e(implode(', ', $last['columns'])) : 'aucune' ?></div>
        <div>Listes de valeurs : <?= $last['enums'] ? e(implode(', ', $last['enums'])) : 'aucune' ?></div>
        <?php foreach ($last['errors'] as $er): ?><div class="text-danger"><i class="bi bi-x-circle"></i> <?= e($er) ?></div><?php endforeach; ?>
      </div></div>
    <?php endif; ?>
  </div>
</div>
