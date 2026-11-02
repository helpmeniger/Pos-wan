<?php
use App\Services\TicketService;
$title = 'Ticket ' . $t['username'];
?>
<div class="mc-page-head">
    <div><h1>Ticket <span class="mc-accent"><?= e($t['username']) ?></span> <?= status_badge($t['status']) ?></h1>
        <p><?= e($t['offer_name']) ?> · <?= e(money($t['price'])) ?> · <?= e($t['router_name']) ?> · lot <?= e($t['batch_code'] ?? '—') ?></p></div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (can('tickets.print')): ?><a class="btn btn-outline-secondary" target="_blank" href="<?= e(url('/tickets/print', ['ids' => [$t['id']], 'format' => 'thermal80'])) ?>"><i class="bi bi-printer"></i> Imprimer</a><?php endif; ?>
        <?php if (can('tickets.manage', 'hotspot.manage')): ?>
            <?php foreach (['suspend' => ['Suspendre', 'warning', 'pause-circle'], 'reactivate' => ['Réactiver', 'success', 'play-circle'], 'cancel' => ['Annuler', 'danger', 'x-octagon']] as $a => [$l, $c, $i]):
                if (($a === 'reactivate') !== ($t['status'] === 'suspended') || $t['status'] === 'cancelled') { continue; } ?>
                <form method="post" action="<?= e(url('/tickets/' . $t['id'] . '/action')) ?>" data-confirm="<?= e($l) ?> ce ticket ?"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $a ?>"><button class="btn btn-outline-<?= $c ?>"><i class="bi bi-<?= $i ?>"></i> <?= $l ?></button></form>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="mc-card"><div class="mc-card-body row g-3 small">
            <?php foreach ([
                'Identifiant' => '<code class="fs-6">' . e($t['username']) . '</code>', 'Mot de passe' => '<code class="fs-6">' . e($t['password']) . '</code>',
                'Profil Hotspot' => e($t['hotspot_profile']), 'Sur le routeur' => $t['on_router'] ? '<span class="mc-status ok">Oui</span>' : '<span class="mc-status bad">Non</span> ' . e($t['push_error']),
                'Activation' => e(TicketService::ACTIVATION[$t['activation_mode']]), 'Validité' => e(TicketService::VALIDITY[$t['validity_mode']]),
                'Appareils simultanés' => (int) $t['shared_users'], 'MAC liée' => e($t['bound_mac'] ?: '—'),
                'Créé le' => e(date_fr($t['created_at'])), 'Vendu le' => e(date_fr($t['sold_at'])) . ($t['sale_ref'] ? ' (<a href="' . e(url('/sales/' . $t['sale_id'])) . '">' . e($t['sale_ref']) . '</a>)' : ''),
                'Client' => e($t['customer_phone'] ?: '—'), 'Revendeur' => e($t['reseller_name'] ?: '—'),
                '1re utilisation' => e(date_fr($t['first_used_at'])), 'Expire le' => e(date_fr($t['expires_at'])),
                'Temps utilisé' => e(human_duration((int) $t['uptime_seconds'])) . ($t['time_limit_seconds'] ? ' / ' . e(human_duration((int) $t['time_limit_seconds'])) : ''),
                'Données' => e(bytes_human($t['bytes_in'] + $t['bytes_out'])) . ($t['data_limit_bytes'] ? ' / ' . e(bytes_human($t['data_limit_bytes'])) : ''),
                'Dernière synchro' => e(date_fr($t['last_synced_at'])),
            ] as $l => $v): ?>
                <div class="col-6"><span class="text-muted d-block"><?= e($l) ?></span><?= $v ?></div>
            <?php endforeach; ?>
        </div></div>
    </div>
    <div class="col-lg-7">
        <div class="mc-card mb-3"><div class="mc-card-head"><h2>Historique des connexions</h2></div>
            <div class="table-responsive"><table class="table mc-table table-sm"><thead><tr><th>Début</th><th>Fin</th><th>Durée</th><th>IP / MAC</th><th class="text-end">Données</th></tr></thead><tbody>
            <?php foreach ($sessions as $s): ?>
                <tr><td class="small"><?= e(date_fr($s['login_at'], 'd/m H:i')) ?></td><td class="small"><?= $s['is_active'] ? '<span class="mc-status ok">En cours</span>' : e(date_fr($s['logout_at'], 'd/m H:i')) ?></td>
                    <td class="small"><?= e(human_duration((int) $s['uptime_seconds'])) ?></td><td class="small"><?= e($s['ip_address']) ?><br><code><?= e($s['mac_address']) ?></code></td>
                    <td class="text-end small"><?= e(bytes_human($s['bytes_in'] + $s['bytes_out'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$sessions): ?><tr><td colspan="5" class="text-center text-muted py-3">Aucune connexion enregistrée.</td></tr><?php endif; ?>
            </tbody></table></div></div>
        <div class="mc-card"><div class="mc-card-head"><h2>Journal</h2></div>
            <ul class="list-group list-group-flush">
            <?php foreach ($audit as $a): ?><li class="list-group-item small"><?= e(date_fr($a['created_at'])) ?> — <?= e($a['description']) ?> <span class="text-muted">(<?= e($a['full_name'] ?? 'système') ?>)</span></li><?php endforeach; ?>
            <?php if (!$audit): ?><li class="list-group-item small text-muted">Aucune action.</li><?php endif; ?>
            </ul></div>
    </div>
</div>
