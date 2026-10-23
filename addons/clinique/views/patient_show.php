<link rel="stylesheet" href="<?= e(asset('addons/clinique/clinique.css')) ?>">
<?php
use Addons\Clinique\ClinicService as CS;
use Addons\Clinique\HospitalService as HS;
use Addons\Clinique\PharmacyLink as PL;
$billColors = ['open' => 'warning text-dark', 'paid' => 'success', 'cancelled' => 'secondary'];
?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <a class="btn btn-outline-secondary" href="<?= e(url('/clinique/patients')) ?>"><i class="bi bi-arrow-left"></i> Patients</a>
  <?php if (can('clinique.reception')): ?><a class="btn btn-outline-primary" href="<?= e(url('/clinique/patients/' . $p['id'] . '/edit')) ?>"><i class="bi bi-pencil"></i> Modifier</a><?php endif; ?>
  <?php if ($openVisit && can('clinique.medical')): ?><a class="btn btn-primary" href="<?= e(url('/clinique/consultations/' . $openVisit['id'])) ?>"><i class="bi bi-clipboard2-pulse"></i> Consultation en cours</a><?php endif; ?>
  <?php if (can('clinique.doctor')): ?><a class="btn btn-outline-danger" href="<?= e(url('/clinique/hospitalisation', ['patient' => $p['id']])) ?>"><i class="bi bi-hospital"></i> Hospitaliser / observation</a><?php endif; ?>
</div>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-body">
      <div class="d-flex align-items-center gap-3 mb-2"><div class="clin-avatar <?= $p['sex'] === 'F' ? 'f' : 'm' ?>"><?= e(mb_substr($p['last_name'], 0, 1) . mb_substr($p['first_name'], 0, 1)) ?></div>
        <div><div class="fs-5 fw-bold"><?= e($p['last_name'] . ' ' . $p['first_name']) ?></div><div class="text-secondary small"><?= e($p['number']) ?> · <?= $p['sex'] === 'F' ? 'Féminin' : 'Masculin' ?><?= $p['birth_date'] ? ' · ' . e(CS::age($p['birth_date'])) . ' (' . e(fdate($p['birth_date'], false)) . ')' : '' ?></div></div></div>
      <?php if ($p['allergies']): ?><div class="alert alert-danger py-1 px-2 small mb-2"><i class="bi bi-exclamation-octagon"></i> <b>Allergies</b> : <?= e($p['allergies']) ?></div><?php endif; ?>
      <dl class="row small mb-0">
        <dt class="col-5">Téléphone</dt><dd class="col-7"><?= $p['phone'] ? '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $p['phone'])) . '">' . e($p['phone']) . '</a>' : '—' ?></dd>
        <dt class="col-5">Adresse</dt><dd class="col-7"><?= e($p['address'] ?? '—') ?></dd>
        <dt class="col-5">À prévenir</dt><dd class="col-7"><?= e($p['emergency_contact'] ?? '—') ?></dd>
        <dt class="col-5">Groupe sanguin</dt><dd class="col-7"><?= e($p['blood_group'] ?? 'Inconnu') ?></dd>
        <dt class="col-5">Prise en charge</dt><dd class="col-7"><?= (float) $p['coverage_pct'] > 0 ? e($p['insurer'] ?? '') . ' — ' . e((string) (float) $p['coverage_pct']) . ' %' : 'Aucune' ?></dd>
        <?php if ($medical && $p['history']): ?><dt class="col-5">Antécédents</dt><dd class="col-7"><?= nl2br(e($p['history'])) ?></dd><?php endif; ?>
      </dl>
    </div></div>

    <?php if (!$openVisit && can('clinique.reception')): ?>
    <div class="card mb-3 border-primary"><div class="card-header bg-primary text-white"><i class="bi bi-box-arrow-in-right"></i> Admission en consultation</div><div class="card-body">
      <form method="post" action="<?= e(url('/clinique/patients/' . $p['id'] . '/queue')) ?>" class="row g-2"><?= csrf_field() ?>
        <div class="col-12"><input name="reason" class="form-control form-control-sm" placeholder="Motif de consultation" maxlength="255"></div>
        <div class="col-7"><select name="practitioner_id" class="form-select form-select-sm"><option value="">Médecin : premier disponible</option><?php foreach ($practitioners as $d): ?><option value="<?= (int) $d['id'] ?>"><?= e($d['full_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-5"><select name="priority" class="form-select form-select-sm"><option value="normal">Normal</option><option value="urgent">Urgent</option></select></div>
        <?php if (can('clinique.nurse')): ?><div class="col-12"><div class="small fw-semibold mt-1">Constantes de triage (facultatif)</div><?= App\Core\View::partial('clinique::_vitals', ['full' => true]) ?></div><?php endif; ?>
        <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-people"></i> Placer dans la file d'attente</button></div>
      </form>
    </div></div>
    <?php endif; ?>

    <div class="card mb-3"><div class="card-header">Rendez-vous</div><ul class="list-group list-group-flush small">
      <?php foreach ($appointments as $a): ?><li class="list-group-item d-flex"><span><?= e(fdate($a['start_at'])) ?><br><span class="text-secondary"><?= e($a['doctor'] ?? '') ?> <?= e($a['reason'] ?? '') ?></span></span><span class="ms-auto"><?= App\Core\View::partial('clinique::_appt_actions', ['a' => $a]) ?></span></li><?php endforeach; ?>
      <?php if (!$appointments): ?><li class="list-group-item text-secondary">Aucun rendez-vous.</li><?php endif; ?>
    </ul></div>

    <?php if (can('clinique.reception')): ?>
    <div class="card mb-3"><div class="card-header">Factures</div><ul class="list-group list-group-flush small">
      <?php foreach ($bills as $b): ?><li class="list-group-item d-flex align-items-center"><a href="<?= e(url('/clinique/factures/' . $b['id'])) ?>"><?= e($b['number']) ?></a>&nbsp;<span class="text-secondary"><?= e(fdate($b['created_at'], false)) ?></span><span class="ms-auto"><?= money($b['patient_amount']) ?> <span class="badge bg-<?= e($billColors[$b['status']]) ?>"><?= e(['open' => 'À payer', 'paid' => 'Payée', 'cancelled' => 'Annulée'][$b['status']]) ?></span></span></li><?php endforeach; ?>
      <?php if (!$bills): ?><li class="list-group-item text-secondary">Aucune facture.</li><?php endif; ?>
    </ul></div>
    <?php endif; ?>
  </div>

  <div class="col-lg-8">
  <?php if (!$medical): ?>
    <div class="alert alert-secondary"><i class="bi bi-shield-lock"></i> Le dossier médical (consultations, constantes, hospitalisations, ordonnances) est réservé au personnel soignant.</div>
  <?php else: ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-clipboard2-pulse"></i> Consultations</div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Date</th><th>Médecin</th><th>Motif</th><th>Diagnostic</th><th>Statut</th></tr></thead><tbody>
      <?php foreach ($visits as $v): ?><tr><td><a href="<?= e(url('/clinique/consultations/' . $v['id'])) ?>"><?= e(fdate($v['created_at'])) ?></a></td><td class="small"><?= e($v['practitioner'] ?? '—') ?></td><td class="small"><?= e($v['reason'] ?? '') ?></td><td class="small fw-semibold"><?= e($v['diagnosis'] ?? '') ?></td><td><span class="badge bg-light text-dark border"><?= e(CS::VISIT_STATUSES[$v['status']]) ?></span></td></tr><?php endforeach; ?>
      <?php if (!$visits): ?><tr><td colspan="5" class="text-secondary text-center py-3">Aucune consultation.</td></tr><?php endif; ?>
    </tbody></table></div></div>

    <?= App\Core\View::partial('clinique::_imaging', ['p' => $p, 'visitId' => null, 'imgStudies' => $imgStudies, 'imgRequests' => $imgRequests]) ?>

    <div class="card mb-3"><div class="card-header"><i class="bi bi-heart-pulse"></i> Dernières constantes</div><?= App\Core\View::partial('clinique::_vitals_table', ['vitals' => $vitals]) ?></div>

    <div class="row g-3">
      <div class="col-md-6"><div class="card h-100"><div class="card-header"><i class="bi bi-hospital"></i> Hospitalisations / observations</div><ul class="list-group list-group-flush small">
        <?php foreach ($stays as $s): ?><li class="list-group-item"><a href="<?= e(url('/clinique/sejours/' . $s['id'])) ?>"><?= e($s['number']) ?></a> · <?= e(fdate($s['admitted_at'])) ?> · <?= e($s['bed']) ?><br>
          <span class="text-secondary"><?= e($s['reason']) ?></span> <?= $s['status'] === 'admitted' ? '<span class="badge bg-danger">En cours</span>' : '<span class="badge bg-light text-dark border">' . e(HS::DISCHARGE[$s['discharge_mode']] ?? 'Sorti') . '</span>' ?></li><?php endforeach; ?>
        <?php if (!$stays): ?><li class="list-group-item text-secondary">Aucune.</li><?php endif; ?>
      </ul></div></div>
      <div class="col-md-6"><div class="card h-100"><div class="card-header"><i class="bi bi-capsule"></i> Ordonnances</div><ul class="list-group list-group-flush small">
        <?php foreach ($prescriptions as $pr): ?><li class="list-group-item d-flex"><span><a target="_blank" rel="noopener" href="<?= e(url('/clinique/ordonnances/' . $pr['id'] . '/print')) ?>"><?= e($pr['number']) ?></a> · <?= e(fdate($pr['created_at'], false)) ?><br><span class="text-secondary"><?= e($pr['prescriber']) ?></span></span><span class="ms-auto"><?= App\Core\View::partial('clinique::_presc_badge', ['st' => $pr['status']]) ?></span></li><?php endforeach; ?>
        <?php if (!$prescriptions): ?><li class="list-group-item text-secondary">Aucune.</li><?php endif; ?>
      </ul></div></div>
    </div>
  <?php endif; ?>
  </div>
</div>
