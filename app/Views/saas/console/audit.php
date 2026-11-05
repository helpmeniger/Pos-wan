<h1 class="mb-3">Journal de la console</h1>
<div class="panel p-0"><div class="table-responsive"><table class="table table-sm small align-middle mb-0">
  <thead class="table-light"><tr><th>Date</th><th>Action</th><th>Par</th><th>Client</th><th>Détail</th><th>IP</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td class="text-nowrap"><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td><td><?= e($r['action']) ?></td><td><?= e($r['username'] ?: 'système') ?></td><td><?= $r['tenant_id'] ? '<a href="' . e(url('/console/clients/' . $r['tenant_id'])) . '">' . e($r['company']) . '</a>' : '' ?></td><td class="text-muted"><?= e(mb_substr((string) $r['details'], 0, 160)) ?></td><td><?= e($r['ip']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
