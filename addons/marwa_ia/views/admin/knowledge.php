<?php /** MARWA AI : base de connaissances (FAQ intégrée + procédures et réponses validées par l'administrateur). */ $cur = 'knowledge'; require __DIR__ . '/_nav.php'; ?>
<div class="row g-3">
  <div class="col-xl-5"><form method="post" action="<?= e(url('/ia/admin/connaissances')) ?>" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <div class="card-header"><?= $edit ? 'Modifier la fiche' : 'Nouvelle fiche' ?></div><div class="card-body">
      <label class="form-label">Type</label><select name="kind" class="form-select mb-2"><?php foreach (['procedure' => 'Procédure', 'faq' => 'Question fréquente', 'validated_answer' => 'Réponse validée', 'note' => 'Note interne'] as $k => $l): ?><option value="<?= $k ?>" <?= ($edit['kind'] ?? 'procedure') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      <label class="form-label">Titre</label><input name="title" class="form-control mb-2" maxlength="200" required value="<?= e($edit['title'] ?? '') ?>">
      <label class="form-label">Mots-clés</label><input name="keywords" class="form-control mb-2" maxlength="500" value="<?= e($edit['keywords'] ?? '') ?>" placeholder="ex. avoir, remboursement, retour client">
      <label class="form-label">Contenu (texte ; les étapes une par ligne)</label><textarea name="body" class="form-control mb-2" rows="8" required><?= e(isset($edit['body']) ? html_entity_decode(strip_tags(str_replace(['<br />', '<br>'], '', $edit['body'])), ENT_QUOTES) : '') ?></textarea>
      <div class="row g-2"><div class="col-6"><label class="form-label">Module</label><input name="module" class="form-control" maxlength="60" value="<?= e($edit['module'] ?? '') ?>"></div>
        <div class="col-6"><label class="form-label">Réservé au droit</label><input name="permission" class="form-control" maxlength="60" value="<?= e($edit['permission'] ?? '') ?>" placeholder="ex. stock.inventory"></div></div>
      <?php if ($edit): ?><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="active" value="1" id="kA" <?= (int) $edit['active'] ? 'checked' : '' ?>><label class="form-check-label" for="kA">Fiche active</label></div><?php endif; ?>
    </div><div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Enregistrer</button><?php if ($edit): ?> <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/ia/admin/connaissances')) ?>">Nouvelle fiche</a><?php endif; ?></div></form></div>
  <div class="col-xl-7"><div class="card"><div class="card-header">Fiches (<?= count($items) ?>)</div><div class="table-responsive" style="max-height:600px"><table class="table table-sm small mb-0"><thead><tr><th>Titre</th><th>Type</th><th>Origine</th><th>Droit</th><th></th></tr></thead><tbody>
    <?php foreach ($items as $i): ?><tr class="<?= (int) $i['active'] ? '' : 'text-secondary' ?>"><td><?= e($i['title']) ?></td><td><?= e($i['kind']) ?></td><td><?= $i['source'] === 'builtin' ? 'intégrée' : 'administrateur' ?></td><td><?= e((string) $i['permission']) ?></td>
      <td><?php if ($i['source'] !== 'builtin'): ?><a href="<?= e(url('/ia/admin/connaissances', ['id' => $i['id']])) ?>">Modifier</a><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div></div>
</div>
