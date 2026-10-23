<?php /** Ligne d'opération (tableaux) : $r, $showAccount */ use Addons\Banques\BankService as BS; $c = (bool) $r['cancelled_at']; ?>
<tr class="<?= $c ? 'text-decoration-line-through text-secondary' : '' ?>">
  <td class="text-nowrap"><?= e(fdate($r['op_date'], false)) ?></td>
  <td class="text-nowrap"><a href="<?= e(url('/banques/operations/' . $r['id'])) ?>"><?= e($r['number']) ?></a></td>
  <?php if (!empty($showAccount)): ?><td><a href="<?= e(url('/banques/comptes/' . $r['account_id'])) ?>"><?= e($r['account_name']) ?></a></td><?php endif; ?>
  <td><?= e(t(BS::TYPES[$r['type']])) ?><?= $r['counter_name'] ? '<br><span class="small text-secondary">' . e(((int) $r['direction'] > 0 ? t('de') : t('vers')) . ' ' . $r['counter_name']) . '</span>' : '' ?></td>
  <?php if (empty($compact)): ?><td class="small"><?= e(t(BS::METHODS[$r['method']] ?? $r['method'])) ?><?= $r['reference'] ? '<br>' . e($r['reference']) : '' ?></td><?php endif; ?>
  <td class="small"><?= e((string) $r['person']) ?><?= $r['description'] ? '<div class="text-secondary">' . e($r['description']) . '</div>' : '' ?><?= $c ? '<div class="text-danger" style="text-decoration:none">' . e(t('Annulée')) . ' : ' . e($r['cancel_reason']) . '</div>' : '' ?></td>
  <?php if (!empty($compact)): ?><td class="num text-nowrap fw-semibold <?= (int) $r['direction'] > 0 ? 'text-success' : 'text-danger' ?>"><?= (int) $r['direction'] > 0 ? '+' : '−' ?> <?= money($r['amount']) ?></td>
  <?php else: ?><td class="num text-success"><?= (int) $r['direction'] > 0 ? money($r['amount']) : '' ?></td>
  <td class="num text-danger"><?= (int) $r['direction'] < 0 ? money($r['amount']) : '' ?></td><?php endif; ?>
  <?php if (array_key_exists('balance_after', $r)): ?><td class="num fw-semibold"><?= $c ? '' : money($r['balance_after']) ?></td><?php endif; ?>
</tr>
