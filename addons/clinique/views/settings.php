<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tRep" type="button">Rapport d'activité</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tActs" type="button">Actes et tarifs</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tBeds" type="button">Chambres et lits</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tAlert" type="button">Seuils d'alerte</button></li>
</ul>
<div class="tab-content">
<div class="tab-pane fade show active" id="tRep">
  <div class="card mb-3"><div class="card-body py-2"><form class="row g-2 align-items-end" method="get">
    <?= App\Core\View::partial('partials/period', ['from' => $from, 'to' => $to]) ?>
    <div class="col-auto"><button class="btn btn-sm btn-primary">Afficher</button></div>
    <?php if (can('reports.export')): ?><div class="col-auto ms-auto"><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a> <a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a></div><?php endif; ?>
  </form></div></div>
  <div class="row g-2 mb-3">
    <?php foreach ([['Consultations', $stats['visits']], ['Nouveaux patients', $stats['patients']], ['Hospitalisations / observations', $stats['stays']], ['Facturé', money($stats['billed'])], ['Pris en charge (tiers payant)', money($stats['coverage'])], ['Encaissé', money($stats['cash'])]] as [$l, $n]): ?>
    <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body py-2"><div class="small text-secondary"><?= e($l) ?></div><div class="fs-5 fw-bold"><?= e((string) $n) ?></div></div></div></div>
    <?php endforeach; ?>
  </div>
  <div class="row g-3">
    <div class="col-lg-6"><div class="card"><div class="card-header">Actes facturés</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Acte</th><th class="num">Qté</th><th class="num">Montant</th></tr></thead><tbody>
      <?php foreach ($byAct as $r): ?><tr><td><?= e($r['label']) ?></td><td class="num"><?= e(qty($r['qty'])) ?></td><td class="num"><?= money($r['total']) ?></td></tr><?php endforeach; ?>
      <?php if (!$byAct): ?><tr><td colspan="3" class="text-secondary text-center">Aucun.</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
    <div class="col-lg-6">
      <div class="card mb-3"><div class="card-header">Consultations terminées par médecin</div><ul class="list-group list-group-flush small"><?php foreach ($byDoctor as $r): ?><li class="list-group-item d-flex"><?= e($r['name']) ?><b class="ms-auto"><?= (int) $r['n'] ?></b></li><?php endforeach; ?><?php if (!$byDoctor): ?><li class="list-group-item text-secondary">Aucune.</li><?php endif; ?></ul></div>
      <div class="card mb-3"><div class="card-header">Diagnostics les plus fréquents</div><ul class="list-group list-group-flush small"><?php foreach ($diag as $r): ?><li class="list-group-item d-flex"><?= e($r['diagnosis']) ?><b class="ms-auto"><?= (int) $r['n'] ?></b></li><?php endforeach; ?><?php if (!$diag): ?><li class="list-group-item text-secondary">Aucun.</li><?php endif; ?></ul></div>
      <div class="card"><div class="card-header">Tiers payants (à réclamer aux organismes)</div><ul class="list-group list-group-flush small"><?php foreach ($byInsurer as $r): ?><li class="list-group-item d-flex"><?= e($r['insurer'] ?? '—') ?> (<?= (int) $r['n'] ?> facture(s))<b class="ms-auto"><?= money($r['total']) ?></b></li><?php endforeach; ?><?php if (!$byInsurer): ?><li class="list-group-item text-secondary">Aucun.</li><?php endif; ?></ul></div>
    </div>
  </div>
</div>
<div class="tab-pane fade" id="tActs">
  <form method="post" action="<?= e(url('/clinique/parametres/actes')) ?>"><?= csrf_field() ?>
  <div class="card mb-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Catégorie</th><th>Acte</th><th style="width:160px">Prix (<?= e(currency()['symbol']) ?>)</th><th>Actif</th></tr></thead><tbody>
    <?php foreach ($acts as $a): ?><tr><td class="small text-secondary"><?= e($a['category']) ?></td><td><input name="acts[<?= (int) $a['id'] ?>][name]" value="<?= e($a['name']) ?>" class="form-control form-control-sm" maxlength="120" required></td><td><input name="acts[<?= (int) $a['id'] ?>][price]" value="<?= e((string) (float) $a['price']) ?>" class="form-control form-control-sm text-end" inputmode="numeric"></td><td><input type="checkbox" class="form-check-input" name="acts[<?= (int) $a['id'] ?>][active]" value="1"<?= (int) $a['active'] ? ' checked' : '' ?>></td></tr><?php endforeach; ?>
    <tr class="table-light"><td><input name="new_category" class="form-control form-control-sm" placeholder="Catégorie" list="actCats" maxlength="60"><datalist id="actCats"><?php foreach (array_unique(array_column($acts, 'category')) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist></td><td><input name="new_name" class="form-control form-control-sm" placeholder="Nouvel acte" maxlength="120"></td><td><input name="new_price" class="form-control form-control-sm text-end" placeholder="0" inputmode="numeric"></td><td></td></tr>
  </tbody></table></div></div>
  <button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer les actes</button>
  </form>
</div>
<div class="tab-pane fade" id="tBeds">
  <form method="post" action="<?= e(url('/clinique/parametres/lits')) ?>"><?= csrf_field() ?>
  <div class="card mb-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Salle / chambre</th><th>Lit</th><th style="width:170px">Prix / jour (<?= e(currency()['symbol']) ?>)</th><th>Actif</th></tr></thead><tbody>
    <?php foreach ($beds as $b): ?><tr><td><?= e($b['room']) ?></td><td><?= e($b['name']) ?></td><td><input name="beds[<?= (int) $b['id'] ?>][daily_price]" value="<?= e((string) (float) $b['daily_price']) ?>" class="form-control form-control-sm text-end" inputmode="numeric"></td><td><input type="checkbox" class="form-check-input" name="beds[<?= (int) $b['id'] ?>][active]" value="1"<?= (int) $b['active'] ? ' checked' : '' ?>></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
  <div class="row g-3 mb-3">
    <div class="col-md-5"><div class="card h-100"><div class="card-header">Nouvelle salle / chambre</div><div class="card-body row g-2">
      <div class="col-7"><input name="room_name" class="form-control form-control-sm" placeholder="ex. Chambre 3" maxlength="80"></div>
      <div class="col-5"><input name="ward" class="form-control form-control-sm" placeholder="Service" maxlength="80" list="wards"><datalist id="wards"><?php foreach (array_unique(array_column($rooms, 'ward')) as $w): ?><option value="<?= e($w) ?>"><?php endforeach; ?><option value="Maternité"><option value="Pédiatrie"></datalist></div>
    </div></div></div>
    <div class="col-md-7"><div class="card h-100"><div class="card-header">Nouveau lit</div><div class="card-body row g-2">
      <div class="col-5"><select name="room_id" class="form-select form-select-sm"><option value="">Salle…</option><?php foreach ($rooms as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?> (<?= e($r['ward']) ?>)</option><?php endforeach; ?></select></div>
      <div class="col-4"><input name="bed_name" class="form-control form-control-sm" placeholder="ex. Lit 3" maxlength="40"></div>
      <div class="col-3"><input name="daily_price" class="form-control form-control-sm text-end" placeholder="Prix/jour" inputmode="numeric"></div>
    </div></div></div>
  </div>
  <button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer chambres et lits</button>
  <div class="small text-secondary mt-2">Un lit désactivé n'est plus proposé à l'admission (l'historique est conservé).</div>
  </form>
</div>
<div class="tab-pane fade" id="tAlert">
  <form method="post" action="<?= e(url('/clinique/parametres/seuils')) ?>" class="card" style="max-width:720px"><?= csrf_field() ?>
  <div class="card-body row g-2">
    <div class="col-12 small text-secondary">Une constante hors de ces limites met le lit en alerte (rouge) sur l'écran de supervision. Valeurs à valider par le médecin responsable.</div>
    <?php foreach (Addons\Clinique\ClinicController::THRESHOLDS as $k => [$label, $def]): ?>
    <div class="col-6 col-md-4"><label class="form-label small mb-0"><?= e($label) ?></label><input name="th[<?= e($k) ?>]" value="<?= e((string) setting('clinique_' . $k, $def)) ?>" class="form-control form-control-sm" inputmode="decimal" required></div>
    <?php endforeach; ?>
  </div>
  <div class="card-footer"><button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer les seuils</button></div>
  </form>
</div>
</div>
