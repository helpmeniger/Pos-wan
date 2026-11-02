<?php
use App\Services\TicketService;
$title = 'Tickets Wi-Fi';
$qs = array_filter($f, static fn ($v) => $v !== '' && $v !== 0);
?>
<div class="mc-page-head">
    <div><h1>Tickets <span class="mc-accent">Wi-Fi</span></h1><p><?= (int) $total ?> ticket(s) correspondant aux filtres.</p></div>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-secondary" href="<?= e(url('/tickets/batches')) ?>"><i class="bi bi-collection me-1"></i> Lots</a>
        <a class="btn btn-outline-secondary" href="<?= e(url('/tickets/templates')) ?>"><i class="bi bi-printer me-1"></i> Modèles</a>
        <?php if (can('tickets.generate')): ?><a class="btn btn-primary" href="<?= e(url('/tickets/generate')) ?>"><i class="bi bi-plus-lg me-1"></i> Générer</a><?php endif; ?>
    </div>
</div>
<div class="d-flex gap-2 flex-wrap mb-3">
    <?php foreach (TicketService::STATUSES as $k => $l): ?>
        <a class="btn btn-sm <?= $f['status'] === $k ? 'btn-navy' : 'btn-outline-secondary' ?>" href="<?= e(url('/tickets', array_merge($qs, ['status' => $k]))) ?>"><?= e($l) ?> <span class="badge text-bg-light"><?= (int) ($counts[$k] ?? 0) ?></span></a>
    <?php endforeach; ?>
    <a class="btn btn-sm <?= $f['status'] === 'not_pushed' ? 'btn-navy' : 'btn-outline-warning' ?>" href="<?= e(url('/tickets', array_merge($qs, ['status' => 'not_pushed']))) ?>">Non créés sur routeur</a>
    <?php if ($f['status'] !== ''): ?><a class="btn btn-sm btn-link" href="<?= e(url('/tickets', array_diff_key($qs, ['status' => 1]))) ?>">Tous</a><?php endif; ?>
</div>
<div class="mc-card">
    <form class="mc-card-head mc-filter-bar" method="get" action="<?= e(url('/tickets')) ?>">
        <input type="hidden" name="status" value="<?= e($f['status']) ?>">
        <input class="form-control form-control-sm" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Identifiant, téléphone…">
        <select class="form-select form-select-sm" name="offer_id"><option value="0">Toutes les offres</option><?php foreach ($offers as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $f['offer_id'] === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select form-select-sm" name="router_id"><option value="0">Tous les routeurs</option><?php foreach ($routers as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $f['router_id'] === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-sm btn-navy"><i class="bi bi-funnel"></i> Filtrer</button>
        <div class="ms-auto d-flex gap-1">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/tickets/print', $qs)) ?>" target="_blank"><i class="bi bi-printer"></i> Imprimer</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/tickets/print', $qs + ['output' => 'pdf'])) ?>" target="_blank"><i class="bi bi-filetype-pdf"></i> PDF</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/tickets/export', $qs)) ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
        </div>
    </form>
    <form method="post" action="<?= e(url('/tickets/bulk')) ?>" data-no-lock>
        <?= csrf_field() ?>
        <div class="table-responsive"><table class="table mc-table table-hover table-sm">
            <thead><tr><th><input class="form-check-input" type="checkbox" data-check-all="tk" aria-label="Tout sélectionner"></th><th>Identifiant</th><th>Offre</th><th>Routeur</th><th>Statut</th><th>Créé</th><th>Vendu</th><th>1re utilisation</th><th>Expire</th><th>Conso.</th></tr></thead>
            <tbody>
            <?php foreach ($tickets as $t): ?>
                <tr>
                    <td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $t['id'] ?>" data-check-group="tk"></td>
                    <td><a class="fw-semibold" href="<?= e(url('/tickets/' . $t['id'])) ?>"><code><?= e($t['username']) ?></code></a>
                        <?php if (!$t['on_router']): ?><i class="bi bi-cloud-slash text-warning" title="Non créé sur le routeur : <?= e($t['push_error']) ?>"></i><?php endif; ?>
                        <?php if ($t['reseller_name']): ?><br><small class="text-muted"><i class="bi bi-shop"></i> <?= e($t['reseller_name']) ?></small><?php endif; ?></td>
                    <td class="small"><?= e($t['offer_name']) ?><br><span class="text-muted"><?= e(money($t['price'])) ?></span></td>
                    <td class="small"><?= e($t['router_name']) ?></td>
                    <td><?= status_badge($t['status']) ?></td>
                    <td class="small text-nowrap"><?= e(date_fr($t['created_at'], 'd/m/y H:i')) ?></td>
                    <td class="small text-nowrap"><?= e(date_fr($t['sold_at'], 'd/m/y H:i')) ?></td>
                    <td class="small text-nowrap"><?= e(date_fr($t['first_used_at'], 'd/m/y H:i')) ?></td>
                    <td class="small text-nowrap"><?= e(date_fr($t['expires_at'], 'd/m/y H:i')) ?></td>
                    <td class="small text-nowrap"><?= $t['uptime_seconds'] ? e(human_duration((int) $t['uptime_seconds'])) . '<br>' . e(bytes_human($t['bytes_in'] + $t['bytes_out'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$tickets): ?><tr><td colspan="10"><div class="mc-empty"><i class="bi bi-ticket-perforated"></i>Aucun ticket.</div></td></tr><?php endif; ?>
            </tbody></table></div>
        <?php if ($tickets): ?>
        <div class="mc-card-head border-top border-bottom-0 mc-filter-bar">
            <span class="small text-muted">Sélection :</span>
            <select class="form-select form-select-sm" name="bulk_action">
                <option value="print">Imprimer</option><option value="pdf">Exporter en PDF</option><option value="csv">Exporter en CSV</option>
                <?php if (can('tickets.manage')): ?><option value="suspend">Suspendre</option><option value="reactivate">Réactiver</option><option value="cancel">Annuler</option><?php endif; ?>
            </select>
            <select class="form-select form-select-sm" name="template_id"><?php foreach ($templates as $tp): ?><option value="<?= (int) $tp['id'] ?>"><?= e($tp['name']) ?></option><?php endforeach; ?></select>
            <button class="btn btn-sm btn-primary">Appliquer</button>
        </div>
        <?php endif; ?>
    </form>
    <?= pagination($page, $pages, '/tickets', $qs) ?>
</div>
