<?php /** Réglages de MARWA IA. */ ?>
<form method="post" action="<?= e(url('/ia/reglages')) ?>" class="card" style="max-width:820px"><?= csrf_field() ?>
  <div class="card-header">Réglages de MARWA IA</div>
  <div class="card-body">
    <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="bubble" value="1" id="iaB" <?= $bubble ? 'checked' : '' ?>><label class="form-check-label" for="iaB">Afficher la bulle « MARWA IA » en bas de toutes les pages (sauf l'écran de caisse)</label></div>
    <h6 class="mt-4">Modèle de langage local (facultatif)</h6>
    <p class="small text-secondary">MARWA IA fonctionne sans ce réglage : son moteur intégré comprend les demandes de rapports et cherche les réponses dans la documentation. Si un modèle <b>Ollama</b> est installé sur ce PC ou le réseau local, il peut rédiger les réponses d'aide à partir de la documentation. Seules les adresses locales sont acceptées : aucune question ne part sur Internet. Les rapports et les chiffres sont toujours calculés par POS Marwa, jamais par le modèle.</p>
    <div class="row g-2">
      <div class="col-md-7"><label class="form-label">Adresse du modèle</label><input name="llm_url" class="form-control" value="<?= e($url) ?>" placeholder="http://127.0.0.1:11434 (laisser vide : moteur intégré seul)"></div>
      <div class="col-md-5"><label class="form-label">Nom du modèle</label><input name="llm_model" class="form-control" value="<?= e($model) ?>" placeholder="ex. llama3.2, qwen2.5, mistral"></div>
    </div>
    <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="test" value="1" id="iaT"><label class="form-check-label" for="iaT">Tester le modèle en enregistrant</label></div>
  </div>
  <div class="card-footer"><button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button> <a class="btn btn-outline-secondary" href="<?= e(url('/ia')) ?>">Ouvrir MARWA IA</a></div>
</form>
