<?php
/** Étiquettes : dimensions en millimètres, planche A4 positionnée au millimètre ou rouleau (une étiquette par page). */
$w = $cfg['width']; $h = $cfg['height']; $f = array_flip($cfg['fields']);
$isA4 = $cfg['paper'] === 'a4';
$perRow = $isA4 ? $cfg['cols'] : 1;
$rows = $isA4 ? max(1, (int) floor((297 - $cfg['margin_top'] + $cfg['gap_y']) / ($h + $cfg['gap_y']))) : 1;
$perPage = $perRow * $rows;
$pages = $labels ? array_chunk($labels, $perPage) : [];
$company = (string) setting('company_name', '');
$fmt = fn($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><title>Étiquettes</title>
<style>
@page { size: <?= $isA4 ? 'A4 portrait' : $fmt($w) . 'mm ' . $fmt($h) . 'mm' ?>; margin: 0; }
html, body { margin: 0; padding: 0; }
body { font-family: Arial, Helvetica, sans-serif; color: #000; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.page { position: relative; width: <?= $isA4 ? '210mm' : $fmt($w) . 'mm' ?>; height: <?= $isA4 ? '297mm' : $fmt($h) . 'mm' ?>; overflow: hidden; page-break-after: always; break-after: page; background: #fff; }
.page:last-child { page-break-after: auto; break-after: auto; }
.lbl { position: absolute; width: <?= $fmt($w) ?>mm; height: <?= $fmt($h) ?>mm; box-sizing: border-box; padding: 1mm 1.2mm; overflow: hidden;
  display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; font-size: <?= $fmt($cfg['font']) ?>pt; line-height: 1.12; <?= $cfg['border'] ? 'outline: 0.2mm dashed #555; outline-offset: -0.1mm;' : '' ?> }
.co { font-size: .8em; text-transform: uppercase; letter-spacing: .02em; }
.nm { font-weight: 700; max-height: 2.3em; overflow: hidden; word-break: break-word; }
.bc { width: 100%; height: <?= $fmt($cfg['barcode_height']) ?>mm; margin: .4mm 0; }
.bc svg { width: 100%; height: 100%; display: block; }
.rf { font-size: .82em; }
.pr { font-weight: 800; font-size: 1.35em; }
.toolbar { display: none; }
@media screen {
  body { background: #7d8799; padding: 10px 0; }
  .page { margin: 0 auto 10px; box-shadow: 0 2px 10px rgba(0,0,0,.35); }
  .toolbar { display: flex; gap: 8px; justify-content: center; margin-bottom: 10px; font: 14px sans-serif; color: #fff; }
  .toolbar button { font: inherit; padding: 6px 14px; border-radius: 6px; border: 0; cursor: pointer; }
}
</style></head>
<body>
<?php if ($print): ?><span data-autoprint></span><?php endif; ?>
<div class="toolbar"><span><?= count($labels) ?> étiquette(s) · <?= count($pages) ?> page(s) · <?= $isA4 ? 'planche A4, ' . $perRow . ' × ' . $rows : 'rouleau ' . $fmt($w) . ' × ' . $fmt($h) . ' mm' ?></span><?php if ($print): ?><button type="button" data-print>Imprimer</button><?php endif; ?></div>
<?php if (!$labels): ?><div style="color:#fff;text-align:center;font:15px sans-serif;padding:30px">Ajoutez des articles pour voir l'aperçu.</div><?php endif; ?>
<?php foreach ($pages as $page): ?>
<div class="page">
  <?php foreach ($page as $n => $p):
    $col = $n % $perRow; $row = intdiv($n, $perRow);
    $left = $isA4 ? $cfg['margin_left'] + $col * ($w + $cfg['gap_x']) : 0;
    $top = $isA4 ? $cfg['margin_top'] + $row * ($h + $cfg['gap_y']) : 0;
    $code = (string) ($p['barcode'] ?: $p['reference']); ?>
  <div class="lbl" style="left:<?= $fmt($left) ?>mm;top:<?= $fmt($top) ?>mm">
    <?php if (isset($f['company']) && $company !== ''): ?><div class="co"><?= e($company) ?></div><?php endif; ?>
    <?php if (isset($f['name'])): ?><div class="nm"><?= e($p['name']) ?></div><?php endif; ?>
    <?php if (isset($f['barcode']) && $code !== ''): ?><div class="bc"><?= App\Services\Barcode::auto($code, 50) ?></div><?php endif; ?>
    <?php if (isset($f['reference'])): ?><div class="rf"><?= e($p['reference']) ?></div><?php endif; ?>
    <?php if (isset($f['price'])): ?><div class="pr"><?= $test ? '0' : money($p['sale_price']) ?></div><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
<script src="<?= e(asset('js/print.js')) ?>"></script>
</body></html>
