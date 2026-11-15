<?php /** Bibliothèque de scénarios (intégrés + ajoutés par l'administrateur). */ $cur = 'scenarios'; require __DIR__ . '/_nav.php'; ?>
<div class="row g-3">
  <div class="col-xl-8">
    <form method="get" class="card mb-3"><div class="card-body py-2 d-flex flex-wrap gap-2 align-items-end">
      <div><label class="form-label small mb-0">Domaine</label><select name="domaine" class="form-select form-select-sm"><option value="">Tous (<?= (int) $all ?>)</option><?php foreach ($domains as $d): ?><option value="<?= e($d['domain']) ?>" <?= $filters['domaine'] === $d['domain'] ? 'selected' : '' ?>><?= e($d['domain']) ?> (<?= (int) $d['n'] ?>)</option><?php endforeach; ?></select></div>
      <div><label class="form-label small mb-0">Intention</label><select name="intention" class="form-select form-select-sm"><option value="">Toutes</option><?php foreach ($intents as $i): ?><option value="<?= e($i) ?>" <?= $filters['intention'] === $i ? 'selected' : '' ?>><?= e($i) ?></option><?php endforeach; ?></select></div>
      <div class="flex-grow-1"><label class="form-label small mb-0">Recherche</label><input name="q" class="form-control form-control-sm" value="<?= e($filters['q']) ?>" placeholder="mot, code (ex. STOCK-001)"></div>
      <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filtrer</button>
      <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/ia/admin/scenarios/export')) ?>"><i class="bi bi-download"></i> Exporter (JSON, format complet)</a>
    </div></form>
    <div class="card"><div class="card-header"><?= (int) $total ?> scénario(s)</div><div class="table-responsive"><table class="table table-sm small mb-0 align-middle"><thead><tr><th>Code</th><th>Question</th><th>Intention</th><th>Rôle</th><th>Formulations</th><th></th></tr></thead><tbody>
      <?php foreach ($rows as $r): $tr = json_decode((string) $r['train_json'], true) ?: []; $te = json_decode((string) $r['test_json'], true) ?: []; ?>
      <tr><td><a href="<?= e(query_with(['voir' => $r['code']])) ?>"><code><?= e($r['code']) ?></code></a><?= $r['source'] !== 'builtin' ? ' <span class="badge text-bg-success">ajouté</span>' : '' ?></td><td><?= e($r['question']) ?><div class="text-secondary"><?= e($r['domain']) ?></div></td>
        <td><code><?= e($r['intent']) ?></code></td><td><?= e($r['role']) ?></td><td><?= count($tr) ?> entr. · <?= count($te) ?> test</td>
        <td><?php if ($r['source'] !== 'builtin'): ?><form method="post" action="<?= e(url('/ia/admin/scenarios/' . (int) $r['id'] . '/supprimer')) ?>" data-confirm="Supprimer ce scénario ?"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-trash"></i></button></form><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php if ($pages > 1): ?><div class="card-footer small d-flex gap-2 flex-wrap"><?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?= $i === $page ? 'fw-bold' : '' ?>" href="<?= e(query_with(['page' => $i, 'voir' => null])) ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?></div>
  </div>
  <div class="col-xl-4">
    <?php if ($show): ?><div class="card mb-3 border-primary"><div class="card-header">Scénario <?= e($show['id']) ?> — format complet</div><div class="card-body small">
      <dl class="mb-0">
        <dt>Domaine</dt><dd><?= e($show['domaine']) ?></dd>
        <dt>Intention principale</dt><dd><code><?= e($show['intention']) ?></code> — <?= e($show['libelle']) ?><?= $show['intentions_secondaires'] ? '<br>Secondaires : ' . e(implode(', ', $show['intentions_secondaires'])) : '' ?></dd>
        <dt>Question</dt><dd>« <?= e($show['question']) ?> » <span class="text-secondary">(rôle : <?= e($show['role']) ?><?= $show['contexte'] ? ', après ' . e($show['contexte']) : '' ?>)</span></dd>
        <dt>Variantes (entraînement)</dt><dd><?= $show['variantes'] ? e(implode(' · ', $show['variantes'])) : '—' ?></dd>
        <dt>Variantes avec fautes / de test (jamais apprises)</dt><dd><?= $show['variantes_fautes_et_test'] ? e(implode(' · ', $show['variantes_fautes_et_test'])) : '—' ?></dd>
        <dt>Synonymes reconnus</dt><dd><?= $show['synonymes'] ? e(implode(', ', array_map(fn($k, $v) => "$k → $v", array_keys($show['synonymes']), $show['synonymes']))) : '—' ?></dd>
        <dt>Entités à extraire</dt><dd><?= $show['entites'] ? e(implode(' ; ', array_map(fn($k, $v) => "$k : $v", array_keys($show['entites']), $show['entites']))) : '—' ?></dd>
        <dt>Paramètres possibles</dt><dd><?= $show['parametres'] ? e(implode(', ', $show['parametres'])) : '—' ?></dd>
        <dt>Outil métier</dt><dd><?= e($show['outil']) ?></dd>
        <dt>Permissions requises</dt><dd><?= $show['permissions'] ? e(implode(', ', $show['permissions'])) : 'aucune' ?></dd>
        <dt>Réponse attendue</dt><dd><?= e($show['reponse_attendue']) ?></dd>
        <dt>Clarification si</dt><dd><?= $show['clarification_si'] ? e(implode(' ; ', $show['clarification_si'])) : '—' ?></dd>
        <dt>Refus si</dt><dd><?= $show['refus_si'] ? e(implode(' ; ', $show['refus_si'])) : '—' ?></dd>
        <dt>Résultat attendu au test</dt><dd><code><?= e(json_encode($show['resultat_attendu'], JSON_UNESCAPED_UNICODE)) ?></code></dd>
      </dl></div></div><?php endif; ?>
    <form method="post" action="<?= e(url('/ia/admin/scenarios')) ?>" class="card"><?= csrf_field() ?><div class="card-header">Ajouter un scénario</div><div class="card-body small">
      <label class="form-label">Intention</label><select name="intent" class="form-select form-select-sm mb-2" required><option value="">Choisir…</option><?php foreach ($codes as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?> (<?= e($k) ?>)</option><?php endforeach; ?></select>
      <label class="form-label">Question</label><input name="question" class="form-control form-control-sm mb-2" maxlength="500" required>
      <label class="form-label">Variantes d'entraînement (une par ligne)</label><textarea name="train" class="form-control form-control-sm mb-2" rows="3"></textarea>
      <label class="form-label">Variantes de test, avec fautes (une par ligne, jamais apprises)</label><textarea name="test" class="form-control form-control-sm mb-2" rows="2"></textarea>
      <div class="row g-2"><div class="col-6"><label class="form-label">Rôle</label><select name="role" class="form-select form-select-sm"><?php foreach (['admin', 'gerant', 'caissier', 'magasin'] as $r): ?><option><?= $r ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label">Issue attendue</label><select name="out" class="form-select form-select-sm"><option value="">par défaut</option><option value="answer">réponse</option><option value="clarify">clarification</option><option value="refuse">refus</option><option value="propose">proposition</option></select></div></div>
      <input type="hidden" name="domain" value="Ajouts de l'administrateur">
    </div><div class="card-footer"><button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Ajouter</button></div></form>
  </div>
</div>
