<?php $prev = date('Y-m-d', strtotime($day . ' -1 day')); $next = date('Y-m-d', strtotime($day . ' +1 day')); ?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
      <a class="btn btn-outline-secondary" href="<?= e(url('/clinique/rdv', ['d' => $prev])) ?>"><i class="bi bi-chevron-left"></i></a>
      <form method="get" action="<?= e(url('/clinique/rdv')) ?>"><input type="date" name="d" value="<?= e($day) ?>" class="form-control" data-autosubmit></form>
      <a class="btn btn-outline-secondary" href="<?= e(url('/clinique/rdv', ['d' => $next])) ?>"><i class="bi bi-chevron-right"></i></a>
      <a class="btn btn-outline-primary" href="<?= e(url('/clinique/rdv')) ?>">Aujourd'hui</a>
      <span class="ms-auto fw-semibold"><?= e(fdate($day, false)) ?> · <?= count($rows) ?> rendez-vous</span>
    </div>
    <div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Heure</th><th>Patient</th><th>Médecin</th><th>Motif</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $a): ?>
      <tr class="<?= in_array($a['status'], ['cancelled', 'no_show'], true) ? 'text-secondary' : '' ?>">
        <td class="fw-bold"><?= e(substr($a['start_at'], 11, 5)) ?><br><small class="text-secondary fw-normal"><?= (int) $a['duration_min'] ?> min</small></td>
        <td><a href="<?= e(url('/clinique/patients/' . $a['patient_id'])) ?>"><?= e($a['last_name'] . ' ' . $a['first_name']) ?></a><br><small class="text-secondary"><?= e($a['pnum']) ?><?= $a['phone'] ? ' · ' . e($a['phone']) : '' ?></small></td>
        <td class="small"><?= e($a['doctor'] ?? '—') ?></td>
        <td class="small"><?= e($a['reason'] ?? '') ?></td>
        <td class="text-end"><?= App\Core\View::partial('clinique::_appt_actions', ['a' => $a]) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-secondary py-4">Aucun rendez-vous ce jour.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  </div>
  <div class="col-lg-4">
    <?php if (can('clinique.reception')): ?>
    <div class="card"><div class="card-header"><i class="bi bi-calendar-plus"></i> Nouveau rendez-vous</div><div class="card-body">
      <form method="post" action="<?= e(url('/clinique/rdv')) ?>" class="row g-2"><?= csrf_field() ?>
        <div class="col-12"><label class="form-label small mb-0">Patient *</label>
          <select name="patient_id" class="form-select" required><option value="">—</option><?php foreach ($patients as $pt): ?><option value="<?= (int) $pt['id'] ?>"><?= e($pt['last_name'] . ' ' . $pt['first_name'] . ' — ' . $pt['number'] . ($pt['phone'] ? ' — ' . $pt['phone'] : '')) ?></option><?php endforeach; ?></select>
          <a class="small" href="<?= e(url('/clinique/patients/create')) ?>">Nouveau patient</a></div>
        <div class="col-8"><label class="form-label small mb-0">Date et heure *</label><input type="datetime-local" name="start_at" value="<?= e($day) ?>T08:00" class="form-control" required></div>
        <div class="col-4"><label class="form-label small mb-0">Durée</label><select name="duration_min" class="form-select"><?php foreach ([10, 15, 20, 30, 45, 60] as $m): ?><option value="<?= $m ?>"<?= $m === 20 ? ' selected' : '' ?>><?= $m ?> min</option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small mb-0">Médecin</label><select name="practitioner_id" class="form-select"><option value="">—</option><?php foreach ($practitioners as $d): ?><option value="<?= (int) $d['id'] ?>"><?= e($d['full_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small mb-0">Motif</label><input name="reason" class="form-control" maxlength="255"></div>
        <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-check2"></i> Enregistrer</button></div>
      </form>
    </div></div>
    <?php endif; ?>
  </div>
</div>
