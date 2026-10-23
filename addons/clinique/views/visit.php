<link rel="stylesheet" href="<?= e(asset('addons/clinique/clinique.css')) ?>">
<?php
use Addons\Clinique\ClinicService as CS;
$editable = in_array($v['status'], ['waiting', 'in_progress'], true) && can('clinique.doctor');
$printp = (int) ($_GET['printp'] ?? 0);
$lastAlerts = CS::alerts($vitals[0] ?? null);
?>
<?php if ($printp && in_array($printp, array_map('intval', array_column($prescriptions, 'id')), true)): ?><span data-autoprint-url="<?= e(url('/clinique/ordonnances/' . $printp . '/print', ['auto' => 1])) ?>"></span><?php endif; ?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <a class="btn btn-outline-secondary" href="<?= e(url('/clinique')) ?>"><i class="bi bi-arrow-left"></i> Accueil</a>
  <a class="btn btn-outline-primary" href="<?= e(url('/clinique/patients/' . $p['id'])) ?>"><i class="bi bi-folder2-open"></i> Dossier</a>
  <?php if (can('clinique.doctor') && $v['status'] !== 'cancelled'): ?><a class="btn btn-outline-danger" href="<?= e(url('/clinique/hospitalisation', ['patient' => $p['id'], 'visit' => $v['id']])) ?>"><i class="bi bi-hospital"></i> Hospitaliser / mettre en observation</a><?php endif; ?>
  <span class="ms-auto"><span class="badge bg-<?= $v['priority'] === 'urgent' ? 'danger' : 'secondary' ?>"><?= $v['priority'] === 'urgent' ? 'URGENT' : 'Normal' ?></span> <span class="badge bg-info text-dark"><?= e(CS::VISIT_STATUSES[$v['status']]) ?></span></span>
</div>
<div class="card mb-3 clin-patient-bar"><div class="card-body py-2 d-flex flex-wrap gap-3 align-items-center">
  <div class="clin-avatar <?= $p['sex'] === 'F' ? 'f' : 'm' ?>"><?= e(mb_substr($p['last_name'], 0, 1) . mb_substr($p['first_name'], 0, 1)) ?></div>
  <div><div class="fw-bold fs-5"><?= e($p['last_name'] . ' ' . $p['first_name']) ?></div><div class="small text-secondary"><?= e($p['number']) ?> · <?= e(implode(' · ', array_filter([$p['sex'], CS::age($p['birth_date'])]))) ?><?= $p['blood_group'] ? ' · ' . e($p['blood_group']) : '' ?> · <?= e($v['number']) ?> du <?= e(fdate($v['created_at'])) ?></div></div>
  <?php if ($p['allergies']): ?><div class="alert alert-danger py-1 px-2 mb-0 small"><i class="bi bi-exclamation-octagon"></i> Allergies : <b><?= e($p['allergies']) ?></b></div><?php endif; ?>
  <?php if ($v['reason']): ?><div class="small"><span class="text-secondary">Motif :</span> <b><?= e($v['reason']) ?></b></div><?php endif; ?>
  <?php if ($lastAlerts): ?><div class="small text-danger fw-semibold"><i class="bi bi-activity"></i> <?= e(implode(' · ', $lastAlerts)) ?></div><?php endif; ?>
</div></div>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card mb-3"><div class="card-header d-flex align-items-center"><span><i class="bi bi-journal-medical"></i> Observation médicale</span>
      <?php if ($v['status'] === 'waiting' && can('clinique.doctor')): ?><form class="ms-auto" method="post" action="<?= e(url('/clinique/consultations/' . $v['id'] . '/start')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-primary"><i class="bi bi-play-fill"></i> Commencer la consultation</button></form><?php endif; ?>
      <?php if ($v['practitioner']): ?><span class="ms-auto small text-secondary">Dr <?= e($v['practitioner']) ?></span><?php endif; ?>
    </div><div class="card-body">
      <?php if ($editable): ?>
      <form method="post" action="<?= e(url('/clinique/consultations/' . $v['id'])) ?>" class="row g-2"><?= csrf_field() ?>
        <div class="col-md-6"><label class="form-label small mb-0">Interrogatoire / histoire de la maladie</label><textarea name="anamnesis" rows="4" class="form-control"><?= e($v['anamnesis'] ?? '') ?></textarea></div>
        <div class="col-md-6"><label class="form-label small mb-0">Examen clinique</label><textarea name="exam" rows="4" class="form-control"><?= e($v['exam'] ?? '') ?></textarea></div>
        <div class="col-12"><label class="form-label small mb-0">Diagnostic *</label><input name="diagnosis" value="<?= e($v['diagnosis'] ?? '') ?>" class="form-control" maxlength="500" placeholder="ex. Paludisme simple"></div>
        <div class="col-12"><label class="form-label small mb-0">Conduite à tenir / conclusion</label><textarea name="conclusion" rows="2" class="form-control"><?= e($v['conclusion'] ?? '') ?></textarea></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-outline-primary" name="close" value="0"><i class="bi bi-save"></i> Enregistrer</button><button class="btn btn-success ms-auto" name="close" value="1"><i class="bi bi-check2-circle"></i> Terminer la consultation</button></div>
      </form>
      <?php else: ?>
      <dl class="row mb-0 small">
        <dt class="col-sm-3">Interrogatoire</dt><dd class="col-sm-9"><?= nl2br(e($v['anamnesis'] ?? '—')) ?></dd>
        <dt class="col-sm-3">Examen</dt><dd class="col-sm-9"><?= nl2br(e($v['exam'] ?? '—')) ?></dd>
        <dt class="col-sm-3">Diagnostic</dt><dd class="col-sm-9 fw-semibold"><?= e($v['diagnosis'] ?? '—') ?></dd>
        <dt class="col-sm-3">Conclusion</dt><dd class="col-sm-9"><?= nl2br(e($v['conclusion'] ?? '—')) ?></dd>
      </dl>
      <?php endif; ?>
    </div></div>

    <div class="card mb-3"><div class="card-header"><i class="bi bi-heart-pulse"></i> Constantes</div>
      <?php if (can('clinique.nurse') && $v['status'] !== 'cancelled'): ?><div class="card-body border-bottom"><form method="post" action="<?= e(url('/clinique/consultations/' . $v['id'] . '/vitals')) ?>"><?= csrf_field() ?><?= App\Core\View::partial('clinique::_vitals', ['full' => true, 'withNote' => true]) ?><button class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-plus"></i> Ajouter le relevé</button></form></div><?php endif; ?>
      <?= App\Core\View::partial('clinique::_vitals_table', ['vitals' => $vitals]) ?>
    </div>

    <div class="card mb-3"><div class="card-header"><i class="bi bi-capsule"></i> Ordonnance(s)</div>
      <?php if ($prescriptions): ?><ul class="list-group list-group-flush small"><?php foreach ($prescriptions as $pr): ?><li class="list-group-item d-flex align-items-center gap-2"><b><?= e($pr['number']) ?></b> <?= e(fdate($pr['created_at'])) ?> <?= App\Core\View::partial('clinique::_presc_badge', ['st' => $pr['status']]) ?><a class="btn btn-sm btn-outline-secondary ms-auto" target="_blank" rel="noopener" href="<?= e(url('/clinique/ordonnances/' . $pr['id'] . '/print')) ?>"><i class="bi bi-printer"></i> Imprimer</a></li><?php endforeach; ?></ul><?php endif; ?>
      <?php if (can('clinique.doctor') && $v['status'] !== 'cancelled'): ?><div class="card-body"><?= App\Core\View::partial('clinique::_drugs', ['action' => url('/clinique/consultations/' . $v['id'] . '/prescription'), 'drugs' => $drugs]) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="col-xl-4">
    <?= App\Core\View::partial('clinique::_imaging', ['p' => $p, 'visitId' => (int) $v['id'], 'imgStudies' => $imgStudies, 'imgRequests' => $imgRequests]) ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-receipt"></i> Facturation</div><div class="card-body">
      <?php if ($bill): ?>
        <div>Facture <b><?= e($bill['number']) ?></b> : <?= money($bill['subtotal']) ?><?= (float) $bill['coverage_amount'] > 0 ? '<br><span class="small text-secondary">Part patient ' . money($bill['patient_amount']) . '</span>' : '' ?></div>
        <div class="mt-1"><span class="badge bg-<?= $bill['status'] === 'paid' ? 'success' : 'warning text-dark' ?>"><?= $bill['status'] === 'paid' ? 'Payée' : 'À encaisser à l\'accueil' ?></span></div>
        <?php if (can('clinique.reception')): ?><a class="btn btn-sm btn-outline-primary mt-2" href="<?= e(url('/clinique/factures/' . $bill['id'])) ?>">Ouvrir la facture</a><?php endif; ?>
      <?php elseif ((can('clinique.doctor') || can('clinique.reception')) && $v['status'] !== 'cancelled'): ?>
        <form method="post" action="<?= e(url('/clinique/consultations/' . $v['id'] . '/bill')) ?>"><?= csrf_field() ?>
          <?= App\Core\View::partial('clinique::_acts', ['acts' => $acts, 'name' => 'acts', 'preselect' => array_map('intval', array_column(array_slice(array_values(array_filter($acts, fn($a) => $a['category'] === 'Consultation')), 0, 1), 'id'))]) ?>
          <?php if ((float) $p['coverage_pct'] > 0): ?><div class="small text-secondary mt-1">Prise en charge <?= e($p['insurer'] ?? '') ?> : <?= e((string) (float) $p['coverage_pct']) ?> %</div><?php endif; ?>
          <button class="btn btn-warning w-100 mt-2"><i class="bi bi-receipt"></i> Facturer les actes</button>
        </form>
      <?php else: ?><span class="text-secondary small">Non facturée.</span><?php endif; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header">Consultations précédentes</div><ul class="list-group list-group-flush small">
      <?php foreach ($previous as $pv): ?><li class="list-group-item"><a href="<?= e(url('/clinique/consultations/' . $pv['id'])) ?>"><?= e(fdate($pv['created_at'], false)) ?></a> — <?= e($pv['diagnosis'] ?? '') ?></li><?php endforeach; ?>
      <?php if (!$previous): ?><li class="list-group-item text-secondary">Première consultation.</li><?php endif; ?>
    </ul></div>
    <?php if ($p['history']): ?><div class="card mb-3"><div class="card-header">Antécédents</div><div class="card-body small"><?= nl2br(e($p['history'])) ?></div></div><?php endif; ?>
  </div>
</div>
