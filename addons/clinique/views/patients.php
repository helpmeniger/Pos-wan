<?php use Addons\Clinique\ClinicService as CS; ?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php if (can('clinique.reception')): ?><a href="<?= e(url('/clinique/patients/create')) ?>" class="btn btn-primary"><i class="bi bi-person-plus"></i> Nouveau patient</a><?php endif; ?>
  <form class="d-flex gap-2 ms-auto" method="get" action="<?= e(url('/clinique/patients')) ?>">
    <input name="q" value="<?= e($q) ?>" class="form-control" placeholder="Nom, prénom, n° de dossier ou téléphone" autofocus>
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
  </form>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>N° dossier</th><th>Patient</th><th>Sexe / âge</th><th>Téléphone</th><th>Prise en charge</th><th></th></tr></thead><tbody>
<?php foreach ($p['rows'] as $r): ?>
  <tr>
    <td><a class="fw-semibold" href="<?= e(url('/clinique/patients/' . $r['id'])) ?>"><?= e($r['number']) ?></a></td>
    <td><?= e($r['last_name'] . ' ' . $r['first_name']) ?><?= $r['allergies'] ? ' <span class="badge bg-danger" title="' . e($r['allergies']) . '">Allergie</span>' : '' ?></td>
    <td><?= e(implode(' · ', array_filter([$r['sex'], CS::age($r['birth_date'])]))) ?></td>
    <td><?= $r['phone'] ? '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $r['phone'])) . '">' . e($r['phone']) . '</a>' : '' ?></td>
    <td class="small"><?= (float) $r['coverage_pct'] > 0 ? e($r['insurer'] ?? '') . ' ' . e((string) (float) $r['coverage_pct']) . ' %' : '—' ?></td>
    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/clinique/patients/' . $r['id'])) ?>"><i class="bi bi-folder2-open"></i> Dossier</a></td>
  </tr>
<?php endforeach; ?>
<?php if (!$p['rows']): ?><tr><td colspan="6" class="text-center text-secondary py-4">Aucun patient<?= $q !== '' ? ' pour « ' . e($q) . ' »' : '' ?>.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
