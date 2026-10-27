<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Installation · POS Marwa</title>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>"><link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>"><link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>"></head>
<body class="login-body">
<div class="card shadow-lg" style="max-width:860px;width:100%">
  <div class="card-body p-4">
    <div class="d-flex align-items-center gap-3 mb-3"><img src="<?= e(asset('img/logo.svg')) ?>" width="52" alt=""><div><h1 class="h3 m-0">Installation de POS Marwa</h1><small class="text-secondary">Assistant de première configuration — désactivé automatiquement après installation.</small></div></div>
    <?php foreach ($flash as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div><?php endforeach; ?>
    <h2 class="h6 mt-3">1. Vérification de l'environnement</h2>
    <ul class="list-group mb-3 small">
      <?php foreach ($checks as [$label, $ok, $info]): ?>
        <li class="list-group-item d-flex justify-content-between"><span><?= e($label) ?> <small class="text-secondary"><?= e($info) ?></small></span><span class="<?= $ok ? 'text-success' : 'text-danger' ?>"><i class="bi <?= $ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>"></i></span></li>
      <?php endforeach; ?>
    </ul>
    <form method="post" action="<?= e(url('/install')) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <h2 class="h6">2. Base de données MySQL / MariaDB</h2>
      <p class="small text-secondary">Créez d'abord la base <b>vide</b> et un utilisateur dédié (cPanel → « Bases de données MySQL », ou phpMyAdmin), puis donnez à l'utilisateur <b>tous les privilèges</b> sur cette base. Sur o2switch, l'hôte est <code>localhost</code> et les noms complets commencent par votre identifiant cPanel (ex. <code>moncompte_posmarwa</code>). N'utilisez pas le compte <code>root</code> sans mot de passe.</p>
      <?php if ($hasEnv): ?><div class="alert alert-info small">Un fichier <code>.env</code> existe déjà. Laissez ces champs vides pour utiliser ses paramètres, ou remplissez-les pour les corriger (mot de passe vide : celui du <code>.env</code> est conservé). Le fichier n'est modifié qu'après une connexion réussie.</div><?php endif; ?>
      <div class="row g-2 mb-3">
        <div class="col-md-4"><label class="form-label">Hôte</label><input name="db_host" class="form-control" value="<?= e(App\Core\Session::old('db_host', 'localhost')) ?>" <?= $hasEnv ? '' : 'required' ?>></div>
        <div class="col-md-2"><label class="form-label">Port</label><input name="db_port" class="form-control" value="<?= e(App\Core\Session::old('db_port', '3306')) ?>"></div>
        <div class="col-md-6"><label class="form-label">Nom complet de la base</label><input name="db_name" class="form-control" value="<?= e(App\Core\Session::old('db_name')) ?>" placeholder="moncompte_posmarwa" <?= $hasEnv ? '' : 'required' ?>></div>
        <div class="col-md-6"><label class="form-label">Utilisateur complet</label><input name="db_user" class="form-control" value="<?= e(App\Core\Session::old('db_user')) ?>" placeholder="moncompte_posmarwa" <?= $hasEnv ? '' : 'required' ?>></div>
        <div class="col-md-6"><label class="form-label">Mot de passe</label><input type="password" name="db_pass" class="form-control" autocomplete="new-password"></div>
      </div>
      <h2 class="h6">3. Votre commerce</h2>
      <div class="row g-2 mb-3">
        <div class="col-md-6"><label class="form-label required">Nom commercial</label><input name="company" class="form-control" required maxlength="150" value="<?= e(App\Core\Session::old('company')) ?>"></div>
        <div class="col-md-6"><label class="form-label">Nom de la boutique principale</label><input name="store_name" class="form-control" maxlength="150" placeholder="ex. Boutique Plateau"></div>
        <div class="col-12"><label class="form-label required">Type d'activité</label>
          <div class="row g-2">
            <?php foreach ($types as $code => [$label]): ?>
              <div class="col-md-4"><label class="border rounded p-2 w-100 d-flex gap-2 align-items-center"><input type="radio" name="business_type" value="<?= e($code) ?>" <?= $code === 'alimentation' ? 'checked' : '' ?>> <?= e($label) ?></label></div>
            <?php endforeach; ?>
          </div>
          <div class="form-text">Active les modules adaptés (lots/péremption, variantes, pharmacie...). Modifiable ensuite dans les paramètres.</div>
        </div>
      </div>
      <h2 class="h6">4. Compte administrateur</h2>
      <div class="row g-2 mb-3">
        <div class="col-md-4"><label class="form-label required">Identifiant</label><input name="admin_username" class="form-control" required pattern="[A-Za-z0-9._-]{3,50}"></div>
        <div class="col-md-8"><label class="form-label">Nom complet</label><input name="admin_name" class="form-control" maxlength="150"></div>
        <div class="col-md-6"><label class="form-label required">Mot de passe</label><input type="password" name="admin_password" class="form-control" required minlength="10" autocomplete="new-password"></div>
        <div class="col-md-6"><label class="form-label required">Confirmation</label><input type="password" name="admin_password_confirm" class="form-control" required autocomplete="new-password"></div>
        <div class="col-12 form-text">Au moins 10 caractères, lettres et chiffres, sans l'identifiant. Aucun mot de passe par défaut n'existe.</div>
      </div>
      <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="demo" value="1" id="demo"><label class="form-check-label" for="demo">Charger des données de démonstration (articles « [DÉMO] », à archiver avant l'exploitation réelle)</label></div>
      <details class="mb-2 small"><summary class="fw-semibold">Contrat de licence d'utilisation (à lire)</summary>
        <div class="border rounded p-2 mt-2" style="max-height:260px;overflow:auto">
          <?php foreach (App\Services\Contract::clauses() as [$ct, $cp]): ?><div class="fw-semibold mt-2"><?= e($ct) ?></div><?php foreach ($cp as $p): ?><p class="mb-1"><?= e($p) ?></p><?php endforeach; ?><?php endforeach; ?>
        </div></details>
      <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="accept_contract" value="1" id="acceptContract" required><label class="form-check-label" for="acceptContract">J'ai lu et j'accepte le contrat de licence d'utilisation de POS Marwa (© <?= e(config('licence.author.name')) ?>).</label></div>
      <button class="btn btn-warning btn-lg w-100"><i class="bi bi-rocket-takeoff"></i> Installer POS Marwa</button>
    </form>
  </div>
</div>
</body></html>
