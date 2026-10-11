<?php
/** Tableau de bord : indicateurs calculés depuis la base réelle. */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
require_permission('parcel.view');
if ((int) $user['must_change_password'] === 1) {
    redirect('profil.php');
}

$stats = StatsService::compute($_GET);
$k = $stats['kpi'];
$areas = admin_area_options();

$pageTitle = 'Tableau de bord';
$activeMenu = 'dashboard';
$vendor = ['chart'];
$scripts = ['dashboard.js'];
require __DIR__ . '/includes/header.php';

$card = static function (string $label, string $value, string $icon, string $cls = '', ?string $href = null): string {
    $inner = '<div class="card kpi ' . $cls . ' h-100"><div class="card-body d-flex justify-content-between align-items-center">'
        . '<div><div class="kpi-value">' . $value . '</div><div class="kpi-label">' . e($label) . '</div></div>'
        . '<i class="bi bi-' . e($icon) . ' fs-2 text-muted"></i></div></div>';
    return '<div class="col-6 col-md-4 col-xl-3">' . ($href ? '<a class="text-decoration-none text-reset" href="' . e($href) . '">' . $inner . '</a>' : $inner) . '</div>';
};
?>
<script type="application/json" id="dashboard-data"><?= json_encode($stats, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<div class="page-header">
    <h1><i class="bi bi-speedometer2"></i> Tableau de bord</h1>
    <form class="d-flex flex-wrap gap-2 align-items-end" method="get">
        <div><label class="form-label small mb-0" for="date_from">Du</label>
            <input class="form-control form-control-sm" type="date" id="date_from" name="date_from" value="<?= e($_GET['date_from'] ?? '') ?>"></div>
        <div><label class="form-label small mb-0" for="date_to">Au</label>
            <input class="form-control form-control-sm" type="date" id="date_to" name="date_to" value="<?= e($_GET['date_to'] ?? '') ?>"></div>
        <div><label class="form-label small mb-0" for="area_id">Région / commune</label>
            <select class="form-select form-select-sm" id="area_id" name="area_id">
                <option value="">Tout le territoire</option>
                <?php foreach ($areas as $a): if (!in_array($a['type'], ['region', 'departement', 'commune'], true)) { continue; } ?>
                    <option value="<?= $a['id'] ?>" <?= (string) ($_GET['area_id'] ?? '') === (string) $a['id'] ? 'selected' : '' ?>><?= e($a['label']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Filtrer</button>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('dashboard.php')) ?>">Réinitialiser</a>
    </form>
</div>

<?php if ($k['demo'] > 0): ?>
<div class="alert demo-banner py-2"><i class="bi bi-exclamation-triangle"></i>
    <strong>Attention :</strong> <?= (int) $k['demo'] ?> parcelle(s) de <strong>démonstration fictives</strong> sont incluses dans ces statistiques.
    Supprimez-les avant toute utilisation réelle (Paramètres &gt; Données de démonstration).</div>
<?php endif; ?>
<?php if ($k['total'] === 0): ?>
<div class="alert alert-info">Aucune parcelle enregistrée pour ces critères. Commencez par <a href="<?= e(url('carte.php')) ?>">dessiner une parcelle sur la carte</a>
    <?php if (can('import.run')): ?>ou <a href="<?= e(url('importation.php')) ?>">importer des données géographiques</a><?php endif; ?>.</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <?= $card('Parcelles enregistrées', fmt_number($k['total']), 'bounding-box', '', url('parcelles.php')) ?>
    <?= $card('Parcelles vérifiées', fmt_number($k['verified']), 'patch-check', 'kpi-green', url('parcelles.php', ['status' => 'verifiee'])) ?>
    <?= $card('En attente de vérification', fmt_number($k['pending']), 'hourglass-split', 'kpi-gray', url('parcelles.php', ['status' => 'soumise'])) ?>
    <?= $card('Sans propriétaire renseigné', fmt_number($k['no_owner']), 'person-x', 'kpi-gray', url('parcelles.php', ['no_owner' => 1])) ?>
    <?= $card('Dossiers incomplets', fmt_number($k['incomplete']), 'clipboard-x', 'kpi-gray', url('rapports.php', ['report' => 'documents_manquants'])) ?>
    <?= $card('Chevauchements à vérifier', fmt_number($k['overlaps']), 'intersect', 'kpi-red', url('rapports.php', ['report' => 'chevauchements'])) ?>
    <?= $card('Superficie cartographiée', fmt_number($k['total_area_m2'] / 10000, 2) . ' ha', 'rulers', 'kpi-green') ?>
    <?= $card('Propriétaires enregistrés', fmt_number($k['owners']), 'people', '', can('owner.view_personal') ? url('proprietaires.php') : null) ?>
</div>

<div class="row g-3">
    <div class="col-lg-8"><div class="card h-100"><div class="card-header">Évolution des enregistrements</div>
        <div class="card-body"><canvas id="chartEvolution" height="110" aria-label="Évolution mensuelle"></canvas></div></div></div>
    <div class="col-lg-4"><div class="card h-100"><div class="card-header">Répartition par catégorie</div>
        <div class="card-body"><canvas id="chartCategory" aria-label="Catégories"></canvas></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header">Parcelles par zone (communes / régions)</div>
        <div class="card-body"><canvas id="chartArea" aria-label="Par zone"></canvas></div></div></div>
    <div class="col-lg-3"><div class="card h-100"><div class="card-header">Statuts de vérification</div>
        <div class="card-body"><canvas id="chartStatus" aria-label="Statuts"></canvas></div></div></div>
    <div class="col-lg-3"><div class="card h-100"><div class="card-header">Nature de la délimitation</div>
        <div class="card-body"><canvas id="chartSurvey" aria-label="Délimitation"></canvas></div></div></div>
    <div class="col-12"><div class="card"><div class="card-header">Activité récente</div>
        <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>Date</th><th>Action</th><th>Parcelle</th><th>Utilisateur</th></tr></thead>
            <tbody>
            <?php foreach ($stats['recent'] as $r): ?>
                <tr><td><?= e(fmt_date($r['date'], true)) ?></td><td><?= e($r['action']) ?></td>
                    <td><a class="ref" href="<?= e(url('parcelle_details.php', ['id' => $r['parcel_id']])) ?>"><?= e($r['ref']) ?></a></td><td><?= e($r['user']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$stats['recent']): ?><tr><td colspan="4" class="text-muted">Aucune activité.</td></tr><?php endif; ?>
            </tbody></table></div></div></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
