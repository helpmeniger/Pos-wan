<?php
use App\Services\MikroTik\RouterOSClient;
$title = $router['name'];
$tabs = ['overview' => ['Aperçu', 'speedometer2'], 'interfaces' => ['Interfaces', 'ethernet'], 'sessions' => ['Clients connectés', 'people'],
    'users' => ['Utilisateurs Hotspot', 'person-badge'], 'profiles' => ['Profils', 'sliders'], 'logs' => ['Journaux', 'journal-text']];
$base = '/routers/' . $router['id'];
?>
<div class="mc-page-head">
    <div>
        <h1><?= e($router['name']) ?> <?= $router['status'] === 'inactive' ? status_badge('inactive') : status_badge($router['connection_state']) ?></h1>
        <p><?= e($router['host']) ?> · <?= e($router['identity'] ?: 'identité inconnue') ?> · <?= e($router['site_name'] ?: 'sans site') ?> · dernière synchro <?= e(date_fr($router['last_sync_at'])) ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (can('routers.sync', 'routers.manage')): ?>
            <form method="post" action="<?= e(url($base . '/sync')) ?>"><?= csrf_field() ?><button class="btn btn-primary"><i class="bi bi-arrow-repeat me-1"></i> Synchroniser</button></form>
        <?php endif; ?>
        <?php if (can('routers.manage')): ?><a class="btn btn-outline-secondary" href="<?= e(url($base . '/edit')) ?>"><i class="bi bi-pencil"></i> Modifier</a><?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/routers')) ?>"><i class="bi bi-arrow-left"></i></a>
    </div>
</div>

<?php if ($router['last_error'] && $router['connection_state'] !== 'online'): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i> Dernière erreur : <?= e($router['last_error']) ?></div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">
    <?php foreach ($tabs as $k => [$label, $icon]): ?>
        <li class="nav-item"><a class="nav-link text-nowrap <?= $tab === $k ? 'active' : '' ?>" href="<?= e(url($base, ['tab' => $k])) ?>"><i class="bi bi-<?= $icon ?> me-1"></i><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($liveError): ?>
    <div class="alert alert-warning"><i class="bi bi-wifi-off me-1"></i> Données en direct indisponibles : <?= e($liveError) ?>. Les informations ci-dessous proviennent de la dernière synchronisation.</div>
<?php endif; ?>

<?php if ($tab === 'overview'): ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="mc-card h-100">
                <div class="mc-card-head"><h2>Informations techniques</h2><?= $live ? '<span class="mc-status ok">En direct</span>' : '<span class="mc-status muted">Dernier relevé</span>' ?></div>
                <div class="mc-card-body">
                    <div class="row g-3 small">
                        <?php $rows = [
                            'Identité' => $live['identity'] ?? $router['identity'], 'Modèle' => $live['model'] ?? $router['model'],
                            'Carte' => $live['board_name'] ?? $router['board_name'], 'Version RouterOS' => $live['version'] ?? $router['routeros_version'],
                            'N° de série' => $live['serial'] ?? $router['serial_number'], 'Architecture' => $live['architecture'] ?? null,
                            'CPU' => isset($live['cpu']) ? $live['cpu'] . ' × ' . $live['cpu_count'] : null,
                            'Charge CPU' => isset($live['cpu_load']) ? $live['cpu_load'] . ' %' : ($router['last_cpu'] !== null ? $router['last_cpu'] . ' %' : null),
                            'Mémoire' => isset($live['total_memory']) ? bytes_human($live['total_memory'] - $live['free_memory']) . ' / ' . bytes_human($live['total_memory']) : ($router['last_memory_used_pct'] !== null ? $router['last_memory_used_pct'] . ' %' : null),
                            'Stockage libre' => isset($live['free_hdd']) ? bytes_human($live['free_hdd']) . ' / ' . bytes_human($live['total_hdd']) : null,
                            'Uptime' => isset($live['uptime']) ? human_duration($live['uptime']) : ($router['last_uptime'] ? human_duration((int) $router['last_uptime']) : null),
                            'Connexion' => strtoupper($router['connection_type']) . ' · ' . ($router['use_ssl'] ? 'API-SSL ' . $router['api_ssl_port'] : 'API ' . $router['api_port']),
                        ]; ?>
                        <?php foreach ($rows as $l => $val): ?>
                            <div class="col-sm-6 col-md-4"><span class="text-muted d-block"><?= e($l) ?></span><strong><?= e($val ?? '—') ?></strong></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="mc-card mb-3">
                <div class="mc-card-head"><h2>Tickets de ce routeur</h2><a class="small" href="<?= e(url('/tickets', ['router_id' => $router['id']])) ?>">Voir</a></div>
                <div class="mc-card-body d-flex flex-wrap gap-3">
                    <?php foreach (App\Services\TicketService::STATUSES as $k => $l): ?>
                        <div><div class="text-muted small"><?= e($l) ?></div><div class="fs-5 fw-bold"><?= (int) ($ticketStats[$k] ?? 0) ?></div></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if (can('routers.reboot')): ?>
            <form class="mc-card" method="post" action="<?= e(url($base . '/reboot')) ?>" data-confirm="Redémarrer le routeur ? Tous les clients seront déconnectés.">
                <?= csrf_field() ?>
                <div class="mc-card-head"><h2 class="text-danger"><i class="bi bi-power me-1"></i>Redémarrer</h2></div>
                <div class="mc-card-body">
                    <label class="form-label small" for="confirm_name">Tapez <strong><?= e($router['name']) ?></strong> pour confirmer</label>
                    <div class="input-group"><input class="form-control" id="confirm_name" name="confirm_name" autocomplete="off" required>
                        <button class="btn btn-danger">Redémarrer</button></div>
                </div>
            </form>
            <?php endif; ?>
        </div>
        <div class="col-12">
            <div class="mc-card">
                <div class="mc-card-head"><h2>Historique des synchronisations</h2></div>
                <div class="table-responsive"><table class="table mc-table table-sm">
                    <thead><tr><th>Date</th><th>Type</th><th>Résultat</th><th>Détail</th><th>Durée</th></tr></thead><tbody>
                    <?php foreach ($syncs as $s): ?>
                        <tr><td class="small text-nowrap"><?= e(date_fr($s['created_at'], 'd/m H:i:s')) ?></td><td><?= e(['scheduled' => 'Planifiée', 'manual' => 'Manuelle'][$s['type']] ?? $s['type']) ?></td>
                            <td><?= $s['status'] === 'success' ? status_badge('completed') : status_badge('failed') ?></td>
                            <td class="small"><?= e($s['message']) ?></td><td class="small"><?= (int) $s['duration_ms'] ?> ms</td></tr>
                    <?php endforeach; ?>
                    <?php if (!$syncs): ?><tr><td colspan="5" class="text-muted text-center py-3">Aucune synchronisation.</td></tr><?php endif; ?>
                    </tbody></table></div>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'interfaces'): ?>
    <div class="mc-card"><div class="table-responsive"><table class="table mc-table table-hover">
        <thead><tr><th>Nom</th><th>Type</th><th>MAC</th><th>État</th><th class="text-end">Reçu</th><th class="text-end">Envoyé</th><th>Commentaire</th></tr></thead><tbody>
        <?php foreach ($interfaces as $i): ?>
            <tr><td class="fw-semibold"><?= e($i['name']) ?><?= $i['name'] === $router['wan_interface'] ? ' <span class="badge text-bg-secondary">surveillée</span>' : '' ?></td><td><?= e($i['type']) ?></td>
                <td class="small"><code><?= e($i['mac_address']) ?></code></td>
                <td><?= $i['disabled'] ? status_badge('inactive') : ($i['running'] ? '<span class="mc-status ok">Active</span>' : '<span class="mc-status bad">Déconnectée</span>') ?></td>
                <td class="text-end small"><?= e(bytes_human($i['rx_byte'])) ?></td><td class="text-end small"><?= e(bytes_human($i['tx_byte'])) ?></td>
                <td class="small text-muted"><?= e($i['comment']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$interfaces): ?><tr><td colspan="7"><div class="mc-empty"><i class="bi bi-ethernet"></i>Aucune interface : lancez une synchronisation.</div></td></tr><?php endif; ?>
        </tbody></table></div></div>

<?php elseif ($tab === 'sessions'): ?>
    <div class="mc-card"><div class="table-responsive"><table class="table mc-table table-hover">
        <thead><tr><th>Utilisateur</th><th>IP</th><th>MAC</th><th>Connecté depuis</th><th>Temps restant</th><th class="text-end">Téléchargé</th><th class="text-end">Envoyé</th><th></th></tr></thead><tbody>
        <?php foreach ($live ?? [] as $s): ?>
            <tr><td class="fw-semibold"><?= e($s['user'] ?? '') ?></td><td><?= e($s['address'] ?? '') ?></td><td class="small"><code><?= e($s['mac-address'] ?? '') ?></code></td>
                <td><?= e(human_duration(RouterOSClient::parseDuration($s['uptime'] ?? ''))) ?></td>
                <td class="small"><?= isset($s['session-time-left']) ? e(human_duration(RouterOSClient::parseDuration($s['session-time-left']))) : '—' ?></td>
                <td class="text-end small"><?= e(bytes_human($s['bytes-out'] ?? 0)) ?></td><td class="text-end small"><?= e(bytes_human($s['bytes-in'] ?? 0)) ?></td>
                <td class="text-end"><?php if (can('hotspot.manage')): ?>
                    <form method="post" action="<?= e(url($base . '/disconnect')) ?>" data-confirm="Déconnecter <?= e($s['user'] ?? '') ?> ?"><?= csrf_field() ?>
                        <input type="hidden" name="active_id" value="<?= e($s['.id'] ?? '') ?>"><input type="hidden" name="user" value="<?= e($s['user'] ?? '') ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i></button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$live): ?><tr><td colspan="8"><div class="mc-empty"><i class="bi bi-people"></i>Aucun client connecté.</div></td></tr><?php endif; ?>
        </tbody></table></div></div>

<?php elseif ($tab === 'users'): ?>
    <div class="mc-card"><div class="mc-card-head"><h2><?= count($live ?? []) ?> utilisateur(s) Hotspot sur le routeur</h2></div>
        <div class="table-responsive"><table class="table mc-table table-sm table-hover">
        <thead><tr><th>Nom</th><th>Profil</th><th>Temps utilisé</th><th>Limite</th><th class="text-end">Données</th><th>État</th><th>Commentaire</th></tr></thead><tbody>
        <?php foreach (array_slice($live ?? [], 0, 1000) as $u): ?>
            <tr><td><code><?= e($u['name'] ?? '') ?></code></td><td><?= e($u['profile'] ?? '') ?></td><td><?= e(human_duration(RouterOSClient::parseDuration($u['uptime'] ?? ''))) ?></td>
                <td class="small"><?= e($u['limit-uptime'] ?? '—') ?></td><td class="text-end small"><?= e(bytes_human((int) ($u['bytes-in'] ?? 0) + (int) ($u['bytes-out'] ?? 0))) ?></td>
                <td><?= ($u['disabled'] ?? 'false') === 'true' ? status_badge('suspended') : '<span class="mc-status ok">Activé</span>' ?></td><td class="small text-muted"><?= e($u['comment'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>

<?php elseif ($tab === 'profiles'): ?>
    <div class="mc-card"><div class="table-responsive"><table class="table mc-table table-hover">
        <thead><tr><th>Profil</th><th>Débit (envoi/réception)</th><th>Utilisateurs simultanés</th><th>Session max</th><th>Inactivité</th><th>Géré par l'app</th><th>Synchronisé</th></tr></thead><tbody>
        <?php foreach ($profiles as $p): ?>
            <tr><td class="fw-semibold"><?= e($p['name']) ?></td><td><?= e($p['rate_limit'] ?: 'illimité') ?></td><td><?= e($p['shared_users'] ?? '—') ?></td>
                <td><?= e($p['session_timeout'] ?: '—') ?></td><td><?= e($p['idle_timeout'] ?: '—') ?></td><td><?= $p['managed_by_app'] ? '<i class="bi bi-check-lg text-success"></i>' : '' ?></td>
                <td class="small"><?= e(date_fr($p['synced_at'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$profiles): ?><tr><td colspan="7"><div class="mc-empty"><i class="bi bi-sliders"></i>Aucun profil : lancez une synchronisation.</div></td></tr><?php endif; ?>
        </tbody></table></div></div>

<?php elseif ($tab === 'logs'): ?>
    <div class="mc-card"><div class="table-responsive" style="max-height:70vh"><table class="table mc-table table-sm">
        <thead><tr><th>Heure</th><th>Sujets</th><th>Message</th></tr></thead><tbody>
        <?php foreach ($live ?? [] as $l): ?>
            <tr><td class="small text-nowrap"><?= e($l['time'] ?? '') ?></td><td class="small"><span class="badge text-bg-<?= str_contains($l['topics'] ?? '', 'error') || str_contains($l['topics'] ?? '', 'critical') ? 'danger' : (str_contains($l['topics'] ?? '', 'warning') ? 'warning' : 'secondary') ?>"><?= e($l['topics'] ?? '') ?></span></td>
                <td class="small"><?= e($l['message'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
<?php endif; ?>
