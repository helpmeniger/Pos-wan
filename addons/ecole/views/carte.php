<?php /** Cartes au format CR80 (85,6 × 54 mm), 10 par page A4. */ ?>
<style>
.ec-cartes { display: grid; grid-template-columns: repeat(2, 85.6mm); gap: 6mm; justify-content: center; padding: 8mm; background: #fff; width: 210mm; margin: 1rem auto; box-sizing: border-box; }
.ec-carte { width: 85.6mm; height: 54mm; border: 1px solid #555; border-radius: 3mm; overflow: hidden; display: flex; flex-direction: column; font-size: 8.5pt; page-break-inside: avoid; break-inside: avoid; }
.ec-carte .h { background: #1d4ed8; color: #fff; padding: 1.5mm 2.5mm; display: flex; justify-content: space-between; align-items: center; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.ec-carte .h b { font-size: 8.5pt; } .ec-carte .h span { font-size: 7pt; }
.ec-carte .c { display: flex; gap: 2.5mm; padding: 2mm 2.5mm; flex: 1; }
.ec-carte .ph { width: 22mm; height: 27mm; border: 1px solid #999; object-fit: cover; flex-shrink: 0; background: #eee; }
.ec-carte .nm { font-weight: bold; font-size: 10pt; line-height: 1.15; }
.ec-carte .bc { height: 9mm; } .ec-carte .bc svg { height: 100%; width: 100%; }
.ec-carte .f { font-size: 6.5pt; text-align: center; color: #333; padding: 0 2mm 1mm; }
@media print { .ec-cartes { margin: 0; padding: 0; width: auto; } @page { size: A4; margin: 8mm; } }
</style>
<div class="ec-cartes">
<?php foreach ($cartes as $k): $p = $k['p']; ?>
  <div class="ec-carte">
    <div class="h"><b><?= e(Addons\Ecole\Ecole::nom()) ?></b><span><?= e($k['titre']) ?><?= $annee ? ' · ' . e($annee['libelle']) : '' ?></span></div>
    <div class="c"><?php if ($k['photo']): ?><img class="ph" src="<?= e($k['photo']) ?>" alt=""><?php else: ?><div class="ph"></div><?php endif; ?>
      <div style="flex:1;min-width:0"><div class="nm"><?= e($p['nom']) ?><br><?= e($p['prenom']) ?></div>
        <div><?= e($k['detail']) ?></div><?php if (!empty($p['date_naissance'])): ?><div>Né(e) le <?= e(fdate($p['date_naissance'])) ?></div><?php endif; ?>
        <div>Matricule : <b><?= e($p['matricule']) ?></b></div>
        <div class="bc"><?= App\Services\Barcode::svg($p['matricule'], 30) ?></div></div></div>
    <?php if (setting('ecole_devise', '')): ?><div class="f"><?= e(setting('ecole_devise', '')) ?></div><?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
