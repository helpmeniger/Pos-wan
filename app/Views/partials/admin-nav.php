<?php
$items = [
  ['/admin', 'speedometer2', 'Tableau de bord', 'admin.dashboard.view'],
  ['/admin/utilisateurs', 'people', 'Utilisateurs', 'users.view'],
  ['/admin/verifications', 'patch-check', 'Vérifications', 'users.verify'],
  ['/admin/offres', 'megaphone', 'Offres', 'jobs.moderate'],
  ['/admin/candidatures', 'person-lines-fill', 'Candidatures', 'missions.view_all'],
  ['/admin/emplois', 'briefcase', 'Emplois', 'missions.view_all'],
  ['/admin/finances', 'cash-coin', 'Finances', 'finance.dashboard.view'],
  ['/admin/support', 'life-preserver', 'Support', 'support.manage'],
  ['/admin/journaux', 'journal-text', 'Journaux', 'audit.view'],
  ['/admin/parametres', 'gear', 'Paramètres', 'settings.manage'],
];
$current = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base = rtrim((string) config('app.base_path', ''), '/');
?>
<div class="jn-admin-nav border-bottom bg-white mb-3">
  <div class="container"><nav class="nav nav-underline flex-nowrap overflow-auto small" aria-label="Administration">
    <?php foreach ($items as [$href, $icon, $label, $perm]): if (!can($perm)) continue;
      $active = $href === '/admin' ? $current === $base . '/admin' : str_starts_with($current, $base . $href); ?>
      <a class="nav-link text-nowrap<?= $active ? ' active' : '' ?>" href="<?= e(url($href)) ?>"><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i> <?= e($label) ?></a>
    <?php endforeach; ?>
  </nav></div>
</div>
