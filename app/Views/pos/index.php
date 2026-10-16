<?php /** Écran de caisse plein écran — disposition inspirée de l'ancien logiciel, modernisée. */ ?><!doctype html>
<html <?= html_attrs() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="pw-currency" content="<?= e(json_encode(currency(), JSON_UNESCAPED_UNICODE)) ?>">
<meta name="base-url" content="<?= e(url('/')) ?>">
<title>Caisse · POS Marwa</title>
<link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
<meta name="theme-color" content="#14213d">
<link rel="apple-touch-icon" href="<?= e(asset('img/icons/icon-180.png')) ?>">
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>">
<link rel="stylesheet" href="<?= e(bootstrap_css()) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/pos.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/scanner.css')) ?>">
</head>
<body class="pos-body">
<div class="pos">
  <!-- ============ ZONE ARTICLES ============ -->
  <section class="pos-left">
    <div class="cat-bar" id="catBar">
      <button class="cat-tile active" data-cat="0"><span class="cat-ico" style="background:#14213d"><i class="bi bi-grid-3x3-gap-fill"></i></span><span>TOUT</span></button>
      <?php foreach ($categories as $c): ?>
        <button class="cat-tile" data-cat="<?= (int) $c['id'] ?>">
          <?php if ($c['image']): ?><img src="<?= e(upload_url($c['image'])) ?>" alt=""><?php else: ?><span class="cat-ico" style="background:<?= e($c['color']) ?>"><i class="bi <?= e($c['icon'] ?: 'bi-tag') ?>"></i></span><?php endif; ?>
          <span><?= e(mb_strtoupper($c['name'])) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <?php if ($alerts || $clinicLink || $rxQueue !== null): ?>
    <div class="mode-bar">
      <?php if ($mode === 'clinique_pharmacie'): ?><span class="mode-tag"><i class="bi bi-capsule"></i> Pharmacie</span><?php if ($clinicLink): ?><a class="mode-link" href="<?= e(url('/clinique/caisse')) ?>"><i class="bi bi-hospital"></i> Prestations médicales</a><?php endif; ?><?php endif; ?>
      <?php if ($rxQueue !== null): ?><a class="mode-link <?= $rxQueue ? 'warn' : '' ?>" href="<?= e(url('/clinique/pharmacie')) ?>"><i class="bi bi-file-medical"></i> Ordonnances à délivrer : <b><?= (int) $rxQueue ?></b></a><?php endif; ?>
      <?php if ($alerts): ?>
        <?php if ($alerts['expired']): ?><a class="mode-link danger" href="<?= e(url('/stock/expiry')) ?>" title="Lots périmés : leur vente est bloquée"><i class="bi bi-calendar-x"></i> <?= (int) $alerts['expired'] ?> lot(s) périmé(s)</a><?php endif; ?>
        <?php if ($alerts['soon']): ?><a class="mode-link warn" href="<?= e(url('/stock/expiry')) ?>"><i class="bi bi-hourglass-split"></i> <?= (int) $alerts['soon'] ?> lot(s) périment sous <?= (int) $alerts['days'] ?> j</a><?php endif; ?>
        <?php if ($alerts['out']): ?><a class="mode-link warn" href="<?= e(url('/stock', ['filter' => 'low'])) ?>"><i class="bi bi-box-seam"></i> <?= (int) $alerts['out'] ?> médicament(s) en rupture</a><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="search-row">
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="search" id="productSearch" class="form-control" placeholder="Rechercher un article (nom, référence, DCI)…" autocomplete="off">
      </div>
      <div class="btn-group view-toggle" role="group" aria-label="Affichage">
        <button class="btn btn-light active" data-view="grid" title="Grille"><i class="bi bi-grid-3x3-gap"></i></button>
        <button class="btn btn-light" data-view="list" title="Liste"><i class="bi bi-list-ul"></i></button>
      </div>
    </div>
    <div class="product-grid" id="productGrid" aria-live="polite"></div>
    <!-- Barre inférieure : code-barres, argent client, à rendre -->
    <div class="bottom-bar">
      <div class="mode-switch btn-group">
        <button class="btn btn-mode active" data-mode="auto" title="Douchette : ajout automatique"><i class="bi bi-upc-scan"></i> Auto</button>
        <button class="btn btn-mode" data-mode="manual" title="Saisie manuelle puis validation"><i class="bi bi-keyboard"></i> Manuel</button>
      </div>
      <input type="text" id="barcodeInput" class="form-control barcode" placeholder="Code-barres ici (scanner ou saisir puis Entrée)" autocomplete="off" inputmode="numeric">
      <button class="btn btn-icon btn-ok" id="barcodeGo" title="Ajouter"><i class="bi bi-check-lg"></i></button>
      <button class="btn btn-icon btn-camera" id="cameraScan" title="Scanner avec la caméra (smartphone, tablette)"><i class="bi bi-camera"></i></button>
      <button class="btn btn-icon btn-trash" data-action="clear" title="Vider le panier"><i class="bi bi-trash3"></i></button>
      <div class="money-box"><input type="text" id="tenderedInput" class="form-control" inputmode="numeric" value="" placeholder="0"><label>Argent client</label></div>
      <button class="btn btn-icon btn-calc" data-action="pay" title="Encaisser"><i class="bi bi-calculator"></i></button>
      <div class="money-box change"><output id="changeOut"><?= e(money(0)) ?></output><label>à rendre</label></div>
    </div>
  </section>

  <!-- ============ BANDE QUANTITÉ RAPIDE ============ -->
  <nav class="qty-strip" aria-label="Quantité rapide">
    <button class="strip-nav" data-scroll="up" title="Défiler vers le haut"><i class="bi bi-chevron-up"></i></button>
    <?php for ($i = 2; $i <= 9; $i++): ?><button class="qty-key" data-qty="<?= $i ?>" title="Quantité ×<?= $i ?> pour le prochain article"><?= $i ?></button><?php endfor; ?>
    <button class="strip-nav" data-scroll="down" title="Défiler vers le bas"><i class="bi bi-chevron-down"></i></button>
  </nav>

  <!-- ============ PANNEAU TICKET (droite) ============ -->
  <aside class="pos-right">
    <div class="pr-head">
      <div><div class="clock" data-clock></div><div class="small text-muted-2"><i class="bi bi-person"></i> <?= e($u['full_name']) ?> · <?= e($register['name'] ?? 'Aucune caisse') ?></div></div>
      <div class="head-icons">
        <a href="<?= e(url('/menu')) ?>" title="Menu de gestion"><i class="bi bi-grid-3x3-gap-fill"></i></a>
        <button data-action="lock" title="Verrouiller / changer d'utilisateur"><i class="bi bi-person-lock"></i></button>
        <button data-action="fullscreen" title="Plein écran"><i class="bi bi-fullscreen"></i></button>
        <form method="post" action="<?= e(url('/logout')) ?>" class="d-inline"><?= csrf_field() ?><button title="Déconnexion"><i class="bi bi-power"></i></button></form>
      </div>
    </div>
    <div class="pr-total">
      <span>Total :</span><output id="totalOut"><?= e(money(0)) ?></output>
    </div>
    <div class="pr-customer" id="customerBadge"><i class="bi bi-person-circle"></i> <span>Client comptoir</span></div>
    <div class="pr-actions">
      <button data-action="customer" title="Client (F4)"><i class="bi bi-person-plus"></i><span>Client</span></button>
      <button data-action="quantity" title="Quantité (F6)"><i class="bi bi-123"></i><span>Quantité</span></button>
      <button data-action="price" title="Modifier le prix"><i class="bi bi-pencil-square"></i><span>Prix</span></button>
      <button data-action="discount" title="Remise (F7)"><i class="bi bi-percent"></i><span>Remise</span></button>
      <button data-action="remove" title="Supprimer la ligne (Suppr)"><i class="bi bi-x-lg"></i><span>Retirer</span></button>
      <button data-action="hold" title="Mettre en attente (F8)"><i class="bi bi-pause-circle"></i><span>Attente</span></button>
      <button data-action="held" title="Ventes en attente"><i class="bi bi-inboxes"></i><span>Reprendre</span><b class="held-count" id="heldCount"></b></button>
      <button data-action="recent" title="Dernières ventes / réimpression"><i class="bi bi-printer"></i><span>Tickets</span></button>
    </div>
    <div class="cart-head"><span>Articles</span><span>Qté</span><span>P.U.</span><span>Total</span></div>
    <div class="cart" id="cart" tabindex="0" aria-label="Panier"></div>
    <div class="pr-summary" id="summary"></div>
    <div class="last-sale d-none" id="lastSale" role="status" aria-live="polite"></div>
    <button class="btn-validate" data-action="validate" title="<?= $config['quickValidate'] ? 'Encaissement automatique en espèces (F9)' : 'Encaisser (F9)' ?>"><i class="bi bi-check-circle"></i> Valider</button>
    <div class="pr-bottom">
      <button data-action="cashExact" title="Fenêtre d'encaissement : espèces, mobile money, carte, paiement mixte (F10)"><i class="bi bi-cash-stack"></i><span>Espèces</span></button>
      <button data-action="credit" title="Vente à crédit"><i class="bi bi-journal-bookmark"></i><span>Crédit</span></button>
      <button data-action="note" title="Note sur la vente"><i class="bi bi-chat-left-text"></i><span>Note</span></button>
      <a href="<?= e(url('/cash')) ?>" title="Ma caisse"><i class="bi bi-safe2"></i><span>Caisse</span></a>
      <a href="<?= e(url('/menu')) ?>" title="Gestion"><i class="bi bi-tools"></i><span>Gestion</span></a>
    </div>
    <div class="pr-options">
      <?php if (feature('wholesale') && can('pos.wholesale')): ?><label><input type="checkbox" id="optWholesale"> Vente en gros</label><?php endif; ?>
      <label><input type="checkbox" id="optTicket" checked> TICKET</label>
      <label><input type="checkbox" id="optA4"> Facture A4</label>
    </div>
  </aside>
</div>

<!-- ============ CAISSE FERMÉE ============ -->
<?php if (!$session && $config['requireSession']): ?>
<div class="overlay" id="closedOverlay">
  <div class="overlay-card">
    <i class="bi bi-safe2 display-4 text-warning"></i>
    <h2 class="h4 mt-2">Caisse fermée</h2>
    <?php if (!$register): ?>
      <p>Aucune caisse n'est associée à votre compte. Un administrateur doit vous affecter une caisse (Utilisateurs).</p>
      <a class="btn btn-light" href="<?= e(url('/menu')) ?>">Menu de gestion</a>
    <?php elseif (can('cash.open_close')): ?>
      <p>Saisissez le fond de caisse compté pour ouvrir <b><?= e($register['name']) ?></b>.</p>
      <form method="post" action="<?= e(url('/cash/open')) ?>">
        <?= csrf_field() ?><input type="hidden" name="return" value="pos">
        <div class="input-group input-group-lg mb-3"><input type="number" name="opening_amount" class="form-control text-end" min="0" step="1" required placeholder="0" autofocus><span class="input-group-text"><?= e(currency()['symbol']) ?></span></div>
        <button class="btn btn-warning btn-lg w-100"><i class="bi bi-unlock"></i> Ouvrir la caisse</button>
      </form>
    <?php else: ?>
      <p>Demandez à un responsable d'ouvrir la caisse.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ============ MODALE PAIEMENT ============ -->
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content pay-modal">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-wallet2"></i> Encaissement</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-lg-7">
            <div class="pay-total"><div><small>Total à payer</small><output id="payTotal"><?= e(money(0)) ?></output></div><div><small>Reste</small><output id="payRemaining"><?= e(money(0)) ?></output></div><div><small>À rendre</small><output id="payChange" class="text-success"><?= e(money(0)) ?></output></div></div>
            <div class="pay-methods" id="payMethods"></div>
            <div class="pay-providers d-none" id="payProviders" aria-label="Opérateur mobile money"></div>
            <div class="input-group input-group-lg my-2">
              <span class="input-group-text" id="payMethodLabel">Espèces</span>
              <input type="text" class="form-control text-end fw-bold" id="payAmount" inputmode="numeric" autocomplete="off">
              <button class="btn btn-primary" id="payAdd"><i class="bi bi-plus-lg"></i> Ajouter</button>
            </div>
            <input type="text" class="form-control mb-2 d-none" id="payRef" placeholder="Référence (n° transaction mobile money, carte...)" maxlength="100">
            <div class="quick-cash" id="quickCash"></div>
            <table class="table table-sm mt-2"><tbody id="payLines"></tbody></table>
          </div>
          <div class="col-lg-5">
            <div class="keypad-pos" id="payKeypad">
              <?php foreach (['7', '8', '9', '4', '5', '6', '1', '2', '3', '00', '0', '⌫'] as $k): ?><button type="button" data-k="<?= e($k) ?>"><?= e($k) ?></button><?php endforeach; ?>
            </div>
            <div class="mt-3 p-2 border rounded small" id="payCustomerBox">Client : <b id="payCustomer">comptoir</b> <button class="btn btn-sm btn-link" data-action="customer">changer</button></div>
            <div class="mt-2 d-none" id="rxBox">
              <div class="alert alert-info py-2 small mb-2"><i class="bi bi-file-medical"></i> Ordonnance requise pour au moins un médicament.</div>
              <input class="form-control form-control-sm mb-1" id="rxPrescriber" placeholder="Prescripteur (médecin, établissement)" maxlength="150">
              <input class="form-control form-control-sm mb-1" id="rxPatient" placeholder="Nom du patient" maxlength="150">
              <div class="d-flex gap-1"><input class="form-control form-control-sm" id="rxRef" placeholder="N° ordonnance" maxlength="100"><input type="date" class="form-control form-control-sm" id="rxDate"></div>
            </div>
          </div>
        </div>
        <div class="alert alert-danger d-none mt-2 mb-0" id="payError"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-light" data-bs-dismiss="modal">Retour</button>
        <button class="btn btn-success btn-lg px-5" id="payConfirm"><i class="bi bi-check2-circle"></i> Valider la vente</button>
      </div>
    </div>
  </div>
</div>

<!-- ============ MODALE SAISIE NUMÉRIQUE (quantité, prix, remise) ============ -->
<div class="modal fade" id="numModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h6 class="modal-title" id="numTitle">Quantité</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
    <div class="modal-body">
      <div class="btn-group w-100 mb-2 d-none" id="numDiscType"><input type="radio" class="btn-check" name="dt" id="dtAmount" value="amount" checked><label class="btn btn-outline-secondary" for="dtAmount">Montant <?= e(currency()['symbol']) ?></label><input type="radio" class="btn-check" name="dt" id="dtPercent" value="percent"><label class="btn btn-outline-secondary" for="dtPercent">%</label></div>
      <div class="btn-group w-100 mb-2 d-none" id="numScope"><input type="radio" class="btn-check" name="sc" id="scLine" value="line" checked><label class="btn btn-outline-secondary" for="scLine">Ligne</label><input type="radio" class="btn-check" name="sc" id="scTicket" value="ticket"><label class="btn btn-outline-secondary" for="scTicket">Ticket entier</label></div>
      <input type="text" class="form-control form-control-lg text-end fw-bold mb-2" id="numInput" inputmode="decimal" autocomplete="off">
      <div class="keypad-pos small-keys" id="numKeypad"><?php foreach (['7', '8', '9', '4', '5', '6', '1', '2', '3', ',', '0', '⌫'] as $k): ?><button type="button" data-k="<?= e($k) ?>"><?= e($k) ?></button><?php endforeach; ?></div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary w-100" id="numOk">OK</button></div>
  </div></div>
</div>

<!-- ============ MODALE CLIENT ============ -->
<div class="modal fade" id="customerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h6 class="modal-title"><i class="bi bi-person"></i> Client</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
    <div class="modal-body">
      <input type="search" class="form-control mb-2" id="customerSearch" placeholder="Nom ou téléphone…" autocomplete="off">
      <div class="list-group mb-3" id="customerResults"></div>
      <button class="btn btn-outline-secondary btn-sm" id="customerNone">Client comptoir (aucun)</button>
      <?php if (can('customers.manage')): ?>
      <hr><div class="small fw-semibold mb-1">Nouveau client</div>
      <div class="d-flex gap-1"><input class="form-control form-control-sm" id="ncName" placeholder="Nom" maxlength="150"><input class="form-control form-control-sm" id="ncPhone" placeholder="Téléphone" maxlength="30"><button class="btn btn-sm btn-success" id="ncSave"><i class="bi bi-plus"></i></button></div>
      <?php endif; ?>
    </div>
  </div></div>
</div>

<!-- ============ MODALE AUTORISATION SUPERVISEUR ============ -->
<div class="modal fade" id="authModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content">
    <div class="modal-header bg-warning-subtle"><h6 class="modal-title"><i class="bi bi-shield-lock"></i> Autorisation requise</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
    <div class="modal-body">
      <p class="small" id="authReason"></p>
      <input class="form-control mb-2" id="authUser" placeholder="Identifiant du responsable" autocomplete="off">
      <input type="password" class="form-control" id="authSecret" placeholder="Mot de passe ou PIN" autocomplete="off">
    </div>
    <div class="modal-footer"><button class="btn btn-warning w-100" id="authOk">Autoriser</button></div>
  </div></div>
</div>

<!-- ============ MODALE LISTES (attente / tickets) ============ -->
<div class="modal fade" id="listModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h6 class="modal-title" id="listTitle"></h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
    <div class="modal-body p-0"><div class="list-group list-group-flush" id="listBody"></div></div>
  </div></div>
</div>

<!-- ============ VENTE TERMINÉE ============ -->
<div class="modal fade" id="doneModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content text-center p-3">
    <i class="bi bi-check-circle-fill text-success display-3"></i>
    <h4 class="mt-2">Vente enregistrée</h4>
    <p class="mb-1" id="doneNumber"></p>
    <div class="done-change">Monnaie à rendre : <output id="doneChange"><?= e(money(0)) ?></output></div>
    <div class="d-flex gap-2 justify-content-center mt-3">
      <a class="btn btn-outline-primary" id="doneTicket" target="_blank" rel="noopener"><i class="bi bi-printer"></i> Ticket</a>
      <a class="btn btn-outline-secondary" id="doneInvoice" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text"></i> Facture A4</a>
      <button class="btn btn-success" id="doneNew" data-bs-dismiss="modal"><i class="bi bi-cart-plus"></i> Nouvelle vente (Entrée)</button>
    </div>
  </div></div>
</div>

<div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3" id="toasts"></div>
<script type="application/json" id="pos-config"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<?= i18n_script() ?>
<script src="<?= e(asset('js/money.js')) ?>"></script>
<script src="<?= e(asset('vendor/zxing/zxing.min.js')) ?>"></script>
<script src="<?= e(asset('js/scanner.js')) ?>"></script>
<script src="<?= e(asset('js/pos.js')) ?>"></script>
<script src="<?= e(asset('js/pwa.js')) ?>"></script>
</body>
</html>
