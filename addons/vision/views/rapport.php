<?php
use Addons\Vision\SurveillanceService as SS;
$t = $cmp['totals'];
$rows = $cmp['rows'];
$lvl = ['alerte' => ['danger', 'À vérifier en priorité'], 'verifier' => ['warning text-dark', 'À vérifier'], 'ok' => ['success', 'Conforme']];
// Analyse rédigée automatiquement à partir des chiffres
$notes = [];
if ($t['events'] === 0) {
    $notes[] = ['secondary', 'Aucun passage enregistré sur la période. Vérifiez que l\'écran Surveillance était ouvert et la caméra démarrée.'];
} else {
    if ($t['coverage'] !== null && $t['coverage'] < 60) {
        $notes[] = ['warning', "Les caméras n'ont vu que {$t['coverage']} % des articles vendus : la ligne ne couvre pas tout le passage, ou des articles ne sont pas reconnus. Les écarts ci-dessous sont donc peu fiables : déplacez la ligne / la caméra ou apprenez davantage de photos."];
    } elseif ($t['coverage'] !== null) {
        $notes[] = ['success', "Les caméras ont vu {$t['coverage']} % des articles vendus : la comparaison est représentative."];
    }
    if ($t['unidentified'] > 0) {
        $pct = round(100 * $t['unidentified'] / max(1, $t['events']));
        $notes[] = [$pct > 40 ? 'warning' : 'info', "{$t['unidentified']} passage(s) non identifié(s) ({$pct} %) : revoyez leurs vignettes ci-dessous ; chaque correction apprend l'article."];
    }
    $prio = array_values(array_filter($rows, fn($r) => $r['unmatched_sure'] > 0));
    foreach (array_slice($prio, 0, 3) as $r) {
        $notes[] = ['danger', "« {$r['name']} » : {$r['unmatched_sure']} passage(s) identifié(s) avec certitude sans vente correspondante (± {$cmp['window']} min). À vérifier sur les vignettes et, si besoin, par un comptage du rayon."];
    }
    $max = max(array_map(fn($x) => $x['unmatched'], $cmp['by_hour']));
    if ($max > 0) {
        $hh = array_keys(array_filter($cmp['by_hour'], fn($x) => $x['unmatched'] === $max));
        $notes[] = ['info', 'Heure(s) avec le plus de passages sans vente : ' . implode(', ', array_map(fn($x) => sprintf('%02dh–%02dh', $x, $x + 1), $hh)) . " ({$max})."];
    }
    $other = array_filter($rows, fn($r) => $r['stock_out'] - $r['sold'] > 0.0001);
    if ($other) {
        $notes[] = ['secondary', count($other) . ' article(s) ont aussi des sorties de stock autres que des ventes (pertes, transferts, ajustements) : voir la colonne « Sorties de stock ».'];
    }
    if (!$prio && $t['unmatched'] === 0 && $t['identified'] > 0) {
        $notes[] = ['success', 'Tous les passages identifiés correspondent à une vente.'];
    }
}
?>
<form method="get" class="card card-body mb-3 py-2"><div class="row g-2 align-items-end small">
  <div class="col-6 col-md-2"><label class="form-label mb-0">Du</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
  <div class="col-6 col-md-2"><label class="form-label mb-0">Au</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
  <div class="col-6 col-md-2"><label class="form-label mb-0">Fenêtre (± min)</label><input type="number" name="window" min="1" max="240" value="<?= (int) $cmp['window'] ?>" class="form-control form-control-sm"></div>
  <div class="col-12 col-md"><label class="form-label mb-0">Caméras</label><div><?php foreach ($cameras as $c): ?><label class="me-2"><input type="checkbox" name="camera[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $cams, true) ? 'checked' : '' ?>> <?= e($c['name']) ?></label><?php endforeach; ?><?= $cameras ? '' : '<span class="text-secondary">aucune</span>' ?></div></div>
  <div class="col-auto"><label class="small"><input type="checkbox" name="all" value="1" <?= $all ? 'checked' : '' ?>> tous les articles du magasin</label></div>
  <div class="col-auto"><input type="hidden" name="show" value="<?= e($show) ?>"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Comparer</button>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(query_with(['export' => 'csv'])) ?>">CSV</a> <a class="btn btn-sm btn-outline-secondary" href="<?= e(query_with(['export' => 'pdf'])) ?>">PDF</a></div>
</div></form>

<div class="row g-2 mb-3">
  <?php foreach ([['Passages vus', $t['events'], '', 'Sorties franchies + articles vus dans les zones'], ['Identifiés', $t['identified'], 'dont ' . (int) $t['sure'] . ' sûrs', ''], ['Non identifiés', $t['unidentified'], '', ''], ['Unités vendues', qty($t['sold']), '', 'Ventes de la période'], ['Sorties de stock', qty($t['stock_out']), '', 'Ventes + pertes + transferts + ajustements'], ['Justifiés par une vente', $t['matched'], '', ''], ['Sans vente', $t['unmatched'], 'dont ' . (int) $t['unmatched_sure'] . ' sûrs', ''], ['Couverture des caméras', $t['coverage'] === null ? '—' : $t['coverage'] . ' %', '', 'Part des articles vendus vus par une caméra']] as [$l, $v, $sub, $tip]): ?>
    <div class="col-6 col-md-3 col-xl"><div class="card h-100" title="<?= e($tip) ?>"><div class="card-body py-2"><div class="small text-secondary"><?= e($l) ?></div><div class="fs-5 fw-bold <?= $l === 'Sans vente' && $t['unmatched_sure'] ? 'text-danger' : '' ?>"><?= e((string) $v) ?></div><?php if ($sub): ?><div class="small text-secondary"><?= e($sub) ?></div><?php endif; ?></div></div></div>
  <?php endforeach; ?>
</div>

<div class="card mb-3"><div class="card-header"><i class="bi bi-lightbulb"></i> Analyse</div><div class="card-body small">
  <?php foreach ($notes as [$cls, $txt]): ?><div class="alert alert-<?= e($cls) ?> py-1 px-2 mb-1"><?= e($txt) ?></div><?php endforeach; ?>
  <div class="text-secondary mt-2"><?= $cmp['learned_only'] ? 'Comparaison limitée aux articles appris par la reconnaissance ou vus par une caméra (les autres ne peuvent pas être identifiés). ' : '' ?>Un passage est « justifié » quand une vente du même article a lieu à ± <?= (int) $cmp['window'] ?> minutes. Ces indications ne sont pas des preuves : la détection et la reconnaissance automatiques peuvent se tromper. Vérifiez les vignettes avant toute conclusion.</div>
</div></div>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card"><div class="card-header"><i class="bi bi-table"></i> Comparaison par article</div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Article</th><th class="text-center" title="Passages identifiés (sûrs / incertains)">Vus</th><th class="text-center">Retours</th><th class="text-center">Vendus</th><th class="text-center" title="Toutes sorties de stock">Sorties stock</th><th class="text-center">Justifiés</th><th class="text-center">Sans vente</th><th class="text-center">Écart vus − vendus</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><b><?= e($r['name']) ?></b><br><span class="small text-secondary"><?= e($r['reference']) ?></span></td>
            <td class="text-center"><?= (int) $r['seen'] ?><br><span class="small text-secondary"><?= (int) $r['seen_sure'] ?> sûrs</span></td>
            <td class="text-center"><?= (int) $r['returns'] ?></td>
            <td class="text-center"><?= qty($r['sold']) ?></td>
            <td class="text-center" title="<?= e(implode(', ', array_map(fn($k, $v) => $k . ' : ' . qty($v), array_keys($r['out_types']), $r['out_types']))) ?>"><?= qty($r['stock_out']) ?></td>
            <td class="text-center"><?= (int) $r['matched'] ?></td>
            <td class="text-center <?= $r['unmatched_sure'] ? 'text-danger fw-bold' : '' ?>"><?= (int) $r['unmatched'] ?><?= $r['unmatched_sure'] ? '<br><span class="small">' . (int) $r['unmatched_sure'] . ' sûrs</span>' : '' ?></td>
            <td class="text-center"><?= ($r['gap'] > 0 ? '+' : '') . qty($r['gap']) ?></td>
            <td><span class="badge bg-<?= e($lvl[$r['level']][0]) ?>"><?= e($lvl[$r['level']][1]) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-secondary py-4">Aucune donnée sur la période.</td></tr><?php endif; ?>
        </tbody></table></div></div>
  </div>
  <div class="col-xl-4">
    <div class="card"><div class="card-header"><i class="bi bi-clock"></i> Par heure</div><div class="card-body small">
      <?php $maxH = max(1, ...array_map(fn($h) => max($h['seen'], $h['sold']), $cmp['by_hour'])); foreach ($cmp['by_hour'] as $h => $x): if (!$x['seen'] && !$x['sold']) { continue; } ?>
        <div class="d-flex align-items-center gap-2 mb-1"><span style="width:48px"><?= sprintf('%02dh', $h) ?></span>
          <div class="flex-grow-1"><div class="rep-bar" title="Passages vus : <?= (int) $x['seen'] ?>"><span style="width:<?= round(100 * $x['seen'] / $maxH) ?>%;background:#7b2cbf"></span></div><div class="rep-bar mt-1" title="Unités vendues : <?= qty($x['sold']) ?>"><span style="width:<?= round(100 * $x['sold'] / $maxH) ?>%;background:#2ea043"></span></div></div>
          <span style="width:70px" class="text-end"><?= (int) $x['seen'] ?> / <?= qty($x['sold']) ?><?= $x['unmatched'] ? ' <b class="text-danger">' . (int) $x['unmatched'] . '</b>' : '' ?></span></div>
      <?php endforeach; ?>
      <div class="text-secondary mt-2"><span style="color:#7b2cbf">■</span> passages vus · <span style="color:#2ea043">■</span> unités vendues · <b class="text-danger">n</b> sans vente</div>
    </div></div>
  </div>
</div>

<div class="card mt-3" id="revue"><div class="card-header d-flex flex-wrap gap-2 align-items-center"><i class="bi bi-images"></i> Passages à revoir
  <div class="btn-group btn-group-sm ms-auto"><?php foreach (['unmatched' => 'Sans vente', 'unknown' => 'Non identifiés', 'all' => 'Tous'] as $k => $l): ?><a class="btn <?= $show === $k ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(query_with(['show' => $k])) ?>#revue"><?= e($l) ?></a><?php endforeach; ?></div></div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="revTable">
    <thead><tr><th></th><th>Heure</th><th>Caméra</th><th>Passage</th><th>Article proposé</th><th>État</th><th class="text-end">Revue</th></tr></thead><tbody>
    <?php foreach ($events as $ev): ?>
      <tr data-ev="<?= (int) $ev['id'] ?>">
        <td><?php if ($ev['thumb']): ?><img class="rep-thumb" src="<?= e($ev['thumb']) ?>" alt=""><?php endif; ?></td>
        <td class="small"><?= e(fdate($ev['seen_at'])) ?></td><td class="small"><?= e($ev['camera']) ?></td>
        <td class="small"><?= e(SS::DIRECTIONS[$ev['direction']]) ?></td>
        <td><?= $ev['product'] ? e($ev['product']) : '<span class="text-secondary">—</span>' ?><?= $ev['score'] !== null ? '<br><span class="small text-secondary">ressemblance ' . e((string) round((float) $ev['score'], 2)) . '</span>' : '' ?></td>
        <td><span class="badge bg-light text-dark border" data-status><?= e(SS::STATUSES[$ev['status']]) ?></span></td>
        <td class="text-end text-nowrap">
          <?php if ($ev['product_id']): ?><button type="button" class="btn btn-sm btn-outline-success" data-act="confirm" title="L'article proposé est le bon">✔</button><?php endif; ?>
          <button type="button" class="btn btn-sm btn-outline-primary" data-act="correct" title="Choisir le bon article (et l'apprendre)">Corriger</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-act="ignore" title="Ce n'est pas un article (main, personne, reflet…)">Ignorer</button>
        </td></tr>
    <?php endforeach; ?>
    <?php if (!$events): ?><tr><td colspan="7" class="text-center text-secondary py-3">Aucun passage dans cette liste.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
