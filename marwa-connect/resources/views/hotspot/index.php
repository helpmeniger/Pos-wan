<?php
use App\Services\MikroTik\RouterOSClient;
$title = 'Clients Wi-Fi';
$tabs = ['live' => 'Connectés maintenant', 'history' => 'Historique des sessions', 'top' => 'Plus gros consommateurs (30 j)'];
?>
<div class="mc-page-head">
    <div><h1>Clients <span class="mc-accent">Wi-Fi</span></h1><p>Sessions Hotspot lues en direct sur les routeurs.</p></div>
    <form class="mc-filter-bar" method="get" action="<?= e(url('/hotspot')) ?>">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <select class="form-select" name="router_id" data-autosubmit><option value="0">Tous les routeurs</option><?php foreach ($allRouters as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $routerId === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
        <input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Utilisateur, IP, MAC">
        <button class="btn btn-navy"><i class="bi bi-search"></i></button>
    </form>
</div>
<ul class="nav nav-tabs mb-3"><?php foreach ($tabs as $k => $l): ?><li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="<?= e(url('/hotspot', ['tab' => $k, 'router_id' => $routerId])) ?>"><?= e($l) ?></a></li><?php endforeach; ?></ul>

<?php if ($tab === 'live'): ?>
    <?php foreach ($errors as $er): ?><div class="alert alert-warning py-2 small"><i class="bi bi-wifi-off me-1"></i><?= e($er) ?></div><?php endforeach; ?>
    <div class="mc-card"><div class="mc-card-head"><h2><?= count($sessions) ?> client(s) connecté(s)</h2><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/hotspot', ['router_id' => $routerId])) ?>"><i class="bi bi-arrow-clockwise"></i> Actualiser</a></div>
    <div class="table-responsive"><table class="table mc-table table-hover">
        <thead><tr><th>Utilisateur</th><th>Routeur</th><th>IP / MAC</th><th>Connecté depuis</th><th>Temps restant</th><th class="text-end">↓ / ↑</th><th>Ticket</th><th></th></tr></thead><tbody>
        <?php foreach ($sessions as $s): $u = $s['user'] ?? ''; $t = $tickets[$u] ?? null; ?>
            <tr><td class="fw-semibold"><?= $t ? '<a href="' . e(url('/tickets/' . $t['id'])) . '"><code>' . e($u) . '</code></a>' : '<code>' . e($u) . '</code>' ?>
                    <?php if (($perUser[$u] ?? 0) > 1): ?><span class="badge text-bg-warning ms-1" title="Connexions simultanées"><?= (int) $perUser[$u] ?> appareils</span><?php endif; ?></td>
                <td class="small"><?= e($s['router_name']) ?></td>
                <td class="small"><?= e($s['address'] ?? '') ?><br><code><?= e($s['mac-address'] ?? '') ?></code></td>
                <td><?= e(human_duration(RouterOSClient::parseDuration($s['uptime'] ?? ''))) ?></td>
                <td class="small"><?= isset($s['session-time-left']) ? e(human_duration(RouterOSClient::parseDuration($s['session-time-left']))) : '—' ?></td>
                <td class="text-end small"><?= e(bytes_human($s['bytes-out'] ?? 0)) ?> / <?= e(bytes_human($s['bytes-in'] ?? 0)) ?></td>
                <td class="small"><?= $t ? e($t['offer_name']) . ' ' . status_badge($t['status']) : '<span class="text-muted">hors application</span>' ?></td>
                <td class="text-end text-nowrap"><?php if (can('hotspot.manage')): ?>
                    <form class="d-inline" method="post" action="<?= e(url('/hotspot/disconnect')) ?>" data-confirm="Déconnecter <?= e($u) ?> ?"><?= csrf_field() ?>
                        <input type="hidden" name="router_id" value="<?= (int) $s['router_id'] ?>"><input type="hidden" name="active_id" value="<?= e($s['.id'] ?? '') ?>"><input type="hidden" name="user" value="<?= e($u) ?>">
                        <button class="btn btn-sm btn-outline-danger" title="Déconnecter"><i class="bi bi-x-circle"></i></button></form>
                    <?php if ($t && in_array($t['status'], ['active', 'sold', 'available'], true)): ?>
                        <form class="d-inline" method="post" action="<?= e(url('/tickets/' . $t['id'] . '/action')) ?>" data-confirm="Suspendre le compte <?= e($u) ?> ?"><?= csrf_field() ?><input type="hidden" name="action" value="suspend">
                            <button class="btn btn-sm btn-outline-warning" title="Suspendre le compte"><i class="bi bi-pause-circle"></i></button></form>
                    <?php endif; ?><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$sessions): ?><tr><td colspan="8"><div class="mc-empty"><i class="bi bi-people"></i>Aucun client connecté.</div></td></tr><?php endif; ?>
        </tbody></table></div></div>

<?php elseif ($tab === 'history'): ?>
    <div class="mc-card"><div class="table-responsive"><table class="table mc-table table-sm table-hover">
        <thead><tr><th>Utilisateur</th><th>Routeur</th><th>Début</th><th>Fin</th><th>Durée</th><th>IP / MAC</th><th class="text-end">Données</th></tr></thead><tbody>
        <?php foreach ($history as $h): ?><tr><td><code><?= e($h['username']) ?></code></td><td class="small"><?= e($h['router_name']) ?></td><td class="small text-nowrap"><?= e(date_fr($h['login_at'])) ?></td>
            <td class="small text-nowrap"><?= $h['is_active'] ? '<span class="mc-status ok">En cours</span>' : e(date_fr($h['logout_at'])) ?></td><td class="small"><?= e(human_duration((int) $h['uptime_seconds'])) ?></td>
            <td class="small"><?= e($h['ip_address']) ?><br><code><?= e($h['mac_address']) ?></code></td><td class="text-end small"><?= e(bytes_human($h['bytes_in'] + $h['bytes_out'])) ?></td></tr><?php endforeach; ?>
        <?php if (!$history): ?><tr><td colspan="7"><div class="mc-empty"><i class="bi bi-clock-history"></i>Aucune session enregistrée.</div></td></tr><?php endif; ?>
        </tbody></table></div><?= pagination($page, $pages, '/hotspot', ['tab' => 'history', 'router_id' => $routerId, 'q' => $q]) ?></div>

<?php else: ?>
    <div class="mc-card"><div class="table-responsive"><table class="table mc-table table-hover">
        <thead><tr><th>Utilisateur</th><th class="text-end">Sessions</th><th class="text-end">Appareils</th><th class="text-end">Temps</th><th class="text-end">Téléchargé</th><th class="text-end">Envoyé</th><th>Dernière connexion</th></tr></thead><tbody>
        <?php foreach ($top as $t): ?><tr><td><code><?= e($t['username']) ?></code></td><td class="text-end"><?= (int) $t['sessions'] ?></td><td class="text-end"><?= (int) $t['devices'] ?></td><td class="text-end"><?= e(human_duration((int) $t['up'])) ?></td>
            <td class="text-end"><?= e(bytes_human($t['bout'])) ?></td><td class="text-end"><?= e(bytes_human($t['bin'])) ?></td><td class="small"><?= e(date_fr($t['last'])) ?></td></tr><?php endforeach; ?>
        <?php if (!$top): ?><tr><td colspan="7"><div class="mc-empty"><i class="bi bi-bar-chart"></i>Pas encore de données.</div></td></tr><?php endif; ?>
        </tbody></table></div></div>
<?php endif; ?>
