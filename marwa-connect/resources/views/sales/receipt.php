<?php $company = (string) setting('company.name', 'MARWA CONNECT'); ?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reçu <?= e($sale['reference']) ?></title><link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>">
<style>@page { size: 80mm auto; margin: 3mm; } .receipt { width: 74mm; margin: 10px auto; background: #fff; padding: 3mm; font-size: 11px; }
.receipt h1 { font-size: 15px; text-align: center; margin: 0; color: #D7192A; } .c { text-align: center; } .line { display: flex; justify-content: space-between; }
hr { border: 0; border-top: 1px dashed #999; margin: 2mm 0; } .tkr { border: 1px dashed #666; border-radius: 2mm; padding: 2mm; margin: 2mm 0; }
.tkr .code { font-family: Consolas, monospace; font-size: 14px; font-weight: 800; }</style></head>
<body class="thermal">
<div class="toolbar no-print"><strong>Reçu <?= e($sale['reference']) ?></strong><button type="button" id="do-print">Imprimer</button></div>
<div class="receipt">
    <h1><?= e(mb_strtoupper($company)) ?></h1>
    <div class="c"><?= e(setting('company.address', '')) ?> <?= e(setting('company.phone', '')) ?></div>
    <hr>
    <div class="line"><span>Reçu</span><strong><?= e($sale['reference']) ?></strong></div>
    <div class="line"><span>Date</span><span><?= e(date_fr($sale['created_at'])) ?></span></div>
    <div class="line"><span>Caissier</span><span><?= e($sale['cashier'] ?? '—') ?></span></div>
    <?php if ($sale['customer_phone']): ?><div class="line"><span>Client</span><span><?= e($sale['customer_phone']) ?></span></div><?php endif; ?>
    <hr>
    <?php foreach ($tickets as $t): ?>
        <div class="tkr">
            <div class="line"><strong><?= e($t['offer']) ?></strong><span><?= e($t['price']) ?></span></div>
            <?php if ($t['same']): ?><div class="line"><span>Code</span><span class="code"><?= e($t['username']) ?></span></div>
            <?php else: ?><div class="line"><span>Identifiant</span><span class="code"><?= e($t['username']) ?></span></div>
                <div class="line"><span>Mot de passe</span><span class="code"><?= e($t['password']) ?></span></div><?php endif; ?>
            <div class="line"><span>Durée</span><span><?= e($t['duration']) ?></span></div>
            <?php if ($t['ssid']): ?><div class="line"><span>Wi-Fi</span><span><?= e($t['ssid']) ?></span></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <hr>
    <?php if ((float) $sale['discount'] > 0): ?><div class="line"><span>Remise</span><span>− <?= e(money($sale['discount'])) ?></span></div><?php endif; ?>
    <div class="line" style="font-size:14px"><strong>TOTAL</strong><strong><?= e(money($sale['total'])) ?></strong></div>
    <div class="line"><span>Paiement</span><span><?= e($sale['method'] ?? '') ?></span></div>
    <?php if ($sale['status'] !== 'completed'): ?><p class="c"><strong>VENTE <?= e(mb_strtoupper($sale['status'])) ?></strong></p><?php endif; ?>
    <hr>
    <div class="c"><?= e(setting('tickets.login_instructions', '')) ?></div>
    <p class="c"><?= e(setting('sales.receipt_footer', 'Merci !')) ?></p>
</div>
<script src="<?= e(asset('js/print.js')) ?>"></script>
</body></html>
