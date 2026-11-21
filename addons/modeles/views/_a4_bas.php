<?php /* Bas d'un document A4 du module : totaux, montant en lettres, solde, conditions, QR, signatures, pied. */ ?>
<?php if ($doc['totals']): ?><table class="m-totaux" style="margin-top:10px"><?php foreach ($doc['totals'] as $r): ?><tr class="<?= !empty($r[2]) ? 'grand' : '' ?>"><td><?= e($r[0]) ?></td><td class="num"><?= e($r[1]) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
<?php if (!empty($doc['words'])): ?><div class="m-words"><?= e($doc['words']) ?></div><?php endif; ?>
<?php if (($doc['credit'] ?? 0) > 0 && $doc['kind'] === 'invoice'): ?><div class="m-solde"><?= e(t('Vente à crédit — reste dû sur cette facture')) ?> : <?= money($doc['credit']) ?></div><?php endif; ?>
<?php if ($o['solde_client'] === '1' && ($doc['customer_balance'] ?? null) !== null && $doc['kind'] === 'invoice'): ?><div class="m-solde"><?= e(t('Solde total dû par le client à ce jour')) ?> : <?= money($doc['customer_balance']) ?></div><?php endif; ?>
<?php if (!empty($doc['notes'])): ?><p class="notes"><?= nl2br(e($doc['notes'])) ?></p><?php endif; ?>
<?php if ($o['conditions'] !== ''): ?><div class="m-cond"><b><?= e(t('Conditions')) ?> :</b> <?= nl2br(e($o['conditions'])) ?></div><?php endif; ?>
<?= App\Core\View::partial('documents/_fiscal', ['doc' => $doc]) ?>
<?php if (!empty($doc['qr'])): ?><div class="qr-a4" style="margin-top:8px"><div data-qr="<?= e($doc['qr']) ?>" data-qr-size="3" class="qr"></div><div class="small"><?= e(t('Référence')) ?> : <?= e($doc['number']) ?></div></div><?php endif; ?>
<?php if (!empty($doc['signatures'])): ?><div class="m-sign"><?php foreach ($doc['signatures'] as $s): ?><div><?= e($s) ?></div><?php endforeach; ?></div><?php endif; ?>
<div class="m-foot"><?= nl2br(e($doc['footer'] ?? '')) ?><?php if ($o['pied2'] !== ''): ?><br><?= e($o['pied2']) ?><?php endif; ?></div>
