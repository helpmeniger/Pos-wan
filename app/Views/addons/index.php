<?php
$badges = ['active' => ['success', 'Actif'], 'inactive' => ['secondary', 'Désactivé'], 'available' => ['primary', 'Non installé'], 'upgrade' => ['warning text-dark', 'Mise à jour disponible'], 'error' => ['danger', 'Erreur'], 'missing' => ['danger', 'Dossier absent']];
?>
<div class="alert alert-light border small">
  <b><i class="bi bi-puzzle"></i> Ajouter un module :</b> copiez son dossier dans <code><?= e(str_replace('\\', '/', $dir)) ?>/</code>
  (ex. <code>addons/livraisons/</code> contenant <code>manifest.json</code>), puis actualisez cette page et cliquez sur <b>Installer</b>.
  Une sauvegarde est faite automatiquement avant l'installation. Désactiver un module masque ses pages sans effacer ses données.
  <span class="text-danger">N'installez que des modules de confiance : un module a les mêmes droits que POS Wan.</span>
  Créer un module : voir <code>docs/MODULES.md</code>.
</div>
<div class="d-flex mb-3"><a href="<?= e(url('/addons')) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> Rechercher de nouveaux modules</a></div>
<div class="row g-3">
<?php foreach ($mods as $code => $m): $st = App\Core\Addons::status($code); [$bc, $bl] = $badges[$st]; $row = $installed[$code] ?? null; ?>
  <div class="col-md-6 col-xl-4">
    <div class="card h-100 addon-card"><div class="card-body d-flex flex-column">
      <div class="d-flex align-items-start gap-2 mb-2">
        <span class="addon-icon" style="background:<?= e(preg_match('/^#[0-9a-f]{3,6}$/i', (string) ($m['color'] ?? '')) ? $m['color'] : '#6c3483') ?>"><i class="bi <?= e(preg_match('/^bi-[a-z0-9-]+$/', (string) ($m['icon'] ?? '')) ? $m['icon'] : 'bi-puzzle') ?>"></i></span>
        <div class="flex-grow-1"><div class="fw-semibold"><?= e($m['name'] ?? $code) ?></div><div class="small text-secondary"><?= e($code) ?> · v<?= e($m['version'] ?? '?') ?><?= $row && $st === 'upgrade' ? ' (installée : ' . e($row['version']) . ')' : '' ?><?= !empty($m['author']) ? ' · ' . e($m['author']) : '' ?></div></div>
        <span class="badge bg-<?= e($bc) ?>"><?= e($bl) ?></span>
      </div>
      <p class="small flex-grow-1 mb-2"><?= e($m['description'] ?? '') ?></p>
      <?php foreach ($m['errors'] as $err): ?><div class="small text-danger"><i class="bi bi-exclamation-triangle"></i> <?= e($err) ?></div><?php endforeach; ?>
      <?php if (!empty($m['permissions']) && !$m['errors']): ?><div class="small text-secondary mb-2"><i class="bi bi-shield-lock"></i> <?= e(implode(', ', array_keys((array) $m['permissions']))) ?></div><?php endif; ?>
      <form method="post" action="<?= e(url('/addons')) ?>" class="d-flex gap-2 mt-auto" <?= in_array($st, ['available', 'upgrade'], true) ? 'data-confirm="' . e(($st === 'upgrade' ? 'Mettre à jour' : 'Installer') . ' le module « ' . ($m['name'] ?? $code) . ' » ? Une sauvegarde sera faite avant.') . '"' : '' ?>>
        <?= csrf_field() ?><input type="hidden" name="code" value="<?= e($code) ?>">
        <?php if ($st === 'available'): ?><button name="do" value="install" class="btn btn-sm btn-primary"><i class="bi bi-download"></i> Installer</button><?php endif; ?>
        <?php if ($st === 'upgrade'): ?><button name="do" value="upgrade" class="btn btn-sm btn-warning"><i class="bi bi-arrow-up-circle"></i> Mettre à jour</button><?php endif; ?>
        <?php if ($st === 'active'): ?><button name="do" value="disable" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pause-circle"></i> Désactiver</button><?php endif; ?>
        <?php if ($st === 'inactive'): ?><button name="do" value="enable" class="btn btn-sm btn-success"><i class="bi bi-play-circle"></i> Activer</button><?php endif; ?>
        <?php if ($st === 'active' && !empty($m['menu'][0]['url'])): ?><a class="btn btn-sm btn-outline-primary ms-auto" href="<?= e(url((string) $m['menu'][0]['url'])) ?>">Ouvrir</a><?php endif; ?>
      </form>
    </div></div>
  </div>
<?php endforeach; ?>
<?php if (!$mods): ?><div class="col-12"><div class="text-center text-secondary py-5"><i class="bi bi-puzzle display-5 d-block mb-2"></i>Aucun module dans le dossier <code>addons/</code>.</div></div><?php endif; ?>
</div>
