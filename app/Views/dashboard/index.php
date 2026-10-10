<?php
$fin = can('reports.financial');
$delta = function (float $a, float $b): string {
    if ($b <= 0) return '';
    $p = ($a - $b) / $b * 100;
    return '<span class="small ' . ($p >= 0 ? 'text-white' : 'text-warning') . '"><i class="bi bi-arrow-' . ($p >= 0 ? 'up' : 'down') . '-right"></i> ' . number_format(abs($p), 0) . ' % vs hier</span>';
};
$chart = [
    'daily' => array_map(fn($d) => ['d' => date('d/m', strtotime($d['d'])), 't' => round($d['total']), 'm' => round($d['margin'])], $daily),
    'categories' => array_map(fn($c) => ['l' => $c['label'], 'v' => round((float) $c['revenue']), 'c' => $c['color']], $categories),
    'methods' => array_map(fn($m) => ['l' => method_label($m['method']), 'v' => round((float) $m['total'])], $methods),
    'showMargin' => $fin,
];
?>
<form class="d-flex flex-wrap gap-2 align-items-center mb-3" method="get">
  <span class="text-secondary"><i class="bi bi-calendar3"></i> <?= e(date('d/m/Y')) ?></span>
  <?php if (can('sales.view_all') && count($stores) > 1): ?>
    <select name="store" class="form-select form-select-sm w-auto ms-auto" data-autosubmit>
      <option value="">Toutes les boutiques</option>
      <?php foreach ($stores as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $storeId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
    </select>
  <?php endif; ?>
</form>
<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3"><div class="kpi bg-g1"><i class="bi bi-cash-coin kpi-icon"></i><div class="kpi-label">Ventes du jour (net)</div><div class="kpi-value"><?= money($today['net']) ?></div><?= $delta($today['net'], $yesterday['net']) ?></div></div>
  <div class="col-6 col-xl-3"><div class="kpi bg-g2"><i class="bi bi-receipt kpi-icon"></i><div class="kpi-label">Tickets du jour</div><div class="kpi-value"><?= (int) $today['tickets'] ?></div><span class="small">Panier moyen : <?= money($today['avg_basket']) ?></span></div></div>
  <?php if ($fin): ?>
  <div class="col-6 col-xl-3"><div class="kpi bg-g3"><i class="bi bi-graph-up-arrow kpi-icon"></i><div class="kpi-label">Marge brute du jour</div><div class="kpi-value"><?= money($today['margin']) ?></div><span class="small">Taux : <?= number_format($today['margin_rate'], 1, ',', ' ') ?> %</span></div></div>
  <div class="col-6 col-xl-3"><div class="kpi bg-g5"><i class="bi bi-wallet2 kpi-icon"></i><div class="kpi-label">Dépenses du mois</div><div class="kpi-value"><?= money($month['expenses']) ?></div><span class="small">Bénéfice estimé du mois : <?= money($month['profit']) ?></span></div></div>
  <div class="col-6 col-xl-3"><div class="kpi bg-g4"><i class="bi bi-people kpi-icon"></i><div class="kpi-label">Créances clients</div><div class="kpi-value"><?= money($receivables) ?></div></div></div>
  <div class="col-6 col-xl-3"><div class="kpi bg-g8"><i class="bi bi-building kpi-icon"></i><div class="kpi-label">Dettes fournisseurs</div><div class="kpi-value"><?= money($payables) ?></div></div></div>
  <div class="col-6 col-xl-3"><div class="kpi bg-g6"><i class="bi bi-safe2 kpi-icon"></i><div class="kpi-label">Espèces en caisses ouvertes</div><div class="kpi-value"><?= money($cash) ?></div><span class="small">Trésorerie de caisse (théorique)</span></div></div>
  <?php if ($stockValue !== null): ?><div class="col-6 col-xl-3"><div class="kpi bg-g7"><i class="bi bi-boxes kpi-icon"></i><div class="kpi-label">Valeur du stock (CMP)</div><div class="kpi-value"><?= money($stockValue) ?></div></div></div><?php endif; ?>
  <?php endif; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-8"><div class="card h-100"><div class="card-header d-flex"><span><i class="bi bi-bar-chart"></i> Chiffre d'affaires — 30 derniers jours</span><span class="ms-auto small text-secondary">Mois : <?= money($month['net']) ?> net</span></div><div class="card-body"><canvas id="chDaily" height="110"></canvas></div></div></div>
  <div class="col-xl-4"><div class="card h-100"><div class="card-header"><i class="bi bi-pie-chart"></i> Ventes par catégorie (mois)</div><div class="card-body"><canvas id="chCat" height="200"></canvas></div></div></div>
</div>

<div class="row g-3">
  <div class="col-xl-4"><div class="card h-100"><div class="card-header"><i class="bi bi-trophy"></i> Meilleures ventes du mois</div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Article</th><th class="num">Qté</th><th class="num">CA</th></tr></thead><tbody>
    <?php foreach ($top as $t): ?><tr><td><?= e($t['name']) ?></td><td class="num"><?= qty($t['qty']) ?></td><td class="num"><?= money($t['revenue']) ?></td></tr><?php endforeach; ?>
    <?php if (!$top): ?><tr><td colspan="3" class="text-secondary text-center py-3">Aucune vente ce mois-ci.</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
  <div class="col-xl-4"><div class="card h-100"><div class="card-header d-flex"><span><i class="bi bi-exclamation-triangle text-warning"></i> Stock bas / ruptures</span><a class="ms-auto small" href="<?= e(url('/stock', ['filter' => 'low'])) ?>">Tout voir</a></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Article</th><th class="num">Stock</th><th class="num">Seuil</th></tr></thead><tbody>
    <?php foreach ($low as $l): ?><tr><td><?= e($l['name']) ?></td><td class="num <?= (float) $l['qty'] <= 0 ? 'text-danger fw-bold' : 'text-warning' ?>"><?= qty($l['qty']) ?></td><td class="num"><?= qty($l['min_stock']) ?></td></tr><?php endforeach; ?>
    <?php if (!$low): ?><tr><td colspan="3" class="text-secondary text-center py-3">Aucun article sous le seuil.</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
  <div class="col-xl-4">
    <?php if (feature('lots')): ?>
    <div class="card mb-3"><div class="card-header d-flex"><span><i class="bi bi-calendar-x text-danger"></i> Péremptions proches</span><a class="ms-auto small" href="<?= e(url('/stock/expiry')) ?>">Tout voir</a></div>
      <div class="table-responsive"><table class="table table-sm mb-0"><tbody>
      <?php foreach ($expiring as $x): ?><tr><td><?= e($x['name']) ?><div class="small text-secondary">Lot <?= e($x['lot_number']) ?> · <?= qty($x['quantity']) ?></div></td><td class="num"><span class="badge <?= (int) $x['days_left'] < 0 ? 'bg-danger' : ((int) $x['days_left'] <= 30 ? 'bg-warning text-dark' : 'bg-secondary') ?>"><?= (int) $x['days_left'] < 0 ? 'Périmé' : (int) $x['days_left'] . ' j' ?></span></td></tr><?php endforeach; ?>
      <?php if (!$expiring): ?><tr><td class="text-secondary text-center py-3">Aucun lot proche de la péremption.</td></tr><?php endif; ?>
      </tbody></table></div></div>
    <?php endif; ?>
    <div class="card"><div class="card-header"><i class="bi bi-credit-card"></i> Encaissements du mois par mode</div><div class="card-body"><canvas id="chMethods" height="150"></canvas></div></div>
  </div>
  <div class="col-xl-6"><div class="card"><div class="card-header"><i class="bi bi-person-badge"></i> Performance par vendeur (mois)</div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Vendeur</th><th class="num">Tickets</th><th class="num">Remises</th><th class="num">Total</th></tr></thead><tbody>
    <?php foreach ($users as $x): ?><tr><td><?= e($x['label']) ?></td><td class="num"><?= (int) $x['n'] ?></td><td class="num"><?= money($x['discounts']) ?></td><td class="num"><?= money($x['total']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
  <div class="col-xl-6"><div class="card"><div class="card-header"><i class="bi bi-clock-history"></i> Dernières ventes</div>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>N°</th><th>Heure</th><th>Vendeur</th><th>Client</th><th class="num">Total</th></tr></thead><tbody>
    <?php foreach ($recent as $r): ?><tr><td><a href="<?= e(url('/sales/' . $r['id'])) ?>"><?= e($r['number']) ?></a> <?= $r['status'] === 'cancelled' ? '<span class="badge bg-danger">annulée</span>' : '' ?></td><td><?= e(fdate($r['created_at'])) ?></td><td><?= e($r['cashier']) ?></td><td><?= e($r['customer'] ?? '—') ?></td><td class="num"><?= money($r['total']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
</div>
<script type="application/json" id="dash-data"><?= json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
