<?php
use App\Services\TicketPrintService;
$fmt = TicketPrintService::FORMATS[$tpl['format']] ?? TicketPrintService::FORMATS['sheet20'];
$fields = array_flip($tpl['fields']);
$accent = $tpl['options']['accent'] ?? '#D7192A';
[$pw, $ph] = $fmt['page'];
$thermal = str_starts_with($tpl['format'], 'thermal') || $tpl['format'] === 'single';
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Impression de <?= count($tickets) ?> ticket(s) · <?= e(config('app.name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>">
<style>
@page { size: <?= $thermal ? $pw . 'mm auto' : $pw . 'mm ' . $ph . 'mm' ?>; margin: <?= $thermal ? '2mm' : '8mm' ?>; }
.sheet { grid-template-columns: repeat(<?= (int) $fmt['cols'] ?>, 1fr); <?= $thermal ? 'width:' . ($pw - 4) . 'mm;' : '' ?> }
.tk { <?= $thermal ? '' : 'height: calc((' . $ph . 'mm - 16mm) / ' . (int) $fmt['rows'] . ' - 1mm);' ?> --accent: <?= e($accent) ?>; }
<?php if ($fmt['cols'] >= 4): ?>.tk { font-size: 8px; } .tk .code { font-size: 11px; }<?php endif; ?>
</style>
</head>
<body class="<?= $thermal ? 'thermal' : '' ?>">
<div class="toolbar no-print">
    <strong><?= count($tickets) ?> ticket(s)</strong> · <?= e($fmt['label']) ?>
    <button type="button" id="do-print">Imprimer</button>
</div>
<?php if (!$tickets): ?><p class="empty">Aucun ticket à imprimer.</p><?php endif; ?>
<?php foreach (array_chunk($tickets, $thermal ? 1 : $fmt['cols'] * $fmt['rows']) as $page): ?>
<div class="sheet<?= $thermal ? ' one' : '' ?>">
    <?php foreach ($page as $t): ?>
    <div class="tk">
        <?php if (isset($fields['logo'])): ?><div class="tk-head"><?= e(mb_strtoupper($t['company'])) ?></div><?php endif; ?>
        <div class="tk-body">
            <?php if (isset($fields['offer'])): ?><div class="offer"><?= e($t['offer']) ?></div><?php endif; ?>
            <?php if ($t['same'] && isset($fields['username'])): ?>
                <div class="row big"><span>Code</span><span class="code"><?= e($t['username']) ?></span></div>
            <?php else: ?>
                <?php if (isset($fields['username'])): ?><div class="row big"><span>Identifiant</span><span class="code"><?= e($t['username']) ?></span></div><?php endif; ?>
                <?php if (isset($fields['password'])): ?><div class="row big"><span>Mot de passe</span><span class="code"><?= e($t['password']) ?></span></div><?php endif; ?>
            <?php endif; ?>
            <?php if (isset($fields['duration'])): ?><div class="row"><span>Durée</span><span><?= e($t['duration']) ?></span></div><?php endif; ?>
            <?php if (isset($fields['price'])): ?><div class="row"><span>Prix</span><span><?= e($t['price']) ?></span></div><?php endif; ?>
            <?php if (isset($fields['ssid']) && $t['ssid']): ?><div class="row"><span>Wi-Fi</span><span><?= e($t['ssid']) ?></span></div><?php endif; ?>
            <?php if (isset($fields['dns']) && $t['dns']): ?><div class="row"><span>Connexion</span><span><?= e($t['dns']) ?></span></div><?php endif; ?>
            <?php if (isset($fields['instructions']) && $t['instructions'] && $fmt['cols'] < 4): ?><div class="instr"><?= e($t['instructions']) ?></div><?php endif; ?>
            <?php if (isset($fields['serial'])): ?><div class="serial"><?= e($t['serial']) ?></div><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
<script src="<?= e(asset('js/print.js')) ?>"></script>
</body>
</html>
