<?php use Addons\Clinique\ClinicService as CS; ?>
<?php if (!empty($_GET['auto'])): ?><span data-autoprint></span><?php endif; ?>
<div class="sheet">
  <?= App\Core\View::partial('partials/doc_head', ['docTitle' => 'ORDONNANCE', 'docNumber' => $pr['number'], 'docDate' => $pr['created_at']]) ?>
  <div class="box" style="margin-top:12px">
    <b>Patient</b> : <?= e($pr['last_name'] . ' ' . $pr['first_name']) ?> · <?= e($pr['sex']) ?><?= $pr['birth_date'] ? ' · ' . e(CS::age($pr['birth_date'])) : '' ?> · Dossier <?= e($pr['pnum']) ?>
    <?php if ($pr['allergies']): ?><br><b>Allergies</b> : <?= e($pr['allergies']) ?><?php endif; ?>
    <br><b>Prescripteur</b> : Dr <?= e($pr['prescriber']) ?>
  </div>
  <table class="lines" style="margin-top:12px"><thead><tr><th style="width:5%">#</th><th>Médicament</th><th>Posologie</th><th>Durée</th><th class="num">Qté</th></tr></thead><tbody>
  <?php foreach ($items as $n => $i): ?><tr><td><?= $n + 1 ?></td><td><b><?= e($i['drug_name']) ?></b><?= $i['product_id'] ? '' : ' <i>(hors stock)</i>' ?></td><td><?= e($i['posology'] ?? '') ?></td><td><?= e($i['duration'] ?? '') ?></td><td class="num"><?= (int) $i['quantity'] ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php if ($pr['note']): ?><p>Note : <?= e($pr['note']) ?></p><?php endif; ?>
  <?php if ($pr['status'] === 'cancelled'): ?><p style="font-size:20px;font-weight:bold;color:#c00">ANNULÉE</p><?php endif; ?>
  <div class="sign"><div></div><div>Signature et cachet du médecin</div></div>
  <div style="margin-top:18px;text-align:center"><div data-qr="<?= e($pr['number']) ?>" data-qr-size="3" class="qr" style="display:inline-block"></div></div>
</div>
