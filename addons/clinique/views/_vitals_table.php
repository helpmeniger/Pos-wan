<?php /* Tableau des relevés de constantes (alertes surlignées). $vitals */ use Addons\Clinique\ClinicService as CS; ?>
<div class="table-responsive"><table class="table table-sm align-middle mb-0 clin-vt"><thead><tr><th>Date</th><th class="num">T°</th><th class="num">TA</th><th class="num">Pouls</th><th class="num">SpO₂</th><th class="num">FR</th><th class="num">Glyc.</th><th class="num">Poids</th><th class="num">Douleur</th><th>Par</th></tr></thead><tbody>
<?php foreach ($vitals as $v): $al = CS::alerts($v); ?>
  <tr class="<?= $al ? 'table-danger' : '' ?>" title="<?= e(implode(' · ', $al)) ?>">
    <td class="small text-nowrap"><?= e(fdate($v['taken_at'])) ?></td>
    <td class="num"><?= $v['temperature'] !== null ? e((string) (float) $v['temperature']) : '' ?></td>
    <td class="num"><?= $v['systolic'] !== null ? (int) $v['systolic'] . '/' . (int) $v['diastolic'] : '' ?></td>
    <td class="num"><?= e((string) ($v['pulse'] ?? '')) ?></td>
    <td class="num"><?= $v['spo2'] !== null ? (int) $v['spo2'] . ' %' : '' ?></td>
    <td class="num"><?= e((string) ($v['resp_rate'] ?? '')) ?></td>
    <td class="num"><?= $v['glycemia'] !== null ? e((string) (float) $v['glycemia']) : '' ?></td>
    <td class="num"><?= $v['weight'] !== null ? e((string) (float) $v['weight']) : '' ?></td>
    <td class="num"><?= e((string) ($v['pain'] ?? '')) ?></td>
    <td class="small text-secondary"><?= e($v['full_name'] ?? '') ?><?= $v['note'] ? '<br>' . e($v['note']) : '' ?></td>
  </tr>
<?php endforeach; ?>
<?php if (!$vitals): ?><tr><td colspan="10" class="text-center text-secondary py-3">Aucun relevé.</td></tr><?php endif; ?>
</tbody></table></div>
