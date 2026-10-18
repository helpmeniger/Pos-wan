<?php use Addons\Ecole\Ecole; $modes = payment_methods(); $logo = setting('company_logo', ''); ?>
<?php if ($format === 't80'): ?>
<div class="ticket">
  <div class="c"><?php if ($logo): ?><img class="logo" src="<?= e(upload_url($logo)) ?>" alt=""><br><?php endif; ?><span class="big"><?= e(Ecole::nom()) ?></span><br><?= e((string) setting('company_address', '')) ?><br><?= e((string) setting('company_phone', '')) ?></div>
  <hr><div class="c b">REÇU DE PAIEMENT</div><div class="c"><?= e($p['numero']) ?> · <?= e(fdate($p['created_at'])) ?></div>
  <?php if ($p['annule']): ?><div class="dup">ANNULÉ</div><?php endif; ?>
  <hr><div><?= e(Ecole::apprenant()) ?> : <b><?= e($p['prenom'] . ' ' . $p['nom']) ?></b></div><div>Matricule : <?= e($p['matricule']) ?></div><div>Classe : <?= e($p['classe']) ?> · <?= e($p['annee']) ?></div>
  <hr><table><?php foreach ($lignes as $l): ?><tr><td><?= e($l['libelle']) ?></td><td class="num"><?= money($l['montant']) ?></td></tr><?php endforeach; ?></table>
  <hr><table><tr><td class="b">TOTAL PAYÉ</td><td class="num big"><?= money($p['montant']) ?></td></tr><tr><td>Mode</td><td class="num"><?= e($p['operateur'] ?: ($modes[$p['mode']] ?? $p['mode'])) ?></td></tr><?php if ($p['reference']): ?><tr><td>Référence</td><td class="num"><?= e($p['reference']) ?></td></tr><?php endif; ?>
  <tr><td>Reste à payer</td><td class="num b"><?= money($solde['reste']) ?></td></tr></table>
  <hr><div class="c">Reçu par <?= e((string) $p['caissier']) ?></div><div class="c">Merci. Conservez ce reçu.</div>
</div>
<?php else: ?>
<div class="sheet">
  <div class="head"><div><?php if ($logo): ?><img src="<?= e(upload_url($logo)) ?>" alt="" style="max-height:20mm"><br><?php endif; ?><h1><?= e(Ecole::nom()) ?></h1><?= e((string) setting('company_address', '')) ?><br><?= e((string) setting('company_phone', '')) ?></div>
    <div style="text-align:right"><h1>REÇU N° <?= e($p['numero']) ?></h1>Date : <?= e(fdate($p['created_at'])) ?><br>Année scolaire : <?= e($p['annee']) ?><?php if ($p['annule']): ?><div class="dup">ANNULÉ — <?= e((string) $p['motif_annulation']) ?></div><?php endif; ?></div></div>
  <div class="box" style="margin-top:10px"><?= e(Ecole::apprenant()) ?> : <b><?= e($p['prenom'] . ' ' . $p['nom']) ?></b> · matricule <?= e($p['matricule']) ?> · classe <b><?= e($p['classe']) ?></b><?= $p['tuteur_nom'] ? '<br>Parent / tuteur : ' . e($p['tuteur_nom']) : '' ?></div>
  <table class="lines"><thead><tr><th>Désignation</th><th class="num">Montant</th></tr></thead><tbody><?php foreach ($lignes as $l): ?><tr><td><?= e($l['libelle']) ?></td><td class="num"><?= money($l['montant']) ?></td></tr><?php endforeach; ?></tbody></table>
  <table class="totals"><tr class="grand"><td>Total payé</td><td class="num"><?= money($p['montant']) ?></td></tr><tr><td>Mode de paiement</td><td class="num"><?= e($p['operateur'] ?: ($modes[$p['mode']] ?? $p['mode'])) ?><?= $p['reference'] ? ' · ' . e($p['reference']) : '' ?></td></tr><tr><td>Reste à payer sur l'année</td><td class="num"><b><?= money($solde['reste']) ?></b></td></tr></table>
  <div class="sign"><div>Le payeur</div><div>Le caissier : <?= e((string) $p['caissier']) ?></div></div>
  <div class="foot"><?= e(Ecole::nom()) ?> · reçu généré par POS Marwa</div>
</div>
<?php endif; ?>
