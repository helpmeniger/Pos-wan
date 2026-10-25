<?php $this->layout('layouts/install');
$field = function (string $name, string $label, string $default = '', string $type = 'text', string $extra = '', string $help = ''): string {
    $html = '<div class="mb-3"><label class="form-label" for="' . e($name) . '">' . e($label) . '</label>'
        . '<input class="' . e(field_class($name)) . '" id="' . e($name) . '" name="' . e($name) . '" type="' . e($type) . '" value="'
        . e($type === 'password' ? '' : old($name, $default)) . '" ' . $extra . '>';
    if (error_for($name)) {
        $html .= '<div class="invalid-feedback">' . e(error_for($name)) . '</div>';
    }
    if ($help !== '') {
        $html .= '<div class="form-text">' . $help . '</div>';
    }
    return $html . '</div>';
};
?>
<div class="container py-4" style="max-width:860px">
  <h1 class="h3">Bienvenue ! Installons JOB NIGER</h1>
  <p class="text-muted">Une seule étape : l'assistant crée la base de données, les tables, le catalogue des métiers,
    votre compte administrateur et le fichier de configuration.</p>

  <div class="jn-card p-3 mb-4">
    <h2 class="jn-section-title">1. Vérification de l'ordinateur</h2>
    <ul class="list-unstyled small mb-0">
      <?php foreach ($requirements as $r): ?>
        <li class="d-flex gap-2 py-1 border-bottom">
          <i class="bi <?= $r['ok'] ? 'bi-check-circle-fill text-primary' : ($r['required'] ? 'bi-x-circle-fill text-danger' : 'bi-exclamation-circle text-warning') ?>" aria-hidden="true"></i>
          <span class="flex-grow-1"><?= e($r['label']) ?><?= $r['required'] ? '' : ' <span class="text-muted">(recommandé)</span>' ?></span>
          <span class="text-muted text-end"><?= e($r['detail']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$ready): ?>
      <div class="alert alert-danger mt-3 mb-0">Corrigez les points en rouge, puis rechargez cette page.</div>
    <?php endif; ?>
  </div>

  <?php if ($ready): ?>
  <form method="post" action="<?= e(url('/installation')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="jn-card p-3 mb-4">
      <h2 class="jn-section-title">2. Base de données MySQL / MariaDB</h2>
      <p class="small text-muted">Valeurs par défaut de WampServer : serveur <code>127.0.0.1</code>, utilisateur <code>root</code>, mot de passe <strong>vide</strong>.
        La base est créée automatiquement si elle n'existe pas.</p>
      <div class="row">
        <div class="col-md-5"><?= $field('db_host', 'Serveur', '127.0.0.1', 'text', 'required maxlength="120"') ?></div>
        <div class="col-md-3"><?= $field('db_port', 'Port', '3306', 'number', 'required min="1" max="65535"', 'MySQL : 3306 · MariaDB (Wamp) : 3307') ?></div>
        <div class="col-md-4"><?= $field('db_name', 'Nom de la base', 'job_niger', 'text', 'required maxlength="64" pattern="[A-Za-z0-9_]+"') ?></div>
        <div class="col-md-6"><?= $field('db_user', 'Utilisateur', 'root', 'text', 'required maxlength="80" autocomplete="off"') ?></div>
        <div class="col-md-6"><?= $field('db_password', 'Mot de passe', '', 'password', 'maxlength="200" autocomplete="off"', 'Laissez vide si vous n\'avez pas défini de mot de passe MySQL.') ?></div>
      </div>
    </div>

    <div class="jn-card p-3 mb-4">
      <h2 class="jn-section-title">3. Compte administrateur</h2>
      <div class="row">
        <div class="col-md-6"><?= $field('admin_first_name', 'Prénom', '', 'text', 'required maxlength="80" autocomplete="given-name"') ?></div>
        <div class="col-md-6"><?= $field('admin_last_name', 'Nom', '', 'text', 'required maxlength="80" autocomplete="family-name"') ?></div>
        <div class="col-md-6"><?= $field('admin_phone', 'Téléphone (identifiant de connexion)', '', 'tel', 'required placeholder="90 12 34 56" autocomplete="tel"') ?></div>
        <div class="col-md-6"><?= $field('admin_email', 'E-mail (facultatif)', '', 'email', 'maxlength="190" autocomplete="email"') ?></div>
        <div class="col-md-6"><?= $this->partial('partials/password-field', ['name' => 'admin_password', 'label' => 'Mot de passe', 'autocomplete' => 'new-password', 'strength' => true]) ?></div>
        <div class="col-md-6"><?= $this->partial('partials/password-field', ['name' => 'admin_password_confirmation', 'label' => 'Confirmer le mot de passe', 'autocomplete' => 'new-password']) ?></div>
      </div>
    </div>

    <p class="small text-muted">Adresse détectée : <code><?= e($appUrl . $basePath) ?></code>. L'installation prend quelques secondes.</p>
    <button type="submit" class="btn btn-primary btn-lg w-100" data-loading="Installation en cours… (patientez)"><i class="bi bi-rocket-takeoff" aria-hidden="true"></i> Installer JOB NIGER</button>
  </form>
  <?php endif; ?>
</div>
