<?php $title = 'Routeurs MikroTik'; ?>
<div class="mc-page-head">
    <div>
        <h1>Routeurs <span class="mc-accent">MikroTik</span></h1>
        <p>Connexion réelle via l'API RouterOS. État mis à jour par le planificateur et à chaque synchronisation.</p>
    </div>
    <?php if (can('routers.manage')): ?>
        <a class="btn btn-primary" href="<?= e(url('/routers/create')) ?>"><i class="bi bi-plus-lg me-1"></i> Ajouter un routeur</a>
    <?php endif; ?>
</div>
<?php if (!$routers): ?>
    <div class="mc-card"><div class="mc-empty"><i class="bi bi-router"></i>Aucun routeur enregistré.<br>
        <?php if (can('routers.manage')): ?><a class="btn btn-primary btn-sm mt-3" href="<?= e(url('/routers/create')) ?>">Ajouter le premier routeur</a><?php endif; ?></div></div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($routers as $r): ?>
        <div class="col-md-6 col-xl-4">
            <div class="mc-card h-100 mc-router-card">
                <div class="mc-card-head">
                    <div class="d-flex align-items-center gap-2 min-w-0">
                        <span class="mc-router-icon <?= e($r['connection_state']) ?>"><i class="bi bi-router"></i></span>
                        <div class="min-w-0">
                            <h2 class="text-truncate"><a class="text-reset text-decoration-none" href="<?= e(url('/routers/' . $r['id'])) ?>"><?= e($r['name']) ?></a>
                                <?php if ($r['is_default']): ?><i class="bi bi-star-fill text-warning small" title="Routeur principal"></i><?php endif; ?></h2>
                            <small class="text-muted"><?= e($r['host']) ?>:<?= (int) ($r['use_ssl'] ? $r['api_ssl_port'] : $r['api_port']) ?><?= $r['use_ssl'] ? ' (SSL)' : '' ?></small>
                        </div>
                    </div>
                    <?= $r['status'] === 'inactive' ? status_badge('inactive') : status_badge($r['connection_state']) ?>
                </div>
                <div class="mc-card-body">
                    <div class="row g-2 small">
                        <div class="col-6"><span class="text-muted d-block">Modèle</span><?= e($r['model'] ?: '—') ?></div>
                        <div class="col-6"><span class="text-muted d-block">RouterOS</span><?= e($r['routeros_version'] ?: '—') ?></div>
                        <div class="col-6"><span class="text-muted d-block">CPU / RAM</span><?= $r['last_cpu'] !== null ? (int) $r['last_cpu'] . ' % / ' . (int) $r['last_memory_used_pct'] . ' %' : '—' ?></div>
                        <div class="col-6"><span class="text-muted d-block">Clients connectés</span><?= $r['last_active_users'] ?? '—' ?></div>
                        <div class="col-6"><span class="text-muted d-block">Site</span><?= e($r['site_name'] ?: '—') ?></div>
                        <div class="col-6"><span class="text-muted d-block">Tickets en stock</span><?= (int) $r['stock'] ?></div>
                    </div>
                    <?php if ($r['last_error'] && $r['connection_state'] !== 'online'): ?>
                        <div class="alert alert-danger small py-2 mt-3 mb-0"><i class="bi bi-exclamation-triangle me-1"></i><?= e($r['last_error']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="mc-card-head border-top border-bottom-0">
                    <small class="text-muted">Vu : <?= e(date_fr($r['last_seen_at'])) ?></small>
                    <div class="d-flex gap-1">
                        <?php if (can('routers.sync', 'routers.manage')): ?>
                            <form method="post" action="<?= e(url('/routers/' . $r['id'] . '/sync')) ?>"><?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-primary" title="Synchroniser"><i class="bi bi-arrow-repeat"></i></button></form>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-navy" href="<?= e(url('/routers/' . $r['id'])) ?>">Détails</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
