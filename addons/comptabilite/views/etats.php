<?php require __DIR__ . '/_nav.php'; $q = ['exercice' => $ex['id']]; $sys = Addons\Comptabilite\Compta::referentiel() === 'syscohada'; ?>
<div class="d-flex align-items-center gap-2 mb-3"><h2 class="h5 mb-0">États comptables</h2><div class="ms-auto"><?php require __DIR__ . '/_exsel.php'; ?></div></div>
<div class="row g-3">
<?php foreach ([
    ['/compta/etats/journal', 'bi-journal-text', 'Journal', 'Toutes les écritures d\'un journal (ou de tous) sur une période, ligne par ligne.'],
    ['/compta/etats/centralisateur', 'bi-grid-3x3', 'Journal centralisateur', 'Totaux débit / crédit par journal et par mois.'],
    ['/compta/etats/grand-livre', 'bi-book', 'Grand livre', 'Mouvements de chaque compte avec report, solde progressif et totaux.'],
    ['/compta/etats/balance', 'bi-table', 'Balance générale', 'Soldes d\'ouverture, mouvements et soldes de chaque compte (par compte, par groupe ou par classe).'],
    ['/compta/etats/balance-tiers', 'bi-people', 'Balance des tiers', 'Ce que doit chaque client, ce qui est dû à chaque fournisseur, montants non lettrés.'],
    ['/compta/etats/bilan', 'bi-columns-gap', 'Bilan', $sys ? 'Actif (brut, amortissements, net) et passif selon le SYSCOHADA révisé, avec l\'exercice précédent.' : 'Actif et passif regroupés par comptes à 2 chiffres (présentation simplifiée).'],
    ['/compta/etats/resultat', 'bi-graph-up-arrow', 'Compte de résultat', $sys ? 'Soldes intermédiaires SYSCOHADA : marge commerciale, valeur ajoutée, EBE, résultat d\'exploitation, financier, HAO, résultat net.' : 'Charges et produits (présentation simplifiée, sur la page du bilan).'],
] as [$u, $i, $l, $d]): ?>
  <div class="col-md-6 col-xl-4"><a class="card h-100 text-decoration-none" href="<?= e(url($u, $q)) ?>"><div class="card-body"><div class="fs-4 text-primary"><i class="bi <?= e($i) ?>"></i></div><div class="fw-semibold text-body"><?= e($l) ?></div><div class="small text-secondary"><?= e($d) ?></div></div></a></div>
<?php endforeach; ?>
</div>
<p class="small text-secondary mt-3">Chaque état s'affiche à l'écran, s'imprime (format A4) et s'exporte en CSV (Excel) ou PDF. Par défaut il inclut le brouillard, signalé comme provisoire ; choisissez « Validées seulement » pour les états définitifs.</p>
