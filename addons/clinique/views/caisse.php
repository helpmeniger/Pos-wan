<link rel="stylesheet" href="<?= e(asset('addons/clinique/clinique.css')) ?>">
<?php $cats = []; foreach ($acts as $a) { $cats[$a['category']][] = $a; } ?>
<script type="application/json" id="caisse-data"><?= json_encode(['acts' => array_map(fn($a) => ['id' => (int) $a['id'], 'name' => $a['name'], 'category' => $a['category'], 'price' => (float) $a['price']], $acts)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<div class="d-flex flex-wrap gap-2 mb-2 align-items-center">
  <span class="badge bg-success fs-6"><i class="bi bi-hospital"></i> Caisse clinique</span>
  <?php if ($pharmacyLink): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/pos')) ?>"><i class="bi bi-capsule"></i> Médicaments (pharmacie)</a><?php endif; ?>
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/clinique/factures')) ?>"><i class="bi bi-receipt"></i> Factures</a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/clinique')) ?>"><i class="bi bi-people"></i> File d'attente</a>
  <span class="ms-auto small text-secondary"><?= e($register['name'] ?? 'Aucune caisse associée') ?> · <?= e($u['full_name'] ?? (App\Core\Auth::user()['full_name'] ?? '')) ?></span>
</div>
<?php if ($requireSession && !$sessionOpen): ?><div class="alert alert-warning py-2"><i class="bi bi-lock"></i> Votre caisse est fermée : les encaissements sont refusés. <a href="<?= e(url('/cash')) ?>">Ouvrir ma caisse</a> (les factures peuvent être préparées sans paiement).</div><?php endif; ?>
<div class="row g-3 cl-caisse">
  <div class="col-lg-3">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-person-vcard"></i> Patient</div><div class="card-body">
      <input id="patSearch" class="form-control" placeholder="Nom, n° de dossier, téléphone" autocomplete="off">
      <div id="patResults" class="list-group small mt-1"></div>
      <div id="patCard" class="mt-2 d-none"></div>
      <button type="button" class="btn btn-sm btn-link px-0" id="patNewToggle"><i class="bi bi-person-plus"></i> Nouveau patient</button>
      <div id="patNew" class="d-none row g-1">
        <div class="col-6"><input class="form-control form-control-sm" data-np="last_name" placeholder="Nom *"></div>
        <div class="col-6"><input class="form-control form-control-sm" data-np="first_name" placeholder="Prénom *"></div>
        <div class="col-4"><select class="form-select form-select-sm" data-np="sex"><option value="">Sexe *</option><option value="M">M</option><option value="F">F</option></select></div>
        <div class="col-8"><input class="form-control form-control-sm" data-np="phone" placeholder="Téléphone"></div>
        <div class="col-7"><input class="form-control form-control-sm" data-np="insurer" placeholder="Assurance"></div>
        <div class="col-5"><input class="form-control form-control-sm" data-np="coverage_pct" placeholder="% pris en charge" inputmode="decimal"></div>
        <div class="col-12"><button type="button" class="btn btn-sm btn-primary w-100" id="patCreate">Créer le dossier</button></div>
      </div>
    </div></div>
    <div class="card"><div class="card-header small">Factures à encaisser</div><ul class="list-group list-group-flush small">
      <?php foreach ($openBills as $b): ?><li class="list-group-item d-flex"><a href="<?= e(url('/clinique/factures/' . $b['id'])) ?>"><?= e($b['number']) ?></a>&nbsp;<?= e($b['last_name'] . ' ' . $b['first_name']) ?><b class="ms-auto text-danger"><?= money($b['due']) ?></b></li><?php endforeach; ?>
      <?php if (!$openBills): ?><li class="list-group-item text-secondary">Aucune.</li><?php endif; ?>
    </ul></div>
  </div>
  <div class="col-lg-5">
    <div class="card"><div class="card-header d-flex"><span><i class="bi bi-grid"></i> Prestations</span><input id="actFilter" class="form-control form-control-sm ms-auto" style="max-width:180px" placeholder="Filtrer"></div><div class="card-body cl-acts">
      <?php foreach ($cats as $cat => $list): ?>
        <div class="cl-cat small fw-semibold text-secondary mt-1"><?= e($cat) ?></div>
        <div class="cl-tiles"><?php foreach ($list as $a): ?><button type="button" class="cl-tile" data-act="<?= (int) $a['id'] ?>" data-name="<?= e(mb_strtolower($a['name'])) ?>"><span><?= e($a['name']) ?></span><b><?= money($a['price']) ?></b></button><?php endforeach; ?></div>
      <?php endforeach; ?>
      <?php if (!$acts): ?><div class="text-secondary">Aucune prestation : ajoutez-en dans « Actes, lits, rapport ».</div><?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card"><div class="card-header"><i class="bi bi-cart"></i> Facture</div>
      <ul class="list-group list-group-flush small" id="clLines"><li class="list-group-item text-secondary">Touchez une prestation pour l'ajouter.</li></ul>
      <div class="card-body border-top">
        <div class="d-flex"><span>Total</span><b class="ms-auto" id="clTotal">0</b></div>
        <div class="d-flex small text-secondary"><span id="clCovLabel">Prise en charge</span><span class="ms-auto" id="clCov">0</span></div>
        <div class="d-flex fs-5 fw-bold"><span>Part patient</span><span class="ms-auto" id="clDue">0</span></div>
        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="clQueue"><label class="form-check-label small" for="clQueue">Placer le patient dans la file d'attente de consultation</label></div>
        <div id="clQueueBox" class="row g-1 d-none">
          <div class="col-7"><select id="clDoctor" class="form-select form-select-sm"><option value="">Premier médecin disponible</option><?php foreach ($practitioners as $d): ?><option value="<?= (int) $d['id'] ?>"><?= e($d['full_name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-5"><select id="clPriority" class="form-select form-select-sm"><option value="normal">Normal</option><option value="urgent">Urgent</option></select></div>
          <div class="col-12"><input id="clReason" class="form-control form-control-sm" placeholder="Motif" maxlength="255"></div>
        </div>
        <div class="row g-1 mt-2"><?= App\Core\View::partial('clinique::_pay') ?></div>
        <div class="small text-secondary mt-1"><?= setting('clinique_partial_payment', '1') === '1' ? 'Paiement partiel accepté : le reste figure sur la facture.' : 'Paiement complet exigé.' ?> Montant 0 = facture à payer plus tard.</div>
        <button type="button" class="btn btn-success w-100 mt-2" id="clCheckout" disabled><i class="bi bi-check2-circle"></i> Valider et encaisser</button>
        <div id="clMsg" class="small mt-2"></div>
      </div>
    </div>
    <div class="card mt-3"><div class="card-header small">Mes encaissements du jour</div><ul class="list-group list-group-flush small">
      <?php foreach ($today as $b): ?><li class="list-group-item d-flex"><a href="<?= e(url('/clinique/factures/' . $b['id'])) ?>"><?= e($b['number']) ?></a>&nbsp;<?= e($b['last_name']) ?><span class="ms-auto"><?= money($b['paid']) ?></span> <a class="ms-2" target="_blank" rel="noopener" href="<?= e(url('/clinique/factures/' . $b['id'] . '/ticket')) ?>" title="Reçu"><i class="bi bi-printer"></i></a></li><?php endforeach; ?>
      <?php if (!$today): ?><li class="list-group-item text-secondary">Aucun.</li><?php endif; ?>
    </ul></div>
  </div>
</div>
