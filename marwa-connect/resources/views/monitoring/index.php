<?php
$title = 'Monitoring';
$sel = null;
foreach ($routers as $r) { if ((int) $r['id'] === $selected) { $sel = $r; } }
?>
<div class="mc-page-head"><div><h1><span class="mc-accent">Monitoring</span> réseau</h1><p>Disponibilité, charge et trafic des routeurs. Les graphiques se mettent à jour sans recharger la page.</p></div></div>
<div class="row g-3 mb-3">
    <?php foreach ($routers as $r): ?>
        <div class="col-sm-6 col-xl-3">
            <a class="mc-card mc-kpi d-block text-reset text-decoration-none <?= $r['connection_state'] === 'online' ? 'green' : ($r['connection_state'] === 'offline' ? '' : 'navy') ?> <?= (int) $r['id'] === $selected ? 'border-primary' : '' ?>" href="<?= e(url('/monitoring', ['router' => $r['id']])) ?>">
                <div class="mc-kpi-icon"><i class="bi bi-router"></i></div>
                <div class="mc-kpi-label"><?= e($r['name']) ?></div>
                <div class="mc-kpi-value fs-5"><?= status_badge($r['connection_state']) ?></div>
                <div class="mc-kpi-sub">CPU <?= $r['last_cpu'] ?? '—' ?> % · RAM <?= $r['last_memory_used_pct'] ?? '—' ?> % · <?= $r['last_active_users'] ?? '—' ?> client(s)<br>
                    Disponibilité 24 h : <?= isset($availability[(int) $r['id']]) ? $availability[(int) $r['id']] . ' %' : '—' ?></div>
            </a>
        </div>
    <?php endforeach; ?>
    <?php if (!$routers): ?><div class="col-12"><div class="mc-card"><div class="mc-empty"><i class="bi bi-activity"></i>Aucun routeur à surveiller. <a href="<?= e(url('/routers/create')) ?>">Ajouter un routeur</a></div></div></div><?php endif; ?>
</div>
<?php if ($sel): ?>
<div class="mc-card mb-3" id="mc-monitor" data-live="<?= e(url('/monitoring/' . $sel['id'] . '/live')) ?>" data-history="<?= e(url('/monitoring/' . $sel['id'] . '/history')) ?>">
    <div class="mc-card-head flex-wrap"><h2><i class="bi bi-broadcast me-2 text-primary"></i><?= e($sel['name']) ?> — en direct</h2>
        <div class="mc-filter-bar">
            <select class="form-select form-select-sm" id="mc-mon-iface" aria-label="Interface">
                <?php foreach ($interfaces as $i): ?><option value="<?= e($i['name']) ?>" <?= $i['name'] === $sel['wan_interface'] ? 'selected' : '' ?>><?= e($i['name']) ?><?= $i['running'] ? '' : ' (inactive)' ?></option><?php endforeach; ?>
                <?php if (!$interfaces): ?><option value="">Synchronisez le routeur</option><?php endif; ?>
            </select>
            <span class="mc-status muted" id="mc-mon-state">Démarrage…</span>
        </div></div>
    <div class="mc-card-body">
        <div class="row g-3 mb-3 text-center">
            <div class="col-6 col-md-3"><div class="text-muted small">CPU</div><div class="fs-4 fw-bold" id="mc-mon-cpu">—</div></div>
            <div class="col-6 col-md-3"><div class="text-muted small">Mémoire</div><div class="fs-4 fw-bold" id="mc-mon-mem">—</div></div>
            <div class="col-6 col-md-3"><div class="text-muted small">Clients connectés</div><div class="fs-4 fw-bold" id="mc-mon-active">—</div></div>
            <div class="col-6 col-md-3"><div class="text-muted small">Uptime</div><div class="fs-6 fw-bold" id="mc-mon-uptime">—</div></div>
        </div>
        <div class="mc-chart"><canvas id="mc-chart-traffic" aria-label="Débit en temps réel"></canvas></div>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-6"><div class="mc-card"><div class="mc-card-head"><h2>CPU et mémoire (24 h)</h2></div><div class="mc-card-body"><div class="mc-chart sm"><canvas id="mc-chart-load"></canvas></div></div></div></div>
    <div class="col-lg-6"><div class="mc-card"><div class="mc-card-head"><h2>Clients connectés (24 h)</h2></div><div class="mc-card-body"><div class="mc-chart sm"><canvas id="mc-chart-users"></canvas></div></div></div></div>
</div>
<?php endif; ?>
<?php App\Core\View::start('scripts'); ?>
<script src="<?= e(asset('vendor/chartjs/chart.umd.js')) ?>"></script>
<script src="<?= e(asset('js/charts.js')) ?>"></script>
<?php App\Core\View::stop(); ?>
