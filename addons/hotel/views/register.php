<?php use Addons\Hotel\HotelService as HS; ?>
<div class="card mb-3"><div class="card-body py-2"><form class="row g-2 align-items-end" method="get">
  <?= App\Core\View::partial('partials/period', ['from' => $from, 'to' => $to]) ?>
  <div class="col-auto"><button class="btn btn-sm btn-primary">Afficher</button></div>
  <?php if (can('reports.export')): ?><div class="col-auto ms-auto"><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a> <a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a></div><?php endif; ?>
</form></div></div>
<div class="alert alert-light border small"><i class="bi bi-shield-lock"></i> Registre des voyageurs (main courante) : données personnelles à ne communiquer qu'aux autorités habilitées. Accès réservé au responsable ; chaque export est tracé dans le journal d'audit si activé (loi n° 2017-28 sur la protection des données personnelles).</div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0 small"><thead><tr><th>Arrivée</th><th>Ch.</th><th>Nom et prénoms</th><th>Né(e) le / à</th><th>Nationalité</th><th>Profession</th><th>Pièce</th><th>Venant de / allant à</th><th>Départ</th></tr></thead><tbody>
<?php foreach ($rows as $x): ?>
  <tr><td class="text-nowrap"><?= e(fdate($x['checked_in_at'])) ?><br><small class="text-secondary"><?= e($x['number']) ?></small></td><td><?= e((string) $x['room']) ?></td><td class="fw-semibold"><?= e(trim($x['last_name'] . ' ' . $x['first_name'])) ?> <?= $x['sex'] ? '(' . e($x['sex']) . ')' : '' ?></td>
    <td><?= $x['birth_date'] ? e(fdate($x['birth_date'], false)) : '' ?> <?= e((string) $x['birth_place']) ?></td><td><?= e((string) $x['nationality']) ?></td><td><?= e((string) $x['profession']) ?></td>
    <td><?= e(HS::ID_TYPES[$x['id_type']] ?? '') ?> <?= e((string) $x['id_number']) ?></td><td><?= e((string) $x['coming_from']) ?> / <?= e((string) $x['going_to']) ?></td>
    <td class="text-nowrap"><?= $x['checked_out_at'] ? e(fdate($x['checked_out_at'])) : e(fdate($x['departure'], false)) . ' <small class="text-secondary">(prévu)</small>' ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="9" class="text-center text-secondary py-4">Aucune arrivée sur la période.</td></tr><?php endif; ?>
</tbody></table></div></div>
