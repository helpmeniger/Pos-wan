<?php
$title = "Journal d'audit";
$qs = static fn (array $extra) => http_build_query(array_merge(['tab' => $tab, 'q' => $q, 'from' => $from, 'to' => $to], $extra));
?>
<div class="mc-page-head">
    <div>
        <h1>Journal <span class="mc-accent">d'audit</span></h1>
        <p>Traçabilité des actions sensibles et des connexions. <?= (int) $total ?> entrée(s).</p>
    </div>
</div>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 'audit' ? 'active' : '' ?>" href="<?= e(url('/admin/audit')) ?>">Actions</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'logins' ? 'active' : '' ?>" href="<?= e(url('/admin/audit', ['tab' => 'logins'])) ?>">Connexions</a></li>
</ul>
<div class="mc-card">
    <form class="mc-card-head flex-wrap" method="get" action="<?= e(url('/admin/audit')) ?>">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <div class="d-flex gap-2 flex-wrap">
            <input class="form-control form-control-sm" style="max-width:240px" type="search" name="q" value="<?= e($q) ?>" placeholder="Rechercher…">
            <input class="form-control form-control-sm" style="max-width:160px" type="date" name="from" value="<?= e($from) ?>" aria-label="Du">
            <input class="form-control form-control-sm" style="max-width:160px" type="date" name="to" value="<?= e($to) ?>" aria-label="Au">
            <button class="btn btn-sm btn-navy" type="submit"><i class="bi bi-funnel"></i> Filtrer</button>
        </div>
    </form>
    <div class="table-responsive">
        <table class="table mc-table table-sm table-hover">
            <?php if ($tab === 'logins'): ?>
                <thead><tr><th>Date</th><th>Identifiant</th><th>Utilisateur</th><th>Résultat</th><th>IP</th><th>Navigateur</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="text-nowrap small"><?= e(date_fr($r['created_at'], 'd/m/Y H:i:s')) ?></td>
                        <td><code><?= e($r['identifier']) ?></code></td>
                        <td><?= e($r['full_name'] ?? '—') ?></td>
                        <td><?= $r['success'] ? '<span class="mc-status ok">Réussie</span>' : '<span class="mc-status bad">Échec</span> <small class="text-muted">' . e($r['reason']) . '</small>' ?></td>
                        <td class="small"><?= e($r['ip_address']) ?></td>
                        <td class="small text-muted text-truncate" style="max-width:260px"><?= e($r['user_agent']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Description</th><th>Détails</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="text-nowrap small"><?= e(date_fr($r['created_at'], 'd/m/Y H:i:s')) ?></td>
                        <td><?= e($r['full_name'] ?? 'Système') ?></td>
                        <td><code class="small"><?= e($r['action']) ?></code></td>
                        <td class="small"><?= e($r['description']) ?></td>
                        <td class="small">
                            <?php if ($r['old_values'] || $r['new_values']): ?>
                                <details><summary class="text-primary">Voir</summary>
                                    <?php if ($r['old_values']): ?><div class="text-muted">Avant :</div><pre class="small mb-1"><?= e(json_encode(json_decode($r['old_values'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre><?php endif; ?>
                                    <?php if ($r['new_values']): ?><div class="text-muted">Après :</div><pre class="small mb-0"><?= e(json_encode(json_decode($r['new_values'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre><?php endif; ?>
                                </details>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="small"><?= e($r['ip_address']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!$rows): ?><tr><td colspan="6"><div class="mc-empty"><i class="bi bi-journal"></i>Aucune entrée.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
        <nav class="p-3 d-flex justify-content-between align-items-center">
            <small class="text-muted">Page <?= (int) $page ?> / <?= (int) $pages ?></small>
            <div class="btn-group">
                <a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(url('/admin/audit') . '?' . $qs(['page' => $page - 1])) ?>">Précédent</a>
                <a class="btn btn-sm btn-outline-secondary <?= $page >= $pages ? 'disabled' : '' ?>" href="<?= e(url('/admin/audit') . '?' . $qs(['page' => $page + 1])) ?>">Suivant</a>
            </div>
        </nav>
    <?php endif; ?>
</div>
