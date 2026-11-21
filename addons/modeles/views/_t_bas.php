<?php /* Modèles de documents — bas des tickets : paiements, solde client, signature, conditions, pied, QR. */ ?>
<?php foreach ($doc['payments'] ?? [] as $r): ?><div class="m-row <?= !empty($r[2]) ? 'b' : '' ?>"><span><?= e($r[0]) ?></span><span><?= e($r[1]) ?></span></div><?php endforeach; ?>
<?php if ($o['solde_client'] === '1' && ($doc['customer_balance'] ?? null) !== null && !empty($doc['customer'])): ?>
  <div class="m-sep"></div>
  <div class="m-solde"><span><?= e(t('SOLDE CRÉANCE')) ?> :</span><span><?= money($doc['customer_balance']) ?></span></div>
  <div class="m-sub"><?= e(t('Montant total dû par le client à la date d\'impression')) ?></div>
<?php endif; ?>
<?php if ($o['signature'] === '1'): ?><div class="m-sign"><div><?= e(t('Signature du client')) ?></div><div><?= e(t('Le vendeur')) ?></div></div><?php endif; ?>
<?= App\Core\View::partial('documents/_fiscal', ['doc' => $doc]) ?>
<?php if ($o['conditions'] !== ''): ?><div class="m-cond"><?= nl2br(e($o['conditions'])) ?></div><?php endif; ?>
<?php if (trim((string) $doc['footer']) !== ''): ?><div class="m-merci">★ <?= nl2br(e($doc['footer'])) ?> ★</div><?php endif; ?>
<?php if ($o['pied2'] !== ''): ?><div class="m-contact"><?= e($o['pied2']) ?></div><?php endif; ?>
<?php if (!empty($doc['qr'])): ?><div class="c qr-box"><div data-qr="<?= e($doc['qr']) ?>" data-qr-size="3" class="qr"></div><div class="small"><?= e($doc['number']) ?></div></div><?php endif; ?>
<?php if ($o['nb_articles'] === '1'): ?><div class="m-count"><?= e(t('Articles')) ?> : <?= qty($doc['count'] ?? 0) ?></div><?php endif; ?>
