<?php
$tabs = [['dashboard', '/pay', 'bi-speedometer2', 'Tableau de bord', 'pay.view'], ['providers', '/pay/prestataires', 'bi-building', 'Fournisseurs', 'pay.view'],
    ['api', '/pay/connecteurs', 'bi-plug', 'API', 'pay.configure'], ['terminals', '/pay/terminaux', 'bi-credit-card-2-front', 'Terminaux', 'pay.configure'],
    ['methods', '/pay/moyens', 'bi-wallet2', 'Moyens de paiement', 'pay.configure'], ['transactions', '/pay/transactions', 'bi-list-check', 'Transactions', 'pay.view'],
    ['refunds', '/pay/remboursements', 'bi-arrow-counterclockwise', 'Remboursements', 'pay.view'], ['reconciliation', '/pay/rapprochement', 'bi-check2-square', 'Rapprochement', 'pay.reconcile'],
    ['reports', '/pay/rapports', 'bi-file-earmark-bar-graph', 'Rapports', 'pay.reports'], ['logs', '/pay/journaux', 'bi-terminal', 'Journaux', 'pay.logs'],
    ['settings', '/pay/parametres', 'bi-sliders', 'Paramètres', 'pay.configure']];
?>
<ul class="nav nav-pills flex-wrap gap-1 mb-3 small d-print-none">
<?php foreach ($tabs as [$k, $u, $i, $l, $perm]): if (!can($perm)) continue; ?>
  <li class="nav-item"><a class="nav-link py-1 px-2 <?= ($tab ?? '') === $k ? 'active' : '' ?>" href="<?= e(url($u)) ?>"><i class="bi <?= $i ?>"></i> <?= e(t($l)) ?></a></li>
<?php endforeach; ?>
</ul>
