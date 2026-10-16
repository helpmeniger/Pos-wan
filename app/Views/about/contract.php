<div class="row justify-content-center"><div class="col-xl-9">
<div class="card"><div class="card-body p-4 contract">
  <div class="d-flex flex-wrap align-items-start gap-2 mb-3">
    <div><h2 class="h4 mb-1">Contrat de licence d'utilisation — POS Marwa</h2><div class="small text-secondary">Version du contrat : <?= e(App\Services\Contract::VERSION) ?> · © <?= date('Y') ?> <?= e($author['name']) ?> · <?= e(implode(' / ', $author['phones'])) ?></div></div>
    <button type="button" class="btn btn-sm btn-outline-secondary ms-auto d-print-none" data-print-page><i class="bi bi-printer"></i> Imprimer</button>
  </div>
  <?php foreach ($clauses as [$t, $paras]): ?>
    <h3 class="h6 fw-bold mt-3"><?= e($t) ?></h3>
    <?php foreach ($paras as $p): ?><p class="mb-2"><?= e($p) ?></p><?php endforeach; ?>
  <?php endforeach; ?>
  <div class="row mt-4 small"><div class="col-6">L'Auteur<br><b><?= e($author['name']) ?></b></div><div class="col-6 text-end">Le Client<br><b><?= e(setting('company_name', '')) ?></b></div></div>
</div></div>
</div></div>
