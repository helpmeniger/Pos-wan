<link rel="stylesheet" href="<?= e(asset('addons/clinique/clinique.css')) ?>">
<?php use Addons\Clinique\ClinicService as CS; ?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php if (can('clinique.reception')): ?><a href="<?= e(url('/clinique/patients/create')) ?>" class="btn btn-primary"><i class="bi bi-person-plus"></i> Nouveau patient</a><?php endif; ?>
  <a href="<?= e(url('/clinique/rdv')) ?>" class="btn btn-outline-primary"><i class="bi bi-calendar-week"></i> Rendez-vous</a>
  <?php if (can('clinique.nurse')): ?><a href="<?= e(url('/clinique/supervision')) ?>" class="btn btn-danger"><i class="bi bi-display"></i> Écran de supervision</a><?php endif; ?>
  <form class="d-flex gap-2 ms-auto" method="get" action="<?= e(url('/clinique/patients')) ?>">
    <input name="q" class="form-control" placeholder="Rechercher un patient (nom, n°, téléphone)" autofocus>
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
  </form>
</div>
<div class="row g-2 mb-3">
  <?php foreach ([
    ['Consultations du jour', $stats['visits'], 'bi-clipboard2-pulse', 'primary', null],
    ['En attente / en consultation', count($queue), 'bi-people', 'warning', null],
    ['Hospitalisés / en observation', $stats['hospital'] . ' / ' . $stats['beds'] . ' lits', 'bi-hospital', 'danger', can('clinique.medical') ? '/clinique/hospitalisation' : null],
    ['Ordonnances à délivrer', $stats['presc'], 'bi-capsule', 'success', can('clinique.pharmacy') ? '/clinique/pharmacie' : null],
    ['Encaissé aujourd\'hui', money($stats['cash']), 'bi-cash-coin', 'info', can('clinique.reception') ? '/clinique/factures?status=paid' : null],
    ['Reste à encaisser', money($stats['unpaid']), 'bi-receipt', 'secondary', can('clinique.reception') ? '/clinique/factures' : null],
  ] as [$label, $n, $icon, $c, $link]): ?>
  <div class="col-6 col-md-4 col-xl-2"><<?= $link ? 'a href="' . e(url($link)) . '"' : 'div' ?> class="card text-decoration-none border-<?= e($c) ?> h-100"><div class="card-body py-2 d-flex align-items-center gap-2">
    <i class="bi <?= e($icon) ?> fs-3 text-<?= e($c) ?>"></i><div><div class="fs-5 fw-bold text-body"><?= e((string) $n) ?></div><div class="small text-secondary"><?= e($label) ?></div></div>
  </div></<?= $link ? 'a' : 'div' ?>></div>
  <?php endforeach; ?>
</div>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card"><div class="card-header d-flex"><span><i class="bi bi-people"></i> File d'attente des consultations</span><span class="ms-auto badge bg-secondary"><?= count($queue) ?></span></div>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead><tr><th>Patient</th><th>Motif</th><th>Médecin</th><th>Attente</th><th>Statut</th><th></th></tr></thead><tbody>
    <?php foreach ($queue as $q): ?>
      <tr class="<?= $q['priority'] === 'urgent' ? 'table-danger' : '' ?>">
        <td><?= $q['priority'] === 'urgent' ? '<span class="badge bg-danger">URGENT</span> ' : '' ?><a href="<?= e(url('/clinique/patients/' . $q['patient_id'])) ?>" class="fw-semibold"><?= e($q['last_name'] . ' ' . $q['first_name']) ?></a><br><small class="text-secondary"><?= e(implode(' · ', array_filter([$q['sex'], CS::age($q['birth_date'])]))) ?> · <?= e($q['number']) ?></small></td>
        <td class="small"><?= e($q['reason'] ?? '') ?></td>
        <td class="small"><?= e($q['doctor'] ?? '—') ?></td>
        <td class="small <?= (int) $q['wait_min'] > 60 ? 'text-danger fw-semibold' : '' ?>"><?= (int) $q['wait_min'] ?> min</td>
        <td><span class="badge bg-<?= $q['status'] === 'waiting' ? 'warning text-dark' : 'info text-dark' ?>"><?= e(CS::VISIT_STATUSES[$q['status']]) ?></span></td>
        <td class="text-end"><?php if (can('clinique.medical')): ?><a class="btn btn-sm btn-primary" href="<?= e(url('/clinique/consultations/' . $q['id'])) ?>"><i class="bi bi-clipboard2-pulse"></i> Ouvrir</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$queue): ?><tr><td colspan="6" class="text-center text-secondary py-4">Aucun patient en attente.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  </div>
  <div class="col-lg-5">
    <div class="card"><div class="card-header d-flex"><span><i class="bi bi-calendar-day"></i> Rendez-vous du jour</span><a class="ms-auto small" href="<?= e(url('/clinique/rdv')) ?>">Agenda</a></div>
    <ul class="list-group list-group-flush small">
    <?php foreach ($appointments as $a): ?>
      <li class="list-group-item d-flex align-items-center gap-2">
        <b><?= e(substr($a['start_at'], 11, 5)) ?></b>
        <span><a href="<?= e(url('/clinique/patients/' . $a['patient_id'])) ?>"><?= e($a['last_name'] . ' ' . $a['first_name']) ?></a><br><span class="text-secondary"><?= e($a['doctor'] ?? '') ?><?= $a['reason'] ? ' · ' . e($a['reason']) : '' ?></span></span>
        <span class="ms-auto"><?= App\Core\View::partial('clinique::_appt_actions', ['a' => $a]) ?></span>
      </li>
    <?php endforeach; ?>
    <?php if (!$appointments): ?><li class="list-group-item text-secondary">Aucun rendez-vous aujourd'hui.</li><?php endif; ?>
    </ul></div>
  </div>
</div>
