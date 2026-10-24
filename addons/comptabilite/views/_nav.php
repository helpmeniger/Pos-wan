<?php
/** Barre de navigation du module (onglets) : passer d'une fonction comptable à l'autre sans revenir au menu. */
$cur = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
$base = rtrim(url('/'), '/');
$tabs = [
    ['/compta', 'Tableau de bord', 'bi-speedometer2', 'compta.view', true],
    ['/compta/saisie', 'Saisie', 'bi-pencil-square', 'compta.saisie', false],
    ['/compta/ecritures', 'Écritures', 'bi-list-columns-reverse', 'compta.view', false],
    ['/compta/lettrage', 'Consultation / lettrage', 'bi-check2-square', 'compta.view', false],
    ['/compta/etats', 'États', 'bi-file-earmark-bar-graph', 'compta.view', false],
    ['/compta/generation', 'Écritures de la caisse', 'bi-magic', 'compta.valider', false],
    ['/compta/comptes', 'Plan comptable', 'bi-diagram-3', 'compta.view', false],
    ['/compta/tiers', 'Tiers', 'bi-people', 'compta.view', false],
    ['/compta/journaux', 'Journaux', 'bi-journals', 'compta.admin', false],
    ['/compta/exercices', 'Exercices', 'bi-calendar-range', 'compta.admin', false],
    ['/compta/parametres', 'Paramètres', 'bi-sliders', 'compta.admin', false],
];
?>
<link rel="stylesheet" href="<?= e(asset('addons/comptabilite/compta.css')) ?>">
<nav class="cpt-tabs d-print-none" aria-label="Comptabilité">
  <?php foreach ($tabs as [$u, $l, $i, $p, $exact]): if (!can($p)) continue; $path = $base . $u; $on = $exact ? $cur === $path : str_starts_with((string) $cur, $path); ?>
    <a href="<?= e(url($u)) ?>" class="<?= $on ? 'active' : '' ?>"><i class="bi <?= e($i) ?>"></i> <?= e(t($l)) ?></a>
  <?php endforeach; ?>
</nav>
