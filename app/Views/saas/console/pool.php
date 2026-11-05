<h1 class="mb-3">Bases de données des clients</h1>
<?php if ($mode === 'create'): ?>
  <div class="alert alert-info small">Mode <b>création automatique</b> (SAAS_PROVISION=create) : chaque inscription crée sa base et un utilisateur MySQL qui n'a de droits que sur elle. La réserve ci-dessous ne sert qu'en mode « pool ».</div>
<?php else: ?>
  <div class="alert alert-warning small">Mode <b>réserve</b> (SAAS_PROVISION=pool), pour un hébergement mutualisé comme o2switch où PHP ne peut pas créer de base. Préparez des bases vides dans cPanel → « Bases de données MySQL » : une base + un utilisateur <b>dédié</b> (tous les privilèges sur cette base seulement), puis déclarez-les ici. Chaque inscription prend la plus ancienne base libre ; sans base libre, l'inscription attend et apparaît dans le tableau de bord.</div>
<?php endif; ?>
<div class="panel mb-3"><h2>Ajouter une base vide</h2>
  <form method="post" action="<?= e(url('/console/bases')) ?>" class="row g-2" autocomplete="off"><?= csrf_field() ?>
    <div class="col-md-4"><input name="db_name" class="form-control form-control-sm" placeholder="Nom complet (ex. moncompte_pmt01)" required></div>
    <div class="col-md-3"><input name="db_user" class="form-control form-control-sm" placeholder="Utilisateur MySQL" required></div>
    <div class="col-md-3"><input name="db_pass" type="password" class="form-control form-control-sm" placeholder="Mot de passe" required autocomplete="new-password"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Vérifier et ajouter</button></div>
  </form>
  <p class="small text-muted mt-2 mb-0">La connexion est testée avec ces identifiants et la base doit être vide. Le mot de passe est conservé chiffré (clé SAAS_KEY du fichier .env).</p>
</div>
<div class="panel p-0"><table class="table table-sm align-middle mb-0">
  <thead class="table-light"><tr><th>Base</th><th>Utilisateur</th><th>Ajoutée</th><th>Client</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><code><?= e($r['db_name']) ?></code></td><td><?= e($r['db_user']) ?></td><td class="small"><?= e(date('d/m/Y', strtotime($r['added_at']))) ?></td><td><?= $r['company'] ? e($r['company']) . ' <small class="text-muted">(' . e($r['slug']) . ')</small>' : '<span class="st st-active">Libre</span>' ?></td></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="4" class="text-muted small p-3">Aucune base dans la réserve.</td></tr><?php endif; ?>
  </tbody></table></div>
