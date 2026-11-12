<?php
$tabs = [['dashboard', '/fiscal', 'bi-speedometer2', 'Tableau de bord', 'fiscal.view'], ['mcf', '/fiscal/connecteurs?type=mcf', 'bi-cpu', 'MCF', 'fiscal.view'],
    ['api', '/fiscal/connecteurs?type=api', 'bi-plug', 'API', 'fiscal.view'], ['countries', '/fiscal/pays', 'bi-globe2', 'Pays', 'fiscal.view'],
    ['profiles', '/fiscal/parametres', 'bi-sliders', 'Paramètres', 'fiscal.configure'], ['certs', '/fiscal/certificats', 'bi-file-earmark-lock', 'Certificats', 'fiscal.configure'],
    ['history', '/fiscal/historique', 'bi-clock-history', 'Historique', 'fiscal.view'], ['pending', '/fiscal/attente', 'bi-hourglass-split', 'En attente', 'fiscal.view'],
    ['reports', '/fiscal/rapports', 'bi-file-earmark-bar-graph', 'Rapports', 'fiscal.reports'], ['logs', '/fiscal/journaux', 'bi-terminal', 'Journaux', 'fiscal.logs'],
    ['docs', '/fiscal/documentation', 'bi-book', 'Documentation', 'fiscal.view']];
?>
<ul class="nav nav-pills flex-wrap gap-1 mb-3 small d-print-none">
<?php foreach ($tabs as [$k, $u, $i, $l, $perm]): if (!can($perm)) continue; ?>
  <li class="nav-item"><a class="nav-link py-1 px-2 <?= ($tab ?? '') === $k ? 'active' : '' ?>" href="<?= e(url($u)) ?>"><i class="bi <?= $i ?>"></i> <?= e(t($l)) ?></a></li>
<?php endforeach; ?>
</ul>
