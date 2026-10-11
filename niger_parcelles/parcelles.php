<?php
/** Liste des parcelles avec recherche avancée, tri, pagination et exports. */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

require_login();
require_permission('parcel.view');

$f = ParcelQuery::filtersFrom($_GET);
$sort = isset($_GET['sort']) && array_key_exists($_GET['sort'], ParcelQuery::SORTS) ? $_GET['sort'] : 'created';
$dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
[$where, $params] = ParcelQuery::where($f);
$total = (int) db_value("SELECT COUNT(*) FROM parcels p WHERE $where", $params);
$pg = paginate($total, 25);
$res = ParcelQuery::search($f, $pg['per_page'], $pg['offset'], $sort, $dir);
$areas = admin_area_options();
$exportQuery = array_diff_key($_GET, ['page' => 1]);

$sortLink = static function (string $key, string $label) use ($sort, $dir): string {
    $q = $_GET;
    $q['sort'] = $key;
    $q['dir'] = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    unset($q['page']);
    $icon = $sort === $key ? ($dir === 'asc' ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>') : '';
    return '<a class="text-reset text-decoration-none" href="?' . e(http_build_query($q)) . '">' . e($label) . $icon . '</a>';
};

$pageTitle = 'Parcelles';
$activeMenu = 'parcelles';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <h1><i class="bi bi-bounding-box"></i> Parcelles <span class="text-muted fs-6">(<?= fmt_number($total) ?>)</span></h1>
    <div class="d-flex flex-wrap gap-2">
        <?php if (can('parcel.create')): ?><a class="btn btn-sm btn-primary" href="<?= e(url('carte.php')) ?>"><i class="bi bi-plus-lg"></i> Nouvelle parcelle (carte)</a><?php endif; ?>
        <?php if (can('export.run')): ?>
        <div class="btn-group">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-download"></i> Exporter la sélection</button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="<?= e(url('api/export.php', ['type' => 'csv'] + $exportQuery)) ?>"><i class="bi bi-filetype-csv"></i> CSV</a></li>
                <li><a class="dropdown-item" href="<?= e(url('api/export.php', ['type' => 'xlsx'] + $exportQuery)) ?>"><i class="bi bi-file-earmark-excel"></i> Excel (.xlsx)</a></li>
                <li><a class="dropdown-item" href="<?= e(url('api/export.php', ['type' => 'geojson'] + $exportQuery)) ?>"><i class="bi bi-filetype-json"></i> GeoJSON (géométries)</a></li>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>

<form class="card mb-3" method="get">
    <div class="card-body row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label small mb-0" for="q">Recherche</label>
            <input class="form-control form-control-sm" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Référence, réf. cadastrale<?= can('owner.view_personal') ? ', propriétaire' : '' ?>, localisation"></div>
        <div class="col-md-3"><label class="form-label small mb-0" for="area_id">Zone (inclut les sous-zones)</label>
            <select class="form-select form-select-sm" id="area_id" name="area_id"><option value="">Toutes</option>
                <?php foreach ($areas as $a): ?><option value="<?= $a['id'] ?>" <?= $f['area_id'] === $a['id'] ? 'selected' : '' ?>><?= e($a['label']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-2"><label class="form-label small mb-0" for="category">Catégorie</label>
            <select class="form-select form-select-sm" id="category" name="category"><option value="">Toutes</option>
                <?php foreach (labels('category') as $kk => $v): ?><option value="<?= e($kk) ?>" <?= $f['category'] === $kk ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-3"><label class="form-label small mb-0" for="status">Statut de vérification</label>
            <select class="form-select form-select-sm" id="status" name="status"><option value="">Tous</option>
                <?php foreach (labels('verification_status') as $kk => $v): ?><option value="<?= e($kk) ?>" <?= $f['status'] === $kk ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-3"><label class="form-label small mb-0" for="survey_level">Délimitation</label>
            <select class="form-select form-select-sm" id="survey_level" name="survey_level"><option value="">Toutes</option>
                <?php foreach (labels('survey_level') as $kk => $v): ?><option value="<?= e($kk) ?>" <?= $f['survey_level'] === $kk ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="min_area">Superficie min (m²)</label>
            <input class="form-control form-control-sm" type="number" step="any" min="0" id="min_area" name="min_area" value="<?= e($f['min_area']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="max_area">Superficie max (m²)</label>
            <input class="form-control form-control-sm" type="number" step="any" min="0" id="max_area" name="max_area" value="<?= e($f['max_area']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="lat">Latitude (GPS)</label>
            <input class="form-control form-control-sm" id="lat" name="lat" value="<?= e($f['lat']) ?>" placeholder="13.5116"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="lng">Longitude (GPS)</label>
            <input class="form-control form-control-sm" id="lng" name="lng" value="<?= e($f['lng']) ?>" placeholder="2.1254"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="date_from">Créée du</label>
            <input class="form-control form-control-sm" type="date" id="date_from" name="date_from" value="<?= e($f['date_from']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="date_to">au</label>
            <input class="form-control form-control-sm" type="date" id="date_to" name="date_to" value="<?= e($f['date_to']) ?>"></div>
        <div class="col-md-8 d-flex flex-wrap gap-3 small">
            <div class="form-check"><input class="form-check-input" type="checkbox" id="overlap" name="overlap" value="1" <?= $f['overlap'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="overlap">Chevauchement à vérifier</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="no_owner" name="no_owner" value="1" <?= $f['no_owner'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="no_owner">Sans propriétaire</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="archived" name="archived" value="1" <?= $f['archived'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="archived">Parcelles archivées</label></div>
        </div>
        <div class="col-md-4 d-flex gap-2 justify-content-md-end">
            <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Rechercher</button>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('parcelles.php')) ?>">Réinitialiser</a>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead><tr>
                <th><?= $sortLink('reference', 'Référence') ?></th><th>Réf. cadastrale</th><th>Zone</th>
                <th><?= $sortLink('category', 'Catégorie') ?></th><th class="text-end"><?= $sortLink('area', 'Superficie') ?></th>
                <th><?= $sortLink('status', 'Statut') ?></th><th>Délimitation</th><th class="text-center">Propr.</th>
                <th><?= $sortLink('created', 'Création') ?></th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($res['rows'] as $r): ?>
                <tr>
                    <td><a class="ref" href="<?= e(url('parcelle_details.php', ['id' => $r['id']])) ?>"><?= e($r['parcel_reference']) ?></a>
                        <?php if ((int) $r['has_overlap_warning'] === 1): ?><i class="bi bi-intersect text-danger" title="Chevauchement à vérifier"></i><?php endif; ?>
                        <?php if ((int) $r['is_demo'] === 1): ?><span class="badge text-bg-warning">DÉMO</span><?php endif; ?></td>
                    <td><?= e($r['cadastral_reference'] ?? '—') ?></td>
                    <td><?= e($r['area_name'] ?? '—') ?></td>
                    <td><?= e(label('category', $r['category'])) ?></td>
                    <td class="text-end text-nowrap"><?= fmt_number($r['area_m2'], 2) ?> m²</td>
                    <td><?= status_badge($r['verification_status']) ?></td>
                    <td class="small"><?= e(label('survey_level', $r['survey_level'])) ?></td>
                    <td class="text-center"><?= (int) $r['owner_count'] ?></td>
                    <td class="text-nowrap"><?= e(fmt_date($r['created_at'])) ?></td>
                    <td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('carte.php', ['parcel' => $r['id']])) ?>" title="Voir sur la carte"><i class="bi bi-map"></i></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$res['rows']): ?><tr><td colspan="10" class="text-center text-muted py-4">Aucune parcelle ne correspond à ces critères.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap">
        <span class="small text-muted">Page <?= $pg['page'] ?> / <?= $pg['pages'] ?></span>
        <?= pagination_links($pg) ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
