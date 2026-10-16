<?php if (can('reports.generate')): ?>
<form method="post" action="<?= url('reports/daily/generate') ?>" class="d-flex gap-2 mb-3" style="max-width:420px"><?= csrf_field() ?>
  <input type="date" name="date" class="form-control" value="<?= e(date('Y-m-d', strtotime('-1 day'))) ?>" max="<?= e(date('Y-m-d')) ?>"><button class="btn btn-primary text-nowrap">Générer le rapport</button></form>
<?php endif; ?>
<p class="small text-muted">Les rapports de la veille sont générés automatiquement par la tâche planifiée (scripts\maintenance.bat).</p>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
<thead><tr><th>Date</th><th class="text-end">Sorties</th><th class="text-end">Entrées</th><th class="text-end">À vérifier</th><th class="text-end">Non identifiés</th><th>Généré</th></tr></thead><tbody>
<?php foreach ($rows as $r): $s = json_decode($r['summary_json'], true); ?><tr><td><a href="<?= url('reports/daily/' . (int) $r['id']) ?>"><?= dt($r['report_date'], 'd/m/Y') ?></a></td>
  <td class="text-end"><?= (int) $s['exits'] ?></td><td class="text-end"><?= (int) $s['entries'] ?></td><td class="text-end"><?= (int) $s['to_verify'] ?></td><td class="text-end"><?= (int) $s['objects_unidentified'] ?></td>
  <td class="small"><?= e($r['generated_by']) ?> — <?= dt($r['generated_at'], 'd/m H:i') ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="text-muted text-center py-3">Aucun rapport.</td></tr><?php endif; ?>
</tbody></table></div></div>
