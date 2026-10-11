<?php
/** Rapports de gestion : affichage à l'écran et exports CSV, Excel, PDF. */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

require_login();
require_permission('report.view');

$key = isset($_GET['report']) && isset(ReportService::REPORTS[$_GET['report']]) ? $_GET['report'] : 'parcelles_commune';
$report = ReportService::run($key, $_GET);
$areas = admin_area_options();
$pg = paginate(count($report['rows']), 50);
$visible = array_slice($report['rows'], $pg['offset'], $pg['per_page']);
$exportQuery = array_diff_key($_GET, ['page' => 1]) + ['report' => $key];

$pageTitle = 'Rapports';
$activeMenu = 'rapports';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header"><h1><i class="bi bi-file-earmark-bar-graph"></i> Rapports</h1>
    <?php if (can('export.run')): ?><div class="btn-group">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('api/export.php', ['type' => 'report', 'format' => 'csv'] + $exportQuery)) ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('api/export.php', ['type' => 'report', 'format' => 'xlsx'] + $exportQuery)) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <a class="btn btn-sm btn-primary" href="<?= e(url('api/export.php', ['type' => 'report', 'format' => 'pdf'] + $exportQuery)) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a>
    </div><?php endif; ?>
</div>
<div class="row g-3">
    <div class="col-lg-3"><div class="list-group">
        <?php foreach (ReportService::REPORTS as $k => $t): ?>
            <a class="list-group-item list-group-item-action<?= $k === $key ? ' active' : '' ?>" href="<?= e(url('rapports.php', ['report' => $k])) ?>"><?= e($t) ?></a>
        <?php endforeach; ?>
    </div>
    <p class="small text-muted mt-2">Les rapports sont calculés à partir de la base. Les données personnelles n'y figurent que pour les profils autorisés.</p></div>
    <div class="col-lg-9">
        <form class="card card-body mb-3" method="get"><input type="hidden" name="report" value="<?= e($key) ?>">
            <div class="row g-2 align-items-end">
                <?php if ($key !== 'imports' && $key !== 'chevauchements'): ?>
                <div class="col-md-4"><label class="form-label small mb-0" for="area_id">Zone</label><select class="form-select form-select-sm" id="area_id" name="area_id"><option value="">Toutes</option>
                    <?php foreach ($areas as $a): ?><option value="<?= $a['id'] ?>" <?= (string) ($_GET['area_id'] ?? '') === (string) $a['id'] ? 'selected' : '' ?>><?= e($a['label']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label small mb-0" for="date_from">Du</label><input class="form-control form-control-sm" type="date" id="date_from" name="date_from" value="<?= e($_GET['date_from'] ?? '') ?>"></div>
                <div class="col-md-3"><label class="form-label small mb-0" for="date_to">Au</label><input class="form-control form-control-sm" type="date" id="date_to" name="date_to" value="<?= e($_GET['date_to'] ?? '') ?>"></div>
                <?php endif; ?>
                <?php if ($key === 'chevauchements'): ?>
                <div class="col-md-4"><label class="form-label small mb-0" for="os">Statut</label><select class="form-select form-select-sm" id="os" name="overlap_status">
                    <?php foreach (labels('overlap_status') as $k => $l): ?><option value="<?= e($k) ?>" <?= ($_GET['overlap_status'] ?? 'a_verifier') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
                <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Appliquer</button></div>
            </div></form>
        <div class="card"><div class="card-header d-flex justify-content-between"><span><?= e($report['title']) ?></span><span class="text-muted small"><?= count($report['rows']) ?> ligne(s)</span></div>
            <?php if ($report['description']): ?><div class="card-body py-2 small text-muted border-bottom"><?= e($report['description']) ?></div><?php endif; ?>
            <div class="table-responsive"><table class="table table-sm table-striped mb-0">
                <thead><tr><?php foreach ($report['columns'] as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead><tbody>
                <?php foreach ($visible as $row): ?><tr><?php foreach ($row as $i => $v): ?>
                    <td><?php if ($i === 0 || ($key === 'chevauchements' && $i === 1) || ($key === 'parcelles_commune' && $i === 1) || ($key === 'mutations' && $i === 1)):
                        $refCol = ($key === 'parcelles_commune' || $key === 'mutations') ? 1 : 0;
                        if ($i === $refCol || $key === 'chevauchements') {
                            $pid = db_value('SELECT id FROM parcels WHERE parcel_reference = ?', [(string) $v]);
                            echo $pid ? '<a class="ref" href="' . e(url('parcelle_details.php', ['id' => $pid])) . '">' . e($v) . '</a>' : e($v);
                        } else { echo e($v); }
                    else: echo is_float($v) ? fmt_number($v, 2) : e($v); endif; ?></td>
                <?php endforeach; ?></tr><?php endforeach; ?>
                <?php if (!$visible): ?><tr><td colspan="<?= count($report['columns']) ?>" class="text-center text-muted py-4">Aucune donnée pour ces critères.</td></tr><?php endif; ?>
                </tbody></table></div>
            <div class="card-footer"><?= pagination_links($pg) ?></div></div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
