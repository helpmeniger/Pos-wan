<?php
use App\Services\TicketService;
$title = 'Offres';
?>
<div class="mc-page-head">
    <div>
        <h1>Offres <span class="mc-accent">Internet</span></h1>
        <p>Forfaits vendus sous forme de tickets. Chaque offre crée un profil Hotspot sur ses routeurs.</p>
    </div>
    <div class="d-flex gap-2">
        <form method="get" action="<?= e(url('/offers')) ?>">
            <select class="form-select" name="status" data-autosubmit>
                <option value="">Actives et inactives</option>
                <?php foreach (['active' => 'Actives', 'inactive' => 'Inactives', 'archived' => 'Archivées'] as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
            </select>
        </form>
        <?php if (can('offers.manage')): ?><a class="btn btn-primary text-nowrap" href="<?= e(url('/offers/create')) ?>"><i class="bi bi-plus-lg me-1"></i> Nouvelle offre</a><?php endif; ?>
    </div>
</div>
<div class="mc-card"><div class="table-responsive"><table class="table mc-table table-hover">
    <thead><tr><th>Offre</th><th class="text-end">Prix</th><th>Validité</th><th>Limites</th><th>Débit ↓/↑</th><th>Routeurs</th><th class="text-end">Stock</th><th class="text-end">Vendus</th><th>Statut</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($offers as $o): ?>
        <tr>
            <td><div class="fw-semibold"><?= e($o['name']) ?> <?php if ($o['is_public']): ?><i class="bi bi-globe2 text-primary small" title="Vendue en ligne"></i><?php endif; ?></div>
                <small class="text-muted"><code><?= e($o['code']) ?></code> · <?= e(TicketService::VALIDITY[$o['validity_mode']]) ?></small></td>
            <td class="text-end fw-bold"><?= e(money($o['price'])) ?></td>
            <td><?= e(TicketService::durationLabel($o)) ?></td>
            <td class="small"><?= $o['time_limit_seconds'] ? e(human_duration((int) $o['time_limit_seconds'])) . '<br>' : '' ?><?= $o['data_limit_bytes'] ? e(bytes_human($o['data_limit_bytes'])) : '' ?><?= !$o['time_limit_seconds'] && !$o['data_limit_bytes'] ? '—' : '' ?></td>
            <td class="small"><?= e(($o['rate_down'] ?: '∞') . ' / ' . ($o['rate_up'] ?: '∞')) ?></td>
            <td class="small"><?= $o['routers'] ? e($o['routers']) : '<span class="text-warning">Aucun</span>' ?></td>
            <td class="text-end"><?= (int) $o['stock'] ?></td>
            <td class="text-end"><?= (int) $o['sold'] ?></td>
            <td><?= status_badge($o['status']) ?></td>
            <td class="text-end text-nowrap">
                <?php if (can('tickets.generate') && $o['status'] === 'active'): ?><a class="btn btn-sm btn-outline-primary" title="Générer des tickets" href="<?= e(url('/tickets/generate', ['offer_id' => $o['id']])) ?>"><i class="bi bi-ticket-perforated"></i></a><?php endif; ?>
                <?php if (can('offers.manage')): ?>
                    <form class="d-inline" method="post" action="<?= e(url('/offers/' . $o['id'] . '/push')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary" title="Appliquer le profil sur les routeurs"><i class="bi bi-cloud-upload"></i></button></form>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/offers/' . $o['id'] . '/edit')) ?>"><i class="bi bi-pencil"></i></a>
                    <form class="d-inline" method="post" action="<?= e(url('/offers/' . $o['id'])) ?>" data-confirm="Supprimer cette offre ? (elle sera archivée si des tickets existent)"><?= csrf_field() ?><?= method_field('DELETE') ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$offers): ?><tr><td colspan="10"><div class="mc-empty"><i class="bi bi-tags"></i>Aucune offre.</div></td></tr><?php endif; ?>
    </tbody></table></div></div>
