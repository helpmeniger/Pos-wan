<?php
use Addons\Hotel\HotelService as HS;
$final = $s['status'] === 'terminee';
$room = array_filter($charges, fn($c) => $c['kind'] === 'nuitee');
$other = array_filter($charges, fn($c) => $c['kind'] !== 'nuitee');
$guestName = trim($guest['last_name'] . ' ' . $guest['first_name']);
?>
<div class="sheet">
  <?= App\Core\View::partial('partials/doc_head', ['docTitle' => $final ? 'FACTURE' : 'NOTE PROVISOIRE', 'docNumber' => $s['invoice_number'] ?: $s['number'], 'docDate' => $s['checked_out_at'] ?: date('Y-m-d H:i:s')]) ?>
  <div style="display:flex;gap:12px;margin-top:12px">
    <div class="box" style="flex:1"><b>Client</b><br><?= e($guestName) ?><?= $s['company'] ? '<br>' . e($s['company']) : '' ?><?= $guest['address'] ? '<br>' . e($guest['address']) : '' ?><?= $guest['phone'] ? '<br>' . e($guest['phone']) : '' ?></div>
    <div class="box" style="flex:1"><b>Séjour <?= e($s['number']) ?></b><br>Chambre <?= e($s['room_number'] ?? '—') ?> (<?= e($s['type_name']) ?>)<br>Du <?= e(fdate($s['arrival'], false)) ?> au <?= e(fdate($s['departure'], false)) ?> · <?= HS::nights($s['arrival'], $s['departure']) ?> nuit(s)<br><?= (int) $s['adults'] ?> adulte(s)<?= (int) $s['children'] ? ', ' . (int) $s['children'] . ' enfant(s)' : '' ?></div>
  </div>
  <?php if (in_array($s['status'], ['annulee', 'no_show'], true)): ?><p style="font-size:18px;font-weight:bold;color:#c00"><?= e(HS::STATUSES[$s['status']]) ?> — <?= e((string) $s['cancel_reason']) ?></p><?php endif; ?>
  <table class="lines" style="margin-top:12px"><thead><tr><th>Date</th><th>Désignation</th><th class="num">Qté</th><th class="num">P.U.</th><th class="num">Montant</th></tr></thead><tbody>
  <?php foreach (array_merge($room, $other) as $c): ?><tr><td><?= e(fdate($c['service_date'], false)) ?></td><td><?= e($c['label']) ?></td><td class="num"><?= e(qty($c['quantity'])) ?></td><td class="num"><?= money($c['unit_price']) ?></td><td class="num"><?= money($c['amount']) ?></td></tr><?php endforeach; ?>
  <?php if (!$final && $bal['pending']['nights'] > 0): ?><tr><td></td><td><i><?= $bal['pending']['nights'] ?> nuitée(s) à venir (estimation)</i></td><td></td><td></td><td class="num"><i><?= money($bal['pending']['amount']) ?></i></td></tr><?php endif; ?>
  </tbody></table>
  <table class="totals">
    <tr><td>Hébergement</td><td class="num"><?= money(array_sum(array_column($room, 'amount'))) ?></td></tr>
    <?php if ($other): ?><tr><td>Autres prestations</td><td class="num"><?= money(array_sum(array_column($other, 'amount'))) ?></td></tr><?php endif; ?>
    <tr class="grand"><td>TOTAL<?= $final ? '' : ' (estimé)' ?></td><td class="num"><?= money($final ? $bal['posted'] : $bal['estimated']) ?></td></tr>
    <tr><td>Payé</td><td class="num"><?= money($bal['paid']) ?></td></tr>
    <tr><td>Reste à payer</td><td class="num"><?= money(max(0, $final ? $bal['due'] : $bal['estimated_due'])) ?></td></tr>
  </table>
  <p>Arrêtée la présente <?= $final ? 'facture' : 'note' ?> à la somme de : <b><?= e(amount_words((float) ($final ? $bal['posted'] : $bal['estimated']))) ?></b>.</p>
  <?php if ($payments): ?><p>Règlements : <?= e(implode(' · ', array_map(fn($pm) => method_label($pm['method']) . ($pm['provider'] ? ' ' . $pm['provider'] : '') . ' ' . money($pm['amount']) . ' (' . fdate($pm['created_at']) . ')', $payments))) ?></p><?php endif; ?>
  <?php if (setting('hotel_conditions')): ?><p style="font-size:11px;color:#555"><?= nl2br(e((string) setting('hotel_conditions'))) ?></p><?php endif; ?>
  <div class="sign"><div>La réception</div><div>Le client</div></div>
</div>
