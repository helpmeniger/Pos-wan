<?php
$title = 'Tableau de bord';
$t = static fn (string $s) => (int) ($tickets[$s] ?? 0);
$periods = ['today' => "Aujourd'hui", 'yesterday' => 'Hier', '7d' => '7 jours', '30d' => '30 jours'];
$bps = static fn ($v) => $v >= 1e6 ? number_format($v / 1e6, 1, ',', ' ') . ' Mbit/s' : number_format($v / 1e3, 0, ',', ' ') . ' kbit/s';
$qs = ['router_id' => $rid];
?>
<div class="mc-page-head">
    <div><h1>Tableau de <span class="mc-accent">bord</span></h1><p><?= e($p['label']) ?> · <?= e(date_fr($p['from'], 'd/m/Y')) ?><?= $p['from'] !== $p['to'] ? ' → ' . e(date_fr($p['to'], 'd/m/Y')) : '' ?> · données réelles</p></div>
    <form class="mc-filter-bar" method="get" action="<?= e(url('/')) ?>">
        <div class="btn-group" role="group" aria-label="Période">
            <?php foreach ($periods as $k => $l): ?><a class="btn btn-sm <?= $p['key'] === $k ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('/', $qs + ['period' => $k])) ?>"><?= e($l) ?></a><?php endforeach; ?>
        </div>
        <input type="hidden" name="period" value="custom">
        <input class="form-control form-control-sm" type="date" name="from" value="<?= e($p['from']) ?>" aria-label="Du">
        <input class="form-control form-control-sm" type="date" name="to" value="<?= e($p['to']) ?>" aria-label="Au">
        <select class="form-select form-select-sm" name="router_id" aria-label="Routeur"><option value="0">Tous les routeurs</option><?php foreach ($allRouters as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $rid === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-sm btn-navy">Appliquer</button>
    </form>
</div>

<?php if ($setup['routers'] === 0 || $setup['tickets'] === 0): ?>
<div class="mc-card mb-3"><div class="mc-card-body d-flex flex-wrap gap-3 align-items-center">
    <strong><i class="bi bi-flag text-primary"></i> Mise en service :</strong>
    <a class="btn btn-sm <?= $setup['routers'] ? 'btn-outline-success' : 'btn-primary' ?>" href="<?= e(url('/routers/create')) ?>"><?= $setup['routers'] ? '✓' : '1.' ?> Ajouter un routeur</a>
    <a class="btn btn-sm <?= $setup['offers_linked'] ? 'btn-outline-success' : 'btn-outline-primary' ?>" href="<?= e(url('/offers')) ?>"><?= $setup['offers_linked'] ? '✓' : '2.' ?> Associer les offres</a>
    <a class="btn btn-sm <?= $setup['tickets'] ? 'btn-outline-success' : 'btn-outline-primary' ?>" href="<?= e(url('/tickets/generate')) ?>"><?= $setup['tickets'] ? '✓' : '3.' ?> Générer des tickets</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/pos')) ?>">4. Vendre en caisse</a>
</div></div>
<?php endif; ?>

<?php if ($showMoney): ?>
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3"><div class="mc-card mc-kpi green"><div class="mc-kpi-icon"><i class="bi bi-safe"></i></div><div class="mc-kpi-label">Solde en caisse</div><div class="mc-kpi-value"><?= e(money($kpi['cash'])) ?></div><div class="mc-kpi-sub">Espèces attendues dans les caisses ouvertes</div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="mc-card mc-kpi"><div class="mc-kpi-icon"><i class="bi bi-cash-stack"></i></div><div class="mc-kpi-label">CA du jour</div><div class="mc-kpi-value"><?= e(money($kpi['today'])) ?></div><div class="mc-kpi-sub">Période : <?= e(money($sales['total'])) ?> · <?= (int) $sales['n'] ?> vente(s)</div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="mc-card mc-kpi navy"><div class="mc-kpi-icon"><i class="bi bi-calendar3"></i></div><div class="mc-kpi-label">CA du mois</div><div class="mc-kpi-value"><?= e(money($kpi['month'])) ?></div><div class="mc-kpi-sub">Physique <?= (int) $sales['physical_n'] ?> · En ligne <?= (int) $sales['online_n'] ?> · Revendeurs <?= (int) $sales['reseller_n'] ?> (période)</div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="mc-card mc-kpi amber"><div class="mc-kpi-icon"><i class="bi bi-ticket-perforated"></i></div><div class="mc-kpi-label">Tickets vendus (période)</div><div class="mc-kpi-value"><?= (int) $kpi['tickets_sold'] ?></div><div class="mc-kpi-sub"><?= $t('available') ?> disponibles · <?= $t('active') + $t('used') ?> utilisés · <?= $t('expired') ?> expirés</div></div></div>
</div>
<?php endif; ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-2"><div class="mc-card mc-kpi navy"><div class="mc-kpi-label">Routeurs</div><div class="mc-kpi-value"><?= (int) $net['online'] ?>/<?= (int) $net['total'] ?></div><div class="mc-kpi-sub"><?= (int) $net['offline'] ?> hors ligne</div></div></div>
    <div class="col-6 col-xl-2"><div class="mc-card mc-kpi green"><div class="mc-kpi-label">Clients connectés</div><div class="mc-kpi-value"><?= (int) $net['clients'] ?></div><div class="mc-kpi-sub">Dernier relevé</div></div></div>
    <div class="col-6 col-xl-2"><div class="mc-card mc-kpi"><div class="mc-kpi-label">Sessions Wi-Fi</div><div class="mc-kpi-value"><?= (int) $usage['sessions'] ?></div><div class="mc-kpi-sub">Durée moy. <?= e(human_duration((int) $usage['avg_up'])) ?></div></div></div>
    <div class="col-6 col-xl-2"><div class="mc-card mc-kpi amber"><div class="mc-kpi-label">Téléchargement</div><div class="mc-kpi-value fs-5"><?= e(bytes_human($usage['download'])) ?></div><div class="mc-kpi-sub">Envoi <?= e(bytes_human($usage['upload'])) ?></div></div></div>
    <div class="col-6 col-xl-2"><div class="mc-card mc-kpi navy"><div class="mc-kpi-label">Débit actuel</div><div class="mc-kpi-value fs-6">↓ <?= e($bps($net['tx_bps'])) ?></div><div class="mc-kpi-sub">↑ <?= e($bps($net['rx_bps'])) ?> (interfaces surveillées)</div></div></div>
    <div class="col-6 col-xl-2"><div class="mc-card mc-kpi <?= $notPushed ? '' : 'green' ?>"><div class="mc-kpi-label">Stock vendable</div><div class="mc-kpi-value"><?= max(0, $t('available') - $notPushed) ?></div><div class="mc-kpi-sub"><?= $notPushed ? $notPushed . ' non créés sur routeur' : 'Tous créés sur les routeurs' ?></div></div></div>
</div>

<script type="application/json" id="mc-dash-data"><?= json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<div class="row g-3 mb-3">
    <div class="col-xl-8"><div class="mc-card h-100"><div class="mc-card-head"><h2>Ventes et connexions</h2>
        <div class="btn-group btn-group-sm" role="group"><button class="btn btn-primary" data-sales-view="daily">Journalières</button><button class="btn btn-outline-secondary" data-sales-view="weekly">Hebdo.</button><button class="btn btn-outline-secondary" data-sales-view="monthly">Mensuelles</button></div></div>
        <div class="mc-card-body"><div class="mc-chart"><canvas id="mc-chart-sales"></canvas></div></div></div></div>
    <div class="col-xl-4"><div class="mc-card h-100"><div class="mc-card-head"><h2>Répartition par routeur</h2></div><div class="mc-card-body"><div class="mc-chart"><canvas id="mc-chart-routers"></canvas></div></div></div></div>
    <div class="col-xl-4"><div class="mc-card h-100"><div class="mc-card-head"><h2>Tickets vendus par forfait</h2></div><div class="mc-card-body"><div class="mc-chart"><canvas id="mc-chart-offers"></canvas></div></div></div></div>
    <div class="col-xl-8"><div class="mc-card h-100"><div class="mc-card-head"><h2>Consommation Internet (Mo / jour)</h2></div><div class="mc-card-body"><div class="mc-chart"><canvas id="mc-chart-traffic-days"></canvas></div></div></div></div>
</div>

<div class="row g-3">
    <div class="col-xl-6"><div class="mc-card h-100"><div class="mc-card-head"><h2>État des routeurs</h2><a class="small" href="<?= e(url('/monitoring')) ?>">Monitoring</a></div>
        <div class="table-responsive"><table class="table mc-table table-sm"><thead><tr><th>Routeur</th><th>État</th><th class="text-end">CPU</th><th class="text-end">RAM</th><th class="text-end">Clients</th><th class="text-end">Interfaces</th></tr></thead><tbody>
        <?php foreach ($routers as $r): ?><tr><td><a href="<?= e(url('/routers/' . $r['id'])) ?>"><?= e($r['name']) ?></a></td><td><?= status_badge($r['connection_state']) ?></td>
            <td class="text-end"><?= $r['last_cpu'] !== null ? (int) $r['last_cpu'] . ' %' : '—' ?></td><td class="text-end"><?= $r['last_memory_used_pct'] !== null ? (int) $r['last_memory_used_pct'] . ' %' : '—' ?></td>
            <td class="text-end"><?= $r['last_active_users'] ?? '—' ?></td><td class="text-end"><?= (int) $r['ifaces_up'] ?>/<?= (int) $r['ifaces'] ?> actives</td></tr><?php endforeach; ?>
        <?php if (!$routers): ?><tr><td colspan="6" class="text-center text-muted py-3">Aucun routeur.</td></tr><?php endif; ?>
        </tbody></table></div></div></div>
    <?php if ($showMoney): ?>
    <div class="col-xl-3 col-md-6"><div class="mc-card h-100"><div class="mc-card-head"><h2>Revenus par routeur</h2></div><ul class="list-group list-group-flush">
        <?php foreach ($byRouter as $r): ?><li class="list-group-item d-flex justify-content-between small"><span><?= e($r['name']) ?> (<?= (int) $r['n'] ?>)</span><strong><?= e(money($r['total'])) ?></strong></li><?php endforeach; ?>
        <?php if (!$byRouter): ?><li class="list-group-item small text-muted">Aucune vente.</li><?php endif; ?></ul></div></div>
    <div class="col-xl-3 col-md-6"><div class="mc-card h-100"><div class="mc-card-head"><h2>Revenus par revendeur</h2></div><ul class="list-group list-group-flush">
        <?php foreach ($byReseller as $r): ?><li class="list-group-item d-flex justify-content-between small"><span><?= e($r['name']) ?> (<?= (int) $r['n'] ?>)</span><strong><?= e(money($r['total'])) ?></strong></li><?php endforeach; ?>
        <?php if (!$byReseller): ?><li class="list-group-item small text-muted">Aucune vente revendeur.</li><?php endif; ?></ul></div></div>
    <?php endif; ?>
</div>
<?php App\Core\View::start('scripts'); ?>
<script src="<?= e(asset('vendor/chartjs/chart.umd.js')) ?>"></script>
<script src="<?= e(asset('js/charts.js')) ?>"></script>
<?php App\Core\View::stop(); ?>
