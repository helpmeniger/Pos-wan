<?php use App\Saas\Billing; use App\Saas\Subscription; ?>
<h1 class="mb-3">Tableau de bord</h1>
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-2"><div class="kpi"><small>Clients actifs</small><div class="v"><?= (int) $counts['active'] ?></div></div></div>
  <div class="col-6 col-lg-2"><div class="kpi"><small>En essai</small><div class="v"><?= (int) $counts['trial'] ?></div></div></div>
  <div class="col-6 col-lg-2"><div class="kpi"><small>Expirés</small><div class="v"><?= (int) $counts['expired'] ?></div></div></div>
  <div class="col-6 col-lg-2"><div class="kpi"><small>Suspendus</small><div class="v"><?= (int) $counts['suspended'] ?></div></div></div>
  <div class="col-6 col-lg-2"><div class="kpi"><small>Revenu mensuel récurrent</small><div class="v"><?= e(Billing::fcfa($mrr)) ?></div></div></div>
  <div class="col-6 col-lg-2"><div class="kpi"><small>Encaissé ce mois</small><div class="v"><?= e(Billing::fcfa($month)) ?></div></div></div>
</div>
<?php if ($pendingCount): ?><div class="alert alert-warning"><i class="bi bi-bell"></i> <b><?= (int) $pendingCount ?></b> paiement(s) déclaré(s) par des clients à vérifier. <a href="<?= e(url('/console/paiements')) ?>">Vérifier</a></div><?php endif; ?>
<?php if ($waitingCount): ?><div class="alert alert-info"><i class="bi bi-database-exclamation"></i> <b><?= (int) $waitingCount ?></b> inscription(s) en attente d'une base de données. <a href="<?= e(url('/console/bases')) ?>">Ajouter une base</a></div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-6"><div class="panel h-100">
    <h2>Échéances dans les 7 jours</h2>
    <?php if (!$expiring): ?><p class="text-muted small mb-0">Aucune.</p><?php endif; ?>
    <table class="table table-sm align-middle mb-0"><tbody>
    <?php foreach ($expiring as $t): ?>
      <tr><td><a href="<?= e(url('/console/clients/' . $t['id'])) ?>"><?= e($t['company']) ?></a><br><small class="text-muted"><?= e($t['phone']) ?></small></td><td><?= Subscription::badge($t['sub']['state']) ?></td><td class="text-end"><b><?= (int) $t['sub']['days_left'] ?> j</b><br><small class="text-muted"><?= e(date('d/m/Y', strtotime($t['sub']['until']))) ?></small></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div></div>
  <div class="col-lg-6"><div class="panel h-100">
    <h2>Dernières inscriptions</h2>
    <table class="table table-sm align-middle mb-0"><tbody>
    <?php foreach ($signups as $t): ?>
      <tr><td><a href="<?= e(url('/console/clients/' . $t['id'])) ?>"><?= e($t['company']) ?></a><br><small class="text-muted"><?= e($t['slug']) ?> · <?= e($t['plan_name']) ?></small></td><td><?= $t['state'] === 'ready' ? '' : Subscription::badge($t['state']) ?></td><td class="text-end small text-muted"><?= e(date('d/m/Y H:i', strtotime($t['created_at']))) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div></div>
  <div class="col-12"><div class="panel">
    <h2>Encaissements des 6 derniers mois</h2>
    <?php $max = max(1, ...array_map(fn($r) => (float) $r['total'], $monthly ?: [['total' => 1]])); ?>
    <?php if (!$monthly): ?><p class="text-muted small mb-0">Aucun paiement validé pour le moment.</p><?php endif; ?>
    <?php foreach ($monthly as $r): ?>
      <div class="d-flex align-items-center gap-2 mb-1 small"><span style="width:70px"><?= e($r['m']) ?></span><div class="flex-grow-1 bg-light rounded"><div class="rounded" style="height:14px;background:#6f42c1;width:<?= round((float) $r['total'] / $max * 100) ?>%"></div></div><span style="width:150px" class="text-end"><b><?= e(Billing::fcfa($r['total'])) ?></b> (<?= (int) $r['n'] ?>)</span></div>
    <?php endforeach; ?>
  </div></div>
</div>
