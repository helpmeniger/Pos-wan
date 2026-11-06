<?php
/**
 * Centre de contrôle des vérificateurs : dossiers à risque (anti-fraude),
 * qualité des limites communes, dossiers en attente trop longtemps.
 */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
require_permission('parcel.verify');
$tab = in_array($_GET['tab'] ?? '', ['risques', 'limites', 'delais'], true) ? $_GET['tab'] : 'risques';
$areaId = isset($_GET['area_id']) && ctype_digit((string) $_GET['area_id']) ? (int) $_GET['area_id'] : null;

if (request_method() === 'POST') {
    require_csrf();
    try {
        switch ($_POST['action'] ?? '') {
            case 'snap':
                require_permission('parcel.edit');
                $r = QualityService::snap((int) $_POST['move_id'], (int) $_POST['ref_id'], (int) $user['id']);
                flash('success', "Limite de {$r['reference']} recollée : {$r['moved']} sommet(s) déplacé(s) (max. " . fmt_number($r['max_shift'], 2)
                    . " m). Nouvelle version {$r['version']} enregistrée, l'ancienne reste dans l'historique.");
                break;
            case 'dismiss':
                $reason = input_str('reason', 255);
                if (!$reason) {
                    throw new UserFacingException('Indiquez pourquoi l\'écart est accepté (mur mitoyen épais, ruelle, servitude…).');
                }
                QualityService::dismiss((int) $_POST['a'], (int) $_POST['b'], $reason, (int) $user['id']);
                flash('success', 'Écart accepté : il ne sera plus signalé.');
                break;
            default:
                throw new UserFacingException('Action inconnue.');
        }
    } catch (UserFacingException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('controle.php', array_filter(['tab' => $tab, 'area_id' => $areaId]));
}

// ---------------------------------------------------------------- Données
$risks = RiskService::compute();
if ($areaId) {
    $allowed = array_map('intval', array_column(db_all('SELECT id FROM parcels WHERE administrative_area_id IN ('
        . implode(',', admin_area_descendants($areaId)) . ')'), 'id'));
    $risks = array_intersect_key($risks, array_flip($allowed));
}
$minLevel = $_GET['level'] ?? 'moyen';
$riskRows = array_filter($risks, static fn ($r) => $minLevel === 'tous' || ($minLevel === 'eleve' ? $r['level'] === 'eleve' : $r['level'] !== 'faible'));
$info = [];
if ($risks) {
    foreach (db_all('SELECT p.id, p.parcel_reference, p.verification_status, p.declared_status, a.name AS area_name FROM parcels p
                     LEFT JOIN administrative_areas a ON a.id = p.administrative_area_id WHERE p.id IN (' . implode(',', array_keys($risks)) . ')') as $r) {
        $info[(int) $r['id']] = $r;
    }
}
$counts = ['eleve' => 0, 'moyen' => 0, 'faible' => 0];
foreach ($risks as $r) {
    $counts[$r['level']]++;
}
$issues = $tab === 'limites' ? QualityService::scan($areaId) : [];
$overdueDays = max(1, (int) setting('risk_overdue_days', 15));
$overdue = RiskService::overdue($overdueDays);

$pageTitle = 'Contrôle';
$activeMenu = 'controle';
require __DIR__ . '/includes/header.php';
$levelBadge = static fn (string $l, int $s) => '<span class="badge text-bg-' . ['eleve' => 'danger', 'moyen' => 'warning', 'faible' => 'secondary'][$l] . '">' . $s . '</span>';
?>
<div class="page-header">
    <h1><i class="bi bi-shield-check"></i> Centre de contrôle</h1>
    <form method="get" class="d-flex gap-2"><input type="hidden" name="tab" value="<?= e($tab) ?>">
        <select class="form-select form-select-sm" name="area_id" aria-label="Zone" data-autosubmit>
            <option value="">Tout le territoire</option>
            <?php foreach (admin_area_options() as $a): ?><option value="<?= $a['id'] ?>" <?= $areaId === $a['id'] ? 'selected' : '' ?>><?= e($a['label']) ?></option><?php endforeach; ?>
        </select><noscript><button class="btn btn-sm btn-primary">OK</button></noscript></form>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card kpi kpi-red"><div class="card-body"><div class="kpi-value"><?= $counts['eleve'] ?></div><div class="kpi-label">Dossiers à risque élevé</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card kpi"><div class="card-body"><div class="kpi-value"><?= $counts['moyen'] ?></div><div class="kpi-label">Risque moyen</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card kpi kpi-gray"><div class="card-body"><div class="kpi-value"><?= count($overdue) ?></div><div class="kpi-label">En attente depuis plus de <?= $overdueDays ?> jours</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card kpi kpi-green"><div class="card-body"><div class="kpi-value"><?= $tab === 'limites' ? count($issues) : '…' ?></div><div class="kpi-label">Défauts de limites communes</div></div></div></div>
</div>

<ul class="nav nav-tabs mb-3">
    <?php foreach (['risques' => 'Dossiers à risque', 'limites' => 'Qualité des limites', 'delais' => 'Délais de traitement'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= e(url('controle.php', array_filter(['tab' => $k, 'area_id' => $areaId]))) ?>"><?= e($l) ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'risques'): ?>
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <p class="small text-muted mb-0">Le score (0-100) additionne des indicateurs simples et explicables. Il sert à <strong>prioriser</strong> les vérifications : un score élevé n'est pas une preuve de fraude.</p>
    <div class="btn-group btn-group-sm"><?php foreach (['eleve' => 'Élevé', 'moyen' => 'Moyen et +', 'tous' => 'Tous'] as $k => $l): ?>
        <a class="btn btn-outline-secondary<?= $minLevel === $k ? ' active' : '' ?>" href="<?= e(url('controle.php', array_filter(['tab' => 'risques', 'level' => $k, 'area_id' => $areaId]))) ?>"><?= e($l) ?></a><?php endforeach; ?></div>
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
    <thead><tr><th>Score</th><th>Parcelle</th><th>Zone</th><th>Statut</th><th>Indicateurs</th><th></th></tr></thead><tbody>
    <?php foreach ($riskRows as $pid => $r): $p = $info[$pid] ?? null; if (!$p) { continue; } ?>
        <tr><td><?= $levelBadge($r['level'], $r['score']) ?></td>
            <td><a class="ref" href="<?= e(url('parcelle_details.php', ['id' => $pid])) ?>"><?= e($p['parcel_reference']) ?></a></td>
            <td class="small"><?= e($p['area_name'] ?? '—') ?></td><td><?= status_badge($p['verification_status']) ?></td>
            <td class="small"><?php foreach ($r['reasons'] as $code): [$w, $lab, $exp] = RiskService::RULES[$code]; ?>
                <span class="badge rounded-pill text-bg-light border me-1" title="<?= e($exp) ?>"><?= e($lab) ?> <span class="text-muted">+<?= $w ?></span></span><?php endforeach; ?></td>
            <td><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('carte.php', ['parcel' => $pid])) ?>" title="Carte"><i class="bi bi-map"></i></a></td></tr>
    <?php endforeach; ?>
    <?php if (!$riskRows): ?><tr><td colspan="6" class="text-muted text-center py-4">Aucun dossier à ce niveau de risque.</td></tr><?php endif; ?>
    </tbody></table></div></div>
<details class="mt-3 small"><summary>Règles de calcul du score</summary><table class="table table-sm mt-2"><tbody>
    <?php foreach (RiskService::RULES as $code => [$w, $lab, $exp]): ?><tr><td class="text-nowrap">+<?= $w ?></td><td><strong><?= e($lab) ?></strong></td><td><?= e($exp) ?></td></tr><?php endforeach; ?>
    <tr><td></td><td colspan="2">Niveaux : élevé ≥ 60, moyen 30-59, faible &lt; 30 (score plafonné à 100).</td></tr></tbody></table></details>

<?php elseif ($tab === 'limites'): ?>
<p class="small text-muted">Sommets situés à moins de <?= e(fmt_number((float) setting('quality_tolerance_m', 0.60), 2)) ?> m de la limite d'une parcelle voisine sans être dessus :
    petit vide (<strong>écart</strong>) ou léger recouvrement (<strong>empiètement</strong>) le long d'une limite commune. Le recollage déplace ces sommets exactement sur la limite voisine
    et crée une nouvelle version (l'ancienne reste consultable). Tolérance modifiable dans Paramètres.</p>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
    <thead><tr><th>Parcelles</th><th>Défaut</th><th>Écart max.</th><th>Sommets</th><th>Correction</th></tr></thead><tbody>
    <?php foreach ($issues as $i): ?>
        <tr><td><a class="ref" href="<?= e(url('carte.php', ['parcel' => $i['a']])) ?>"><?= e($i['ref_a']) ?></a> <span class="small text-muted">(<?= e(label('verification_status', $i['status_a'])) ?>)</span><br>
                <a class="ref" href="<?= e(url('carte.php', ['parcel' => $i['b']])) ?>"><?= e($i['ref_b']) ?></a> <span class="small text-muted">(<?= e(label('verification_status', $i['status_b'])) ?>)</span></td>
            <td><?= $i['kind'] === 'ecart' ? '<span class="badge text-bg-info">Écart</span>' : '<span class="badge text-bg-warning">Empiètement</span>' ?></td>
            <td><?= fmt_number($i['max_m'] * 100, 0) ?> cm</td><td><?= (int) $i['count'] ?></td>
            <td><?php if (can('parcel.edit')): ?>
                <div class="d-flex flex-wrap gap-1">
                <?php foreach ([[$i['a'], $i['b'], $i['ref_a'], $i['ref_b'], count($i['vertices_a'])], [$i['b'], $i['a'], $i['ref_b'], $i['ref_a'], count($i['vertices_b'])]] as [$mv, $rf, $rmv, $rrf, $nv]): if (!$nv) { continue; } ?>
                    <form method="post" data-confirm="Recoller <?= e($rmv) ?> sur la limite de <?= e($rrf) ?> ? Une nouvelle version des limites sera créée.">
                        <?= csrf_field() ?><input type="hidden" name="action" value="snap"><input type="hidden" name="move_id" value="<?= $mv ?>"><input type="hidden" name="ref_id" value="<?= $rf ?>">
                        <button class="btn btn-sm btn-outline-primary">Aligner <?= e(substr($rmv, -6)) ?> sur <?= e(substr($rrf, -6)) ?></button></form>
                <?php endforeach; ?>
                <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="dismiss"><input type="hidden" name="a" value="<?= $i['a'] ?>"><input type="hidden" name="b" value="<?= $i['b'] ?>">
                    <input class="form-control form-control-sm" name="reason" placeholder="Motif si l'écart est réel" style="max-width:170px" required maxlength="255">
                    <button class="btn btn-sm btn-outline-secondary">Accepter</button></form></div>
            <?php else: ?><span class="small text-muted">Correction réservée aux administrateurs fonciers</span><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$issues): ?><tr><td colspan="5" class="text-muted text-center py-4">Aucun défaut de limite commune détecté.</td></tr><?php endif; ?>
    </tbody></table></div></div>

<?php else: ?>
<p class="small text-muted">Dossiers soumis ou en cours de vérification sans changement de statut depuis plus de <?= $overdueDays ?> jours (délai modifiable dans Paramètres).</p>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Parcelle</th><th>Zone</th><th>Statut</th><th>Depuis le</th><th>Attente</th><th>Agent</th></tr></thead><tbody>
    <?php foreach ($overdue as $o): $days = (int) floor((time() - strtotime($o['since'])) / 86400); ?>
        <tr><td><a class="ref" href="<?= e(url('parcelle_details.php', ['id' => $o['id']])) ?>"><?= e($o['parcel_reference']) ?></a></td><td><?= e($o['area_name'] ?? '—') ?></td>
            <td><?= status_badge($o['verification_status']) ?></td><td><?= e(fmt_date($o['since'])) ?></td>
            <td><span class="badge text-bg-<?= $days > 2 * $overdueDays ? 'danger' : 'warning' ?>"><?= $days ?> jours</span></td><td class="small"><?= e($o['agent'] ?? '—') ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$overdue): ?><tr><td colspan="6" class="text-muted text-center py-4">Aucun dossier en retard.</td></tr><?php endif; ?>
    </tbody></table></div></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
