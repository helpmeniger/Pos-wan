<?php
use App\Services\TicketPrintService;
use App\Services\TicketService;
$title = 'Lot ' . $batch['batch_code'];
?>
<div class="mc-page-head">
    <div><h1>Lot <span class="mc-accent"><?= e($batch['batch_code']) ?></span> <?= status_badge($batch['status']) ?></h1>
        <p><?= (int) $batch['quantity'] ?> × <?= e($batch['offer_name']) ?> (<?= e(money($batch['price'])) ?>) sur <?= e($batch['router_name']) ?> — <?= e(date_fr($batch['created_at'])) ?> par <?= e($batch['creator'] ?? '—') ?></p></div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/tickets/batches')) ?>"><i class="bi bi-arrow-left"></i> Lots</a>
</div>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="mc-card mb-3"><div class="mc-card-head"><h2>Création sur le routeur</h2></div>
            <div class="mc-card-body">
                <?php $pct = $batch['quantity'] ? round($batch['pushed_count'] * 100 / $batch['quantity']) : 0; ?>
                <div class="d-flex justify-content-between small mb-1"><span><?= (int) $batch['pushed_count'] ?> / <?= (int) $batch['quantity'] ?> créés</span><span><?= $pct ?> %</span></div>
                <div class="progress mc-progress-thin mb-3"><div class="progress-bar bg-<?= $pct >= 100 ? 'success' : 'warning' ?>" style="width: <?= $pct ?>%"></div></div>
                <?php if ($batch['last_error']): ?><div class="alert alert-warning small"><?= e($batch['last_error']) ?></div><?php endif; ?>
                <?php foreach ($errors as $er): ?><div class="small text-muted"><code><?= e($er['username']) ?></code> — <?= e($er['push_error'] ?: 'en attente') ?></div><?php endforeach; ?>
                <?php if ($batch['pushed_count'] < $batch['quantity'] && can('tickets.generate')): ?>
                    <form method="post" action="<?= e(url('/tickets/batches/' . $batch['id'] . '/push')) ?>" class="mt-3"><?= csrf_field() ?><button class="btn btn-primary"><i class="bi bi-cloud-upload me-1"></i> Re-pousser vers le routeur</button></form>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-3 mt-3">
                    <?php foreach (TicketService::STATUSES as $k => $l): ?><div><div class="text-muted small"><?= e($l) ?></div><div class="fw-bold fs-5"><?= (int) ($counts[$k] ?? 0) ?></div></div><?php endforeach; ?>
                </div>
            </div></div>
        <div class="mc-card"><div class="mc-card-head"><h2>Paramètres</h2></div><div class="mc-card-body row g-2 small">
            <div class="col-6 col-md-4"><span class="text-muted d-block">Préfixe</span><?= e($batch['prefix'] ?: '—') ?></div>
            <div class="col-6 col-md-4"><span class="text-muted d-block">Longueurs</span><?= (int) $batch['username_length'] ?> / <?= (int) $batch['password_length'] ?></div>
            <div class="col-6 col-md-4"><span class="text-muted d-block">Identiques</span><?= $batch['same_credentials'] ? 'Oui' : 'Non' ?></div>
            <div class="col-6 col-md-4"><span class="text-muted d-block">Activation</span><?= e(TicketService::ACTIVATION[$batch['activation_mode']]) ?></div>
            <div class="col-6 col-md-4"><span class="text-muted d-block">Validité</span><?= e(TicketService::VALIDITY[$batch['validity_mode']]) ?></div>
            <div class="col-6 col-md-4"><span class="text-muted d-block">Expiration fixe</span><?= e(date_fr($batch['expires_at'])) ?></div>
        </div></div>
    </div>
    <div class="col-lg-5">
        <form class="mc-card" method="get" action="<?= e(url('/tickets/print')) ?>" target="_blank">
            <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
            <div class="mc-card-head"><h2><i class="bi bi-printer me-2 text-primary"></i>Imprimer / exporter</h2></div>
            <div class="mc-card-body">
                <label class="form-label">Modèle</label>
                <select class="form-select mb-2" name="template_id"><?php foreach ($templates as $tp): ?><option value="<?= (int) $tp['id'] ?>"><?= e($tp['name']) ?></option><?php endforeach; ?></select>
                <label class="form-label">Format</label>
                <select class="form-select mb-2" name="format"><option value="">Format du modèle</option><?php foreach (TicketPrintService::FORMATS as $k => $f): ?><option value="<?= $k ?>"><?= e($f['label']) ?></option><?php endforeach; ?></select>
                <select class="form-select mb-3" name="only"><option value="available">Tickets disponibles uniquement</option><option value="all">Tous les tickets du lot</option></select>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary flex-fill" name="output" value="html"><i class="bi bi-printer"></i> Imprimer</button>
                    <button class="btn btn-outline-primary flex-fill" name="output" value="pdf"><i class="bi bi-filetype-pdf"></i> PDF</button>
                </div>
                <a class="btn btn-outline-secondary w-100 mt-2" href="<?= e(url('/tickets/export', ['batch_id' => $batch['id'], 'only' => 'all'])) ?>"><i class="bi bi-filetype-csv"></i> Export CSV</a>
                <a class="btn btn-link w-100 mt-1" href="<?= e(url('/tickets', ['batch_id' => $batch['id']])) ?>">Voir les tickets du lot</a>
            </div>
        </form>
    </div>
</div>
