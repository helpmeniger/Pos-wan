<link rel="stylesheet" href="<?= e(asset('addons/clinique/clinique.css')) ?>">
<?php
use Addons\Clinique\ClinicService as CS;
use Addons\Clinique\HospitalService as HS;
$open = $s['status'] === 'admitted';
$printp = (int) ($_GET['printp'] ?? 0);
$mins = fn(int $m) => $m >= 60 ? intdiv($m, 60) . ' h ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) : $m . ' min';
?>
<?php if ($printp && in_array($printp, array_map('intval', array_column($prescriptions, 'id')), true)): ?><span data-autoprint-url="<?= e(url('/clinique/ordonnances/' . $printp . '/print', ['auto' => 1])) ?>"></span><?php endif; ?>
<script type="application/json" id="clin-chart"><?= json_encode(array_map(fn($v) => ['at' => substr($v['taken_at'], 5, 11), 't' => $v['temperature'] !== null ? (float) $v['temperature'] : null, 'p' => $v['pulse'] !== null ? (int) $v['pulse'] : null, 'sys' => $v['systolic'] !== null ? (int) $v['systolic'] : null], $chart), JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <a class="btn btn-outline-secondary" href="<?= e(url('/clinique/hospitalisation')) ?>"><i class="bi bi-arrow-left"></i> Hospitalisation</a>
  <a class="btn btn-outline-primary" href="<?= e(url('/clinique/patients/' . $p['id'])) ?>"><i class="bi bi-folder2-open"></i> Dossier</a>
  <?php if (can('clinique.nurse')): ?><a class="btn btn-outline-danger" href="<?= e(url('/clinique/supervision')) ?>"><i class="bi bi-display"></i> Supervision</a><?php endif; ?>
  <span class="ms-auto"><?= $s['kind'] === 'observation' ? '<span class="badge bg-warning text-dark fs-6">Observation</span>' : '<span class="badge bg-danger fs-6">Hospitalisation</span>' ?> <?= $open ? '<span class="badge bg-success fs-6">Présent · J' . $days . '</span>' : '<span class="badge bg-secondary fs-6">Sorti : ' . e(HS::DISCHARGE[$s['discharge_mode']] ?? '') . '</span>' ?></span>
</div>
<div class="card mb-3 clin-patient-bar"><div class="card-body py-2 d-flex flex-wrap gap-3 align-items-center">
  <div class="clin-avatar <?= $p['sex'] === 'F' ? 'f' : 'm' ?>"><?= e(mb_substr($p['last_name'], 0, 1) . mb_substr($p['first_name'], 0, 1)) ?></div>
  <div><div class="fw-bold fs-5"><?= e($p['last_name'] . ' ' . $p['first_name']) ?></div><div class="small text-secondary"><?= e($p['number']) ?> · <?= e(implode(' · ', array_filter([$p['sex'], CS::age($p['birth_date'])]))) ?><?= $p['blood_group'] ? ' · ' . e($p['blood_group']) : '' ?> · <?= e($s['number']) ?> · <?= e($s['room'] . ' / ' . $s['bed']) ?> · entré le <?= e(fdate($s['admitted_at'])) ?><?= $s['doctor'] ? ' · Dr ' . e($s['doctor']) : '' ?></div></div>
  <?php if ($p['allergies']): ?><div class="alert alert-danger py-1 px-2 mb-0 small"><i class="bi bi-exclamation-octagon"></i> Allergies : <b><?= e($p['allergies']) ?></b></div><?php endif; ?>
  <div class="small"><span class="text-secondary">Motif :</span> <b><?= e($s['reason']) ?></b><?= $s['diagnosis'] ? '<br><span class="text-secondary">Diagnostic :</span> <b>' . e($s['diagnosis']) . '</b>' : '' ?></div>
</div></div>
<?php if ($alerts && $open): ?><div class="alert alert-danger py-2"><i class="bi bi-activity"></i> <b>Alerte sur le dernier relevé</b> : <?= e(implode(' · ', $alerts)) ?></div><?php endif; ?>
<?php if (!$open): ?><div class="alert alert-secondary py-2">Sorti le <?= e(fdate($s['discharged_at'])) ?> — <?= e(HS::DISCHARGE[$s['discharge_mode']] ?? '') ?><?= $s['discharge_note'] ? ' : ' . e($s['discharge_note']) : '' ?><?php if ($s['bill_id'] && can('clinique.reception')): ?> · <a href="<?= e(url('/clinique/factures/' . $s['bill_id'])) ?>">facture du séjour</a><?php endif; ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-list-check"></i> Plan de soins</div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Soin / médicament</th><th>Fréquence</th><th>Prochaine prise</th><th></th></tr></thead><tbody>
      <?php foreach ($care as $c): $late = (int) $c['active'] && (int) $c['in_min'] < 0; $soon = (int) $c['active'] && !$late && (int) $c['in_min'] <= 60; ?>
        <tr class="<?= !(int) $c['active'] ? 'text-secondary' : ($late ? 'table-danger' : ($soon ? 'table-warning' : '')) ?>">
          <td><span class="badge bg-light text-dark border"><?= e(HS::CARE_KINDS[$c['kind']]) ?></span> <b><?= e($c['label']) ?></b><?= $c['dose'] ? ' · ' . e($c['dose']) : '' ?><?= $c['route'] ? ' · ' . e($c['route']) : '' ?><br><small class="text-secondary"><?= e($c['prescriber'] ?? '') ?><?= $c['end_at'] ? ' · jusqu\'au ' . e(fdate($c['end_at'])) : '' ?></small></td>
          <td class="small">toutes les <?= (int) $c['every_hours'] ?> h</td>
          <td class="small"><?php if ((int) $c['active']): ?><b><?= e(fdate($c['next_due_at'])) ?></b><br><?= $late ? '<span class="text-danger fw-semibold">en retard de ' . e($mins(-(int) $c['in_min'])) . '</span>' : 'dans ' . e($mins((int) $c['in_min'])) ?><?php else: ?>arrêté<?php endif; ?></td>
          <td class="text-end text-nowrap"><?php if ((int) $c['active'] && $open): ?>
            <?php if (can('clinique.nurse')): ?>
            <form class="d-inline" method="post" action="<?= e(url('/clinique/soins/' . $c['id'] . '/log')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="done"><button class="btn btn-sm btn-success" title="Soin effectué"><i class="bi bi-check2"></i> Fait</button></form>
            <form class="d-inline" method="post" action="<?= e(url('/clinique/soins/' . $c['id'] . '/log')) ?>" data-confirm="Le patient a refusé ce soin ?" data-reason="1"><?= csrf_field() ?><input type="hidden" name="status" value="refused"><button class="btn btn-sm btn-outline-warning" title="Refusé (motif demandé)"><i class="bi bi-hand-thumbs-down"></i></button></form>
            <?php endif; ?>
            <?php if (can('clinique.doctor')): ?><form class="d-inline" method="post" action="<?= e(url('/clinique/soins/' . $c['id'] . '/stop')) ?>" data-confirm="Arrêter ce soin ?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" title="Arrêter"><i class="bi bi-stop-circle"></i></button></form><?php endif; ?>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$care): ?><tr><td colspan="4" class="text-center text-secondary py-3">Aucun soin prescrit.</td></tr><?php endif; ?>
      </tbody></table></div>
      <?php if ($open && can('clinique.doctor')): ?>
      <div class="card-body border-top"><form method="post" action="<?= e(url('/clinique/sejours/' . $s['id'] . '/soins')) ?>" class="row g-2 align-items-end"><?= csrf_field() ?>
        <div class="col-md-2"><label class="form-label small mb-0">Type</label><select name="kind" class="form-select form-select-sm"><?php foreach (HS::CARE_KINDS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small mb-0">Soin / médicament *</label><input name="label" class="form-control form-control-sm" required maxlength="150" list="clinDrugList2" placeholder="ex. Artésunate inj."></div>
        <div class="col-md-2"><label class="form-label small mb-0">Dose</label><input name="dose" class="form-control form-control-sm" maxlength="100" placeholder="ex. 120 mg"></div>
        <div class="col-md-1"><label class="form-label small mb-0">Voie</label><input name="route" class="form-control form-control-sm" maxlength="50" list="clinRoutes" placeholder="IV"></div>
        <div class="col-md-1"><label class="form-label small mb-0">Toutes les (h)</label><input name="every_hours" value="8" class="form-control form-control-sm" inputmode="numeric" required></div>
        <div class="col-md-1"><label class="form-label small mb-0">Pendant (j)</label><input name="days" class="form-control form-control-sm" inputmode="numeric" placeholder="∞"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-plus"></i> Ajouter</button></div>
        <datalist id="clinRoutes"><option value="Per os"><option value="IV"><option value="IM"><option value="SC"><option value="Perfusion"><option value="Locale"></datalist>
        <datalist id="clinDrugList2"><?php foreach ($drugs as $d): ?><option value="<?= e($d['name'] . ($d['dosage'] ? ' ' . $d['dosage'] : '')) ?>"><?php endforeach; ?></datalist>
        <div class="col-12 small text-secondary">Le premier soin est dû maintenant ; les suivants sont calculés automatiquement et affichés sur l'écran de supervision.</div>
      </form></div>
      <?php endif; ?>
    </div>

    <div class="card mb-3"><div class="card-header d-flex"><span><i class="bi bi-heart-pulse"></i> Surveillance des constantes</span></div>
      <div class="card-body border-bottom"><canvas id="clinChart" height="160" class="w-100"></canvas><div class="small text-secondary"><span class="clin-key t"></span> T° (°C) <span class="clin-key p"></span> Pouls <span class="clin-key s"></span> TA systolique</div></div>
      <?php if ($open && can('clinique.nurse')): ?><div class="card-body border-bottom"><form method="post" action="<?= e(url('/clinique/sejours/' . $s['id'] . '/vitals')) ?>"><?= csrf_field() ?><?= App\Core\View::partial('clinique::_vitals', ['full' => true, 'withNote' => true]) ?><button class="btn btn-sm btn-primary mt-2"><i class="bi bi-plus"></i> Enregistrer le relevé</button></form></div><?php endif; ?>
      <?= App\Core\View::partial('clinique::_vitals_table', ['vitals' => $vitals]) ?>
    </div>

    <div class="card mb-3"><div class="card-header"><i class="bi bi-capsule"></i> Ordonnances (pharmacie)</div>
      <?php if ($prescriptions): ?><ul class="list-group list-group-flush small"><?php foreach ($prescriptions as $pr): ?><li class="list-group-item d-flex align-items-center gap-2"><b><?= e($pr['number']) ?></b> <?= e(fdate($pr['created_at'])) ?> <?= App\Core\View::partial('clinique::_presc_badge', ['st' => $pr['status']]) ?><a class="btn btn-sm btn-outline-secondary ms-auto" target="_blank" rel="noopener" href="<?= e(url('/clinique/ordonnances/' . $pr['id'] . '/print')) ?>"><i class="bi bi-printer"></i></a></li><?php endforeach; ?></ul><?php endif; ?>
      <?php if ($open && can('clinique.doctor')): ?><div class="card-body"><?= App\Core\View::partial('clinique::_drugs', ['action' => url('/clinique/sejours/' . $s['id'] . '/prescription'), 'drugs' => $drugs]) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-chat-left-text"></i> Transmissions</div>
      <?php if ($open && can('clinique.nurse')): ?><div class="card-body border-bottom"><form method="post" action="<?= e(url('/clinique/sejours/' . $s['id'] . '/note')) ?>"><?= csrf_field() ?>
        <textarea name="note" rows="2" class="form-control form-control-sm" required placeholder="Observation, évolution, événement…"></textarea>
        <div class="d-flex gap-2 mt-1 align-items-center"><?php if (can('clinique.doctor')): ?><select name="kind" class="form-select form-select-sm w-auto"><option value="nurse">Note infirmière</option><option value="doctor">Note médicale</option></select><?php endif; ?><button class="btn btn-sm btn-outline-primary ms-auto">Ajouter</button></div>
      </form></div><?php endif; ?>
      <ul class="list-group list-group-flush small clin-notes">
        <?php foreach ($notes as $n): ?><li class="list-group-item"><span class="badge bg-<?= $n['kind'] === 'doctor' ? 'primary' : 'info text-dark' ?>"><?= $n['kind'] === 'doctor' ? 'Médecin' : 'Infirmier' ?></span> <span class="text-secondary"><?= e(fdate($n['created_at'])) ?> · <?= e($n['full_name']) ?></span><br><?= nl2br(e($n['note'])) ?></li><?php endforeach; ?>
        <?php if (!$notes): ?><li class="list-group-item text-secondary">Aucune transmission.</li><?php endif; ?>
      </ul>
    </div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-clock-history"></i> Soins effectués</div><ul class="list-group list-group-flush small clin-notes">
      <?php foreach ($careLog as $l): ?><li class="list-group-item"><?= e(fdate($l['done_at'])) ?> · <b><?= e($l['label']) ?></b><?= $l['dose'] ? ' ' . e($l['dose']) : '' ?> · <?= e(['done' => 'fait', 'refused' => 'refusé', 'skipped' => 'non fait'][$l['status']]) ?> · <span class="text-secondary"><?= e($l['full_name']) ?></span><?= $l['note'] ? '<br><i>' . e($l['note']) . '</i>' : '' ?></li><?php endforeach; ?>
      <?php if (!$careLog): ?><li class="list-group-item text-secondary">Aucun.</li><?php endif; ?>
    </ul></div>
    <?php if ($open && can('clinique.nurse') && $beds): ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-arrow-left-right"></i> Changer de lit</div><div class="card-body"><form method="post" action="<?= e(url('/clinique/sejours/' . $s['id'] . '/transfer')) ?>" class="d-flex gap-2"><?= csrf_field() ?>
      <select name="bed_id" class="form-select form-select-sm" required><option value="">Lit libre…</option><?php foreach ($beds as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['room'] . ' — ' . $b['name']) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline-primary">Transférer</button></form></div></div>
    <?php endif; ?>
    <?php if ($open && can('clinique.doctor')): ?>
    <div class="card mb-3 border-success"><div class="card-header bg-success text-white"><i class="bi bi-door-open"></i> Sortie du patient</div><div class="card-body">
      <form method="post" action="<?= e(url('/clinique/sejours/' . $s['id'] . '/discharge')) ?>" data-confirm="Confirmer la sortie du patient ? Le séjour sera facturé (<?= $days ?> jour(s) × <?= e(money($s['daily_price'])) ?>) et le plan de soins arrêté."><?= csrf_field() ?>
        <label class="form-label small mb-0">Mode de sortie *</label>
        <select name="mode" class="form-select form-select-sm mb-2" required><?php foreach (HS::DISCHARGE as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
        <textarea name="note" rows="2" class="form-control form-control-sm mb-2" placeholder="Compte rendu de sortie, consignes, rendez-vous de contrôle…"></textarea>
        <div class="small fw-semibold">Actes à ajouter à la facture</div>
        <?= App\Core\View::partial('clinique::_acts', ['acts' => array_values(array_filter($acts, fn($a) => $a['category'] !== 'Hospitalisation')), 'name' => 'acts']) ?>
        <div class="small text-secondary mt-1">Séjour : <?= $days ?> jour(s) × <?= money($s['daily_price']) ?> = <b><?= money($days * (float) $s['daily_price']) ?></b> (ajouté automatiquement)</div>
        <button class="btn btn-success w-100 mt-2"><i class="bi bi-door-open"></i> Enregistrer la sortie et facturer</button>
      </form>
    </div></div>
    <?php endif; ?>
  </div>
</div>
