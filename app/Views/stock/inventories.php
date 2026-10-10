<div class="row g-3">
  <div class="col-lg-8"><div class="card"><table class="table table-sm table-hover mb-0"><thead><tr><th>N°</th><th>Date</th><th>Entrepôt</th><th>Par</th><th class="num">Comptés</th><th>Statut</th></tr></thead><tbody>
  <?php foreach ($p['rows'] as $i): ?><tr><td><a href="<?= e(url('/inventories/' . $i['id'])) ?>"><?= e($i['number']) ?></a></td><td><?= e(fdate($i['created_at'])) ?></td><td><?= e($i['warehouse']) ?></td><td><?= e($i['full_name']) ?></td><td class="num"><?= (int) $i['counted'] ?>/<?= (int) $i['n'] ?></td><td><?= App\Core\View::partial('partials/status', ['status' => $i['status']]) ?></td></tr><?php endforeach; ?>
  <?php if (!$p['rows']): ?><tr><td colspan="6" class="text-center text-secondary py-4">Aucun inventaire.</td></tr><?php endif; ?>
  </tbody></table></div><?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?></div>
  <div class="col-lg-4"><div class="card"><div class="card-header"><i class="bi bi-clipboard-plus"></i> Nouvel inventaire</div><div class="card-body">
    <form method="post" action="<?= e(url('/inventories')) ?>" class="row g-2"><?= csrf_field() ?>
      <div class="col-12"><label class="form-label">Entrepôt</label><select name="warehouse_id" class="form-select"><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><label class="form-label">Catégorie (inventaire partiel)</label><select name="category_id" class="form-select"><option value="">Tous les articles</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><input name="note" class="form-control" placeholder="Note (optionnel)" maxlength="255"></div>
      <div class="col-12"><button class="btn btn-primary w-100">Démarrer</button></div>
    </form>
    <p class="small text-secondary mt-2 mb-0">Le stock théorique est figé au démarrage. À la validation, seul l'écart compté est appliqué : les ventes réalisées pendant le comptage restent prises en compte.</p>
  </div></div></div>
</div>
