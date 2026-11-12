<?php /** Réglages de MARWA AI. */ ?>
<form method="post" action="<?= e(url('/ia/reglages')) ?>" class="card" style="max-width:860px" data-confirm="Enregistrer ? Les conversations plus anciennes que la durée de conservation choisie seront effacées définitivement."><?= csrf_field() ?>
  <div class="card-header">Réglages de MARWA AI</div>
  <div class="card-body">
    <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="bubble" value="1" id="iaB" <?= $bubble ? 'checked' : '' ?>><label class="form-check-label" for="iaB">Afficher la bulle « MARWA AI » en bas de toutes les pages (sauf l'écran de caisse)</label></div>
    <h6 class="mt-4">Mémoire des conversations</h6>
    <div class="row g-2 align-items-end"><div class="col-md-4"><label class="form-label">Durée de conservation (jours)</label><input type="number" min="1" max="3650" name="retention" class="form-control" value="<?= (int) $retention ?>"></div>
      <div class="col-md-8 small text-secondary">Chaque utilisateur ne voit que ses propres conversations (même un administrateur ne lit pas celles des autres) et peut les effacer à tout moment. Données personnelles : loi n° 2017-28 du 3 mai 2017 relative à la protection des données à caractère personnel (Niger).</div></div>
    <h6 class="mt-4">Modèle de langage</h6>
    <?php if ($models): ?>
      <p class="small">Modèle par défaut : <b><?= e($models[0]['name']) ?></b> (<?= e($models[0]['model']) ?>)<?= isset($models[1]) ? ' · secours : <b>' . e($models[1]['name']) . '</b>' : '' ?>.</p>
    <?php else: ?>
      <p class="small text-secondary">Aucun modèle de langage actif : MARWA AI répond avec son moteur intégré (compréhension, calculs et documentation, sans Internet).</p>
    <?php endif; ?>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="phrasing" value="1" id="iaP" <?= $phrasing ? 'checked' : '' ?>><label class="form-check-label" for="iaP">Laisser le modèle reformuler les réponses chiffrées (les chiffres restent ceux calculés par POS Marwa ; toute reformulation qui modifie ou ajoute un chiffre est rejetée)</label></div>
    <p class="small mt-3"><a href="<?= e(url('/ia/admin/modeles')) ?>"><i class="bi bi-cpu"></i> Gérer les modèles (Ollama, OpenAI, Claude, Gemini…)</a> · <a href="<?= e(url('/ia/admin')) ?>"><i class="bi bi-speedometer2"></i> Pilotage et apprentissage</a></p>
  </div>
  <div class="card-footer"><button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button> <a class="btn btn-outline-secondary" href="<?= e(url('/ia')) ?>">Ouvrir MARWA AI</a></div>
</form>
