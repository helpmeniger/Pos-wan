<?php /** MARWA AI : pilotage (compréhension, avis, usage des modèles, questions à enseigner, banc de tests). */
$cur = 'admin'; require __DIR__ . '/_nav.php';
$o = $dash['outcomes']; ?>
<div class="row g-3 mb-3">
  <?php foreach ([['Questions (30 j)', $dash['total']], ['Comprises', $dash['understood'] === null ? '—' : $dash['understood'] . ' %'], ['Non comprises', (int) ($o['not_understood'] ?? 0)], ['Refus (droits / hors sujet)', (int) ($o['refused'] ?? 0)],
      ['Avis 👍 / 👎', $dash['up'] . ' / ' . $dash['down']], ['Exemples d\'entraînement', $dash['examples'] . ' (dont ' . $dash['admin_examples'] . ' validés ici)'], ['Fiches de connaissance', $dash['knowledge']]] as $k): ?>
    <div class="col-6 col-md-3 col-xl"><div class="card h-100"><div class="card-body py-2"><div class="small text-secondary"><?= e($k[0]) ?></div><div class="fs-5 fw-semibold"><?= e((string) $k[1]) ?></div></div></div></div>
  <?php endforeach; ?>
</div>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card mb-3"><div class="card-header d-flex align-items-center">Banc de tests de compréhension
      <form method="post" action="<?= e(url('/ia/admin/banc')) ?>" class="ms-auto"><?= csrf_field() ?><button class="btn btn-sm btn-primary"><i class="bi bi-play-fill"></i> Lancer (<?= count(Addons\MarwaIa\Brain\Learning::BENCH) ?> questions)</button></form></div>
      <?php if ($bench): ?>
        <div class="card-body py-2"><b><?= (int) $bench['ok'] ?> / <?= (int) $bench['total'] ?></b> questions classées dans l'intention attendue (<?= round($bench['ok'] / max(1, $bench['total']) * 100) ?> %).</div>
        <div class="table-responsive" style="max-height:420px"><table class="table table-sm small mb-0"><thead><tr><th>Question</th><th>Attendu</th><th>Obtenu</th><th>Score</th></tr></thead><tbody>
          <?php foreach ($bench['results'] as $r): ?><tr class="<?= $r['ok'] ? '' : 'table-danger' ?>"><td><?= e($r['q']) ?></td><td><?= e($r['expected']) ?></td><td><?= e($r['got'] ?? '— (non compris)') ?></td><td><?= e(number_format($r['score'], 2)) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php else: ?><div class="card-body small text-secondary">Vérifie que les formulations courantes (avec fautes, synonymes, familier) sont bien comprises. À relancer après chaque ajout d'exemples.</div><?php endif; ?>
    </div>
    <?php if (!$bench): ?>
    <div class="card mb-3"><div class="card-header">Questions à enseigner (non comprises ou confiées à l'ancien moteur)</div>
      <?php if (!$unresolved): ?><div class="card-body small text-secondary">Aucune question en attente.</div><?php else: ?>
      <div class="table-responsive"><table class="table table-sm small mb-0 align-middle"><thead><tr><th>Question (anonymisée)</th><th>Résultat</th><th style="min-width:260px">Rattacher à</th></tr></thead><tbody>
        <?php foreach ($unresolved as $u): ?><tr><td><?= e($u['question']) ?><div class="text-secondary"><?= e(fdate($u['created_at'])) ?></div></td><td><?= e($u['outcome']) ?><?= $u['intent'] ? '<div class="text-secondary">' . e($u['intent']) . ' (' . e((string) $u['confidence']) . ')</div>' : '' ?></td>
          <td><form method="post" action="<?= e(url('/ia/admin/apprentissage/' . (int) $u['id'])) ?>" class="d-flex gap-1"><?= csrf_field() ?><select name="intent" class="form-select form-select-sm" required><option value="">Choisir…</option><?php foreach ($intents as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-success" title="Valider"><i class="bi bi-check2"></i></button></form></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="col-xl-5">
    <div class="card mb-3"><div class="card-header d-flex align-items-center">Connaissance de l'application
      <form method="post" action="<?= e(url('/ia/admin/index')) ?>" class="ms-auto"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Reconstruire</button></form></div>
      <div class="card-body small"><?php $lbl = ['module' => 'modules', 'menu' => 'entrées de menu', 'route' => 'routes', 'permission' => 'droits', 'table' => 'tables', 'setting' => 'paramètres (noms seuls)', 'doc' => 'sections de documentation'];
        foreach ($index as $k => $n): ?><span class="badge text-bg-light border me-1 mb-1"><?= (int) $n ?> <?= e($lbl[$k] ?? $k) ?></span><?php endforeach; ?>
        <div class="text-secondary mt-1">Mise à jour automatique dès que la version, les modules, le schéma de la base ou les droits changent.</div></div></div>
    <?php if ($usage): ?><div class="card mb-3"><div class="card-header">Utilisation des modèles (30 j)</div><div class="table-responsive"><table class="table table-sm small mb-0"><thead><tr><th>Modèle</th><th>Appels</th><th>Réussis</th><th>Jetons entrée / sortie</th><th>Coût estimé</th></tr></thead><tbody>
      <?php foreach ($usage as $x): ?><tr><td><?= e($x['name']) ?><div class="text-secondary"><?= e($x['model']) ?></div></td><td><?= (int) $x['n'] ?></td><td><?= (int) $x['ok'] ?></td><td><?= number_format((int) $x['tin'], 0, ',', ' ') ?> / <?= number_format((int) $x['tout'], 0, ',', ' ') ?></td><td><?= e(number_format((float) $x['cost'], 4, ',', ' ')) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div><?php endif; ?>
    <?php if ($feedback): ?><div class="card mb-3"><div class="card-header">Avis négatifs récents</div><ul class="list-group list-group-flush small"><?php foreach ($feedback as $f): ?><li class="list-group-item"><b><?= e($f['intent'] ?? '—') ?></b> · <?= e(mb_substr($f['content'], 0, 140)) ?><?= $f['comment'] ? '<div class="text-danger">« ' . e($f['comment']) . ' »</div>' : '' ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if ($actions): ?><div class="card"><div class="card-header">Dernières actions proposées</div><ul class="list-group list-group-flush small"><?php foreach ($actions as $a): ?><li class="list-group-item"><span class="badge text-bg-<?= ['executed' => 'success', 'proposed' => 'warning', 'failed' => 'danger', 'refused' => 'danger'][$a['status']] ?? 'secondary' ?>"><?= e($a['status']) ?></span> <?= e($a['tool']) ?> · <?= e($a['username'] ?? '') ?> · <?= e(fdate($a['created_at'])) ?><div class="text-secondary"><?= e(mb_substr((string) $a['summary'], 0, 160)) ?></div></li><?php endforeach; ?></ul></div><?php endif; ?>
  </div>
</div>
<p class="small text-secondary mt-3">L'apprentissage de MARWA AI se fait par exemples validés par un administrateur : les conversations brutes ne modifient jamais le modèle. Les questions sont enregistrées anonymisées (numéros de téléphone, adresses électroniques et numéros de pièces masqués).</p>
