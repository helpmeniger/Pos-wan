<?php /** MARWA AI : intentions, exemples validés, outils. */ $cur = 'intents'; require __DIR__ . '/_nav.php'; ?>
<div class="row g-3">
  <div class="col-xl-7"><div class="card"><div class="card-header">Intentions comprises (<?= count($intents) ?>)</div>
    <div class="table-responsive" style="max-height:560px"><table class="table table-sm small mb-0"><thead><tr><th>Code</th><th>Libellé</th><th>Module</th><th>Droit requis</th><th>Exemples</th><th>État</th></tr></thead><tbody>
      <?php foreach ($intents as $i): ?><tr class="<?= (int) $i['active'] ? '' : 'text-secondary' ?>"><td><code><?= e($i['code']) ?></code></td><td><?= e($i['label']) ?></td><td><?= e((string) $i['module']) ?></td><td><?= e((string) $i['permission']) ?></td><td><?= (int) $i['examples'] ?><?= $i['custom'] ? ' <span class="badge text-bg-success">+' . (int) $i['custom'] . '</span>' : '' ?></td>
        <td><form method="post" action="<?= e(url('/ia/admin/intentions/etat')) ?>"><?= csrf_field() ?><input type="hidden" name="code" value="<?= e($i['code']) ?>"><input type="hidden" name="active" value="<?= (int) $i['active'] ? '0' : '1' ?>"><button class="btn btn-sm btn-link p-0 <?= (int) $i['active'] ? 'text-success' : 'text-secondary' ?>" title="<?= (int) $i['active'] ? 'Active — cliquer pour désactiver' : 'Désactivée — cliquer pour réactiver' ?>"><i class="bi <?= (int) $i['active'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i></button></form></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
  <div class="col-xl-5">
    <form method="post" action="<?= e(url('/ia/admin/intentions/exemple')) ?>" class="card mb-3"><?= csrf_field() ?><div class="card-header">Ajouter un exemple validé</div><div class="card-body">
      <select name="intent" class="form-select mb-2" required><option value="">Intention…</option><?php foreach ($intents as $i): ?><option value="<?= e($i['code']) ?>"><?= e($i['label']) ?></option><?php endforeach; ?></select>
      <input name="text" class="form-control" maxlength="300" required placeholder="Formulation, ex. « combien j'ai encaissé ce matin »">
      <div class="small text-secondary mt-2">Utilisé dès la question suivante. Relancez le banc de tests pour vérifier l'effet.</div></div>
      <div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Ajouter</button></div></form>
    <form method="post" action="<?= e(url('/ia/admin/intentions/ajouter')) ?>" class="card mb-3"><?= csrf_field() ?><div class="card-header">Ajouter une intention (réponse rédigée)</div><div class="card-body small">
      <input name="label" class="form-control form-control-sm mb-2" maxlength="160" required placeholder="Libellé, ex. Horaires de la boutique">
      <textarea name="examples" class="form-control form-control-sm mb-2" rows="3" required placeholder="Exemples de questions, une par ligne (deux au moins)"></textarea>
      <textarea name="answer" class="form-control form-control-sm" rows="3" required placeholder="Réponse que l'assistant donnera"></textarea>
      <div class="text-secondary mt-2">Une intention ajoutée ici donne une réponse fixe, rédigée par vous : elle ne lit aucune donnée et n'exécute rien.</div></div>
      <div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Ajouter</button></div></form>
    <div class="card mb-3"><div class="card-header">Exemples ajoutés</div>
      <?php if (!$custom): ?><div class="card-body small text-secondary">Aucun pour l'instant.</div><?php else: ?><ul class="list-group list-group-flush small"><?php foreach ($custom as $c): ?>
        <li class="list-group-item d-flex gap-2"><div><code><?= e($c['intent_code']) ?></code> « <?= e($c['text']) ?> » <span class="text-secondary">(<?= e($c['source']) ?>)</span></div>
          <form method="post" action="<?= e(url('/ia/admin/intentions/exemple/' . (int) $c['id'] . '/supprimer')) ?>" class="ms-auto" data-confirm="Supprimer cet exemple ?"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x-lg"></i></button></form></li><?php endforeach; ?></ul><?php endif; ?></div>
    <div class="card"><div class="card-header">Outils (actions et lectures)</div><div class="table-responsive" style="max-height:300px"><table class="table table-sm small mb-0"><thead><tr><th>Code</th><th>Type</th><th>Droit</th><th>Confirmation</th></tr></thead><tbody>
      <?php foreach ($tools as $t): ?><tr><td><?= e($t['label']) ?><div class="text-secondary"><code><?= e($t['code']) ?></code></div></td><td><?= e($t['kind']) ?></td><td><?= e((string) $t['permission']) ?></td><td><?= $t['needs_confirmation'] ? ($t['is_sensitive'] ? 'oui + mot de passe' : 'oui') : '—' ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  </div>
</div>
