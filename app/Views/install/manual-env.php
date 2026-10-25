<?php $this->layout('layouts/install'); ?>
<div class="container py-4" style="max-width:860px">
  <h1 class="h3">Dernière étape manuelle</h1>
  <div class="alert alert-warning">La base de données et votre compte administrateur sont prêts, mais le serveur web n'a pas le droit
    d'écrire le fichier <code>.env</code> dans le dossier du projet.</div>
  <ol>
    <li>Créez un fichier nommé <code>.env</code> à la racine du projet (à côté de <code>composer.json</code>).</li>
    <li>Copiez-y exactement le contenu ci-dessous, puis enregistrez.</li>
    <li>Rechargez le site et connectez-vous avec votre compte administrateur.</li>
  </ol>
  <textarea class="form-control font-monospace small" rows="18" readonly><?= e($env) ?></textarea>
  <p class="small text-danger mt-2">Ce contenu comprend le mot de passe MySQL et la clé secrète de l'application : ne le partagez pas.</p>
</div>
