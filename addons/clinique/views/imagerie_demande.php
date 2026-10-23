<?php use Addons\Clinique\ImagingService as IS; use Addons\Clinique\ClinicService as CS; ?>
<link rel="stylesheet" href="<?= e(asset('addons/clinique/imagerie.css')) ?>">
<?= App\Core\View::partial('partials/doc_head', ['docTitle' => 'DEMANDE D\'EXAMEN D\'IMAGERIE', 'docNumber' => $r['number'], 'docDate' => $r['created_at']]) ?>
<?php if ($r['priority'] === 'urgent'): ?><p style="border:2px solid #c00;color:#c00;text-align:center;font-weight:700;padding:4px">URGENT</p><?php endif; ?>
<table style="width:100%;border-collapse:collapse;font-size:13px">
  <tr><td style="width:40%;padding:4px;border-bottom:1px solid #ccc">Patient</td><td style="padding:4px;border-bottom:1px solid #ccc"><b><?= e($r['last_name'] . ' ' . $r['first_name']) ?></b> — <?= e($r['sex']) ?><?= $r['birth_date'] ? ', ' . e(CS::age($r['birth_date'])) . ' (' . e(fdate($r['birth_date'], false)) . ')' : '' ?></td></tr>
  <tr><td style="padding:4px;border-bottom:1px solid #ccc">N° de dossier (Patient ID à saisir dans l'appareil)</td><td style="padding:4px;border-bottom:1px solid #ccc;font:700 18px monospace"><?= e($r['patient_number']) ?></td></tr>
  <tr><td style="padding:4px;border-bottom:1px solid #ccc">N° de demande (Accession Number)</td><td style="padding:4px;border-bottom:1px solid #ccc;font:700 18px monospace"><?= e($r['number']) ?></td></tr>
  <tr><td style="padding:4px;border-bottom:1px solid #ccc">Examen demandé</td><td style="padding:4px;border-bottom:1px solid #ccc"><b><?= e(IS::MODALITIES[$r['modality']] ?? $r['modality']) ?></b> — <?= e($r['exam']) ?></td></tr>
  <?php if ($r['clinical_info']): ?><tr><td style="padding:4px;border-bottom:1px solid #ccc">Renseignements cliniques</td><td style="padding:4px;border-bottom:1px solid #ccc"><?= nl2br(e($r['clinical_info'])) ?></td></tr><?php endif; ?>
  <tr><td style="padding:4px">Demandé par</td><td style="padding:4px"><?= e($r['requester']) ?></td></tr>
</table>
<p style="font-size:11px;margin-top:14px">Au poste d'imagerie : saisissez le n° de dossier ou le n° de demande dans l'appareil ; les images arriveront directement dans le dossier du patient.</p>
<div style="display:flex;justify-content:flex-end;margin-top:30px"><div style="border-top:1px solid #000;width:45%;text-align:center;font-size:12px;padding-top:4px">Signature et cachet du médecin</div></div>
