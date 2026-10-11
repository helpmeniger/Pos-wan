<?php use Addons\Clinique\ClinicService as CS; use Addons\Clinique\HospitalService as HS; ?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <a class="btn btn-sm <?= $status === 'admitted' ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url('/clinique/hospitalisation')) ?>">Patients présents (<?= $status === 'admitted' ? count($rows) : '…' ?>)</a>
  <a class="btn btn-sm <?= $status === 'discharged' ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url('/clinique/hospitalisation', ['status' => 'discharged'])) ?>">Sortis</a>
  <?php if (can('clinique.nurse')): ?><a class="btn btn-danger ms-auto" href="<?= e(url('/clinique/supervision')) ?>"><i class="bi bi-display"></i> Écran de supervision</a><?php endif; ?>
</div>
<div class="row g-3">
  <div class="<?= can('clinique.doctor') && $status === 'admitted' ? 'col-lg-8' : 'col-12' ?>">
    <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Lit</th><th>Patient</th><th>Motif / diagnostic</th><th>Entrée</th><th><?= $status === 'admitted' ? 'Jour' : 'Sortie' ?></th><th>Médecin</th></tr></thead><tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><b><?= e($s['bed']) ?></b><br><small class="text-secondary"><?= e($s['room']) ?></small></td>
        <td><a class="fw-semibold" href="<?= e(url('/clinique/sejours/' . $s['id'])) ?>"><?= e($s['last_name'] . ' ' . $s['first_name']) ?></a><br><small class="text-secondary"><?= e(implode(' · ', array_filter([$s['sex'], CS::age($s['birth_date'])]))) ?> · <?= e($s['number']) ?></small> <?= $s['kind'] === 'observation' ? '<span class="badge bg-warning text-dark">Observation</span>' : '' ?></td>
        <td class="small"><?= e($s['reason']) ?><?= $s['diagnosis'] ? '<br><b>' . e($s['diagnosis']) . '</b>' : '' ?></td>
        <td class="small"><?= e(fdate($s['admitted_at'])) ?></td>
        <td class="small"><?= $status === 'admitted' ? 'J' . HS::days($s) : e(fdate($s['discharged_at'])) . '<br>' . e(HS::DISCHARGE[$s['discharge_mode']] ?? '') ?></td>
        <td class="small"><?= e($s['doctor'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-secondary py-4"><?= $status === 'admitted' ? 'Aucun patient hospitalisé.' : 'Aucune sortie.' ?></td></tr><?php endif; ?>
    </tbody></table></div></div>
  </div>
  <?php if (can('clinique.doctor') && $status === 'admitted'): ?>
  <div class="col-lg-4">
    <div class="card border-danger"><div class="card-header bg-danger text-white"><i class="bi bi-hospital"></i> Admission</div><div class="card-body">
      <?php if (!$beds): ?><div class="alert alert-warning py-2 small">Aucun lit libre. Ajoutez des lits dans « Actes, lits, rapport » ou libérez-en un (sortie).</div><?php endif; ?>
      <form method="post" action="<?= e(url('/clinique/hospitalisation')) ?>" class="row g-2"><?= csrf_field() ?>
        <input type="hidden" name="visit_id" value="<?= (int) $visitId ?>">
        <div class="col-12"><label class="form-label small mb-0">Patient *</label><select name="patient_id" class="form-select" required><option value="">—</option><?php foreach ($patients as $pt): ?><option value="<?= (int) $pt['id'] ?>"<?= (int) $pt['id'] === $patientId ? ' selected' : '' ?>><?= e($pt['last_name'] . ' ' . $pt['first_name'] . ' — ' . $pt['number']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small mb-0">Type</label><div>
          <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="kind" value="hospitalisation" id="kH" checked><label class="form-check-label" for="kH">Hospitalisation</label></div>
          <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="kind" value="observation" id="kO"><label class="form-check-label" for="kO">Mise en observation</label></div></div></div>
        <div class="col-12"><label class="form-label small mb-0">Lit *</label><select name="bed_id" class="form-select" required><option value="">—</option><?php foreach ($beds as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['room'] . ' — ' . $b['name'] . ' (' . money($b['daily_price']) . '/j)') ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small mb-0">Motif *</label><input name="reason" class="form-control" required maxlength="255"></div>
        <div class="col-12"><label class="form-label small mb-0">Diagnostic d'entrée</label><input name="diagnosis" class="form-control" maxlength="500"></div>
        <div class="col-12"><button class="btn btn-danger w-100" <?= $beds ? '' : 'disabled' ?>><i class="bi bi-box-arrow-in-right"></i> Admettre le patient</button></div>
      </form>
    </div></div>
  </div>
  <?php endif; ?>
</div>
