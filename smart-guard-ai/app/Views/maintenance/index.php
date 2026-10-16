<div class="row g-3">
<div class="col-lg-6">
  <div class="card mb-3"><div class="card-header bg-white">Diagnostic</div><ul class="list-group list-group-flush small">
    <?php foreach ($checks as [$l, $ok]): ?><li class="list-group-item d-flex justify-content-between"><?= e($l) ?><i class="bi <?= $ok ? 'bi-check-circle text-success' : 'bi-x-circle text-danger' ?>"></i></li><?php endforeach; ?>
    <li class="list-group-item d-flex justify-content-between">Service IA <?= $health['ok'] ? '<span class="text-success">opérationnel — ' . e($health['data']['detector']) . ' / ' . e($health['data']['embedder']) . '</span>' : '<span class="text-danger">' . e($health['error']) . '</span>' ?></li>
    <?php if ($health['ok']): ?><li class="list-group-item">Événements en attente côté IA : <?= (int) $health['data']['events']['pending'] ?><?= $health['data']['events']['last_error'] ? ' — dernière erreur : ' . e($health['data']['events']['last_error']) : '' ?></li><?php endif; ?>
  </ul></div>
  <div class="card mb-3"><div class="card-header bg-white">Stockage</div><div class="card-body small">
    <p>Espace libre : <strong class="<?= $disk['low'] ? 'text-danger' : '' ?>"><?= human_bytes($disk['free']) ?></strong> sur <?= human_bytes($disk['total']) ?><br>
    Médias (images/extraits) : <?= (int) $media['n'] ?> fichier(s), <?= human_bytes((float) $media['s']) ?>, dont <?= (int) $media['p'] ?> protégé(s) (alertes ouvertes).</p>
    <?php if (can('maintenance.manage')): ?><form method="post" action="<?= url('maintenance/purge') ?>" data-confirm="Supprimer les médias arrivés à expiration ?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Purger les médias expirés maintenant</button></form><?php endif; ?>
  </div></div>
  <div class="card mb-3"><div class="card-header bg-white d-flex justify-content-between"><span>Sauvegardes</span>
    <?php if (can('maintenance.manage')): ?><form method="post" action="<?= url('maintenance/backup') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-primary">Sauvegarder maintenant</button></form><?php endif; ?></div>
  <ul class="list-group list-group-flush small">
    <?php foreach ($backups as $b): ?><li class="list-group-item d-flex justify-content-between"><span><?= dt($b['started_at']) ?> — <?= e($b['backup_type']) ?> (<?= e($b['triggered_by']) ?>) <?= $b['message'] ? '<span class="text-danger">' . e($b['message']) . '</span>' : '' ?></span>
      <span><?= $b['status'] === 'succes' ? human_bytes((float) $b['size_bytes']) : '<span class="text-danger">échec</span>' ?>
      <?php if ($b['status'] === 'succes' && $b['file_path'] && $b['backup_type'] !== 'restauration' && can('maintenance.manage')): ?> <a href="<?= url('maintenance/backups/' . (int) $b['id']) ?>"><i class="bi bi-download"></i></a><?php endif; ?></span></li><?php endforeach; ?>
    <?php if (!$backups): ?><li class="list-group-item text-muted">Aucune sauvegarde.</li><?php endif; ?>
  </ul><div class="card-footer bg-white small text-muted">Restauration : <code>scripts\restore.bat &lt;fichier&gt;</code> (ligne de commande uniquement, voir docs/INSTALLATION.md).</div></div>
</div>
<div class="col-lg-6">
  <?php if (can('audit.view')): ?>
  <div class="card mb-3"><div class="card-header bg-white">Journal d'activité (100 dernières actions)</div><div class="table-responsive" style="max-height:420px"><table class="table table-sm mb-0 small">
    <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Objet</th><th>IP</th></tr></thead><tbody>
    <?php foreach ($audit as $a): ?><tr><td><?= dt($a['created_at'], 'd/m H:i:s') ?></td><td><?= e($a['username'] ?? 'système') ?></td><td><?= e($a['action']) ?></td><td><?= e($a['entity_type']) ?> <?= e($a['entity_id']) ?></td><td><?= e($a['ip_address']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
  <div class="card mb-3"><div class="card-header bg-white">Tentatives de connexion récentes</div><div class="table-responsive" style="max-height:260px"><table class="table table-sm mb-0 small">
    <?php foreach ($logins as $l): ?><tr><td><?= dt($l['attempted_at'], 'd/m H:i:s') ?></td><td><?= e($l['username']) ?></td><td><?= e($l['ip_address']) ?></td><td><?= $l['success'] ? '<span class="text-success">réussie</span>' : '<span class="text-danger">échouée</span>' ?></td></tr><?php endforeach; ?>
  </table></div></div>
  <?php endif; ?>
  <div class="card"><div class="card-header bg-white">Journal applicatif du jour</div><pre class="small mb-0 p-2" style="max-height:260px;overflow:auto;white-space:pre-wrap"><?= e(implode("\n", $tail)) ?: 'Vide.' ?></pre></div>
</div>
</div>
