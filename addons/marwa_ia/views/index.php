<?php /** MARWA IA : page de l'assistant (conversation + exemples + dernières questions). */ ?>
<div class="row g-3 mia-page">
  <div class="col-xl-8">
    <div class="card mia-card"><div class="mia-chat" data-mia-chat data-mia-full>
      <div class="mia-log" data-mia-log></div>
      <form class="mia-form" data-mia-form data-no-lock><input class="form-control" data-mia-input maxlength="500" autocomplete="off" placeholder="Posez votre question : « rapport du client Moussa en PDF », « que dois-je commander ? »…" aria-label="Question pour MARWA IA"><button class="btn btn-primary" type="submit" aria-label="Envoyer"><i class="bi bi-send"></i></button></form>
    </div></div>
  </div>
  <div class="col-xl-4">
    <div class="card mb-3"><div class="card-header">Exemples de questions</div><div class="card-body small">
      <?php foreach ($examples as $g => $list): ?><div class="fw-semibold mt-2 mb-1"><?= e($g) ?></div><div class="d-flex flex-wrap gap-1"><?php foreach ($list as $x): ?><button type="button" class="btn btn-sm btn-outline-secondary mia-ex" data-mia-ask="<?= e($x) ?>"><?= e($x) ?></button><?php endforeach; ?></div><?php endforeach; ?>
      <div class="text-secondary mt-3">Remplacez « … » par le nom réel. Ajoutez « en PDF » ou « en Excel » pour télécharger. Après une réponse, enchaînez : « et le mois dernier ? », « et en PDF ? ».</div>
    </div></div>
    <?php if ($history): ?><div class="card"><div class="card-header d-flex align-items-center">Vos dernières questions
      <form method="post" action="<?= e(url('/ia/oubli')) ?>" class="ms-auto" data-confirm="Effacer l'historique de vos questions ?"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0">Effacer</button></form></div>
      <ul class="list-group list-group-flush small"><?php foreach ($history as $h): ?><li class="list-group-item"><a href="#" data-mia-ask="<?= e($h['question']) ?>"><?= e($h['question']) ?></a><span class="text-secondary float-end"><?= e(fdate($h['created_at'])) ?></span></li><?php endforeach; ?></ul></div><?php endif; ?>
    <p class="small text-secondary mt-3">MARWA IA calcule tout sur le serveur de la boutique, sans Internet. Les rapports respectent les droits de votre rôle. Vérifiez les chiffres importants dans les écrans d'origine avant une décision.</p>
  </div>
</div>
