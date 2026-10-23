<?php use Addons\Clinique\ImagingService as IS; use Addons\Clinique\ClinicService as CS; ?>
<link rel="stylesheet" href="<?= e(asset('addons/clinique/imagerie.css')) ?>">
<?= App\Core\View::partial('partials/doc_head', ['docTitle' => 'COMPTE RENDU D\'IMAGERIE', 'docNumber' => 'EX-' . $s['id'], 'docDate' => $s['study_date'] ?: $s['created_at']]) ?>
<?php if ($s['status'] !== 'valide'): ?><p style="border:2px solid #c60;color:#c60;text-align:center;font-weight:700;padding:4px">PROVISOIRE — compte rendu non validé</p><?php endif; ?>
<table style="width:100%;font-size:13px;margin-bottom:8px">
  <tr><td><b>Patient :</b> <?= $s['patient_id'] ? e($s['last_name'] . ' ' . $s['first_name']) . ' (' . e($s['patient_number']) . ')' : e(t('non rattaché')) ?><?= $s['sex'] ? ' — ' . e($s['sex']) : '' ?><?= $s['birth_date'] ? ', ' . e(CS::age($s['birth_date'])) : '' ?></td>
  <td style="text-align:right"><b>Examen :</b> <?= e(IS::MODALITIES[$s['modality']] ?? (string) $s['modality']) ?><?= $s['description'] ? ' — ' . e($s['description']) : '' ?></td></tr>
  <?php if ($s['request_exam']): ?><tr><td colspan="2"><b>Demande :</b> <?= e($s['request_number']) ?> — <?= e($s['request_exam']) ?><?= $s['clinical_info'] ? ' — ' . e($s['clinical_info']) : '' ?> (<?= e((string) $s['requester']) ?>)</td></tr><?php endif; ?>
</table>
<?php if ($s['technique']): ?><h4 style="margin:8px 0 2px">Technique</h4><div><?= nl2br(e($s['technique'])) ?></div><?php endif; ?>
<h4 style="margin:8px 0 2px">Résultats</h4><div><?= $s['findings'] ? nl2br(e($s['findings'])) : '<i>—</i>' ?></div>
<h4 style="margin:8px 0 2px">Conclusion</h4><div style="font-weight:700"><?= $s['conclusion'] ? nl2br(e($s['conclusion'])) : '<i>—</i>' ?></div>
<?php if ($images): ?><div class="img-print-grid"><?php foreach ($images as $i): ?><img src="<?= e(url('/clinique/imagerie/images/' . $i['id'] . '/miniature', ['t' => 512])) ?>" alt=""><?php endforeach; ?></div><?php endif; ?>
<div style="display:flex;justify-content:space-between;margin-top:24px;font-size:12px">
  <div><?= $s['reported_at'] ? 'Rédigé par ' . e((string) $s['reporter']) . ' le ' . e(fdate($s['reported_at'])) : '' ?><?= $s['validated_at'] ? '<br>Validé par ' . e((string) $s['validator']) . ' le ' . e(fdate($s['validated_at'])) : '' ?></div>
  <div style="border-top:1px solid #000;width:40%;text-align:center;padding-top:4px">Signature du médecin</div>
</div>
