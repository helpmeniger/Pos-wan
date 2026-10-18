<?php use Addons\Ecole\Ecole; use Addons\Ecole\Finance; $ptype = 'eleve'; $e = null; $eleve = true; /* $eleve : formulaire « élève » (consentement) */ ?>
<script type="application/json" id="ecole-caisse-data"><?= json_encode(['eleve' => (int) $eleveId, 'recu' => (int) ($_GET['recu'] ?? 0), 'visage' => $visage, 'seuil' => (float) setting('ecole_visage_seuil', '0.50'), 'marge' => (float) setting('ecole_visage_marge', '0.05')], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <h2 class="h5 mb-0"><i class="bi bi-cash-coin"></i> Caisse <?= $annee ? '· ' . e($annee['libelle']) : '' ?></h2>
  <?php if ($sessionRequise): ?><span class="badge <?= $sessionOuverte ? 'bg-success' : 'bg-danger' ?>"><?= $sessionOuverte ? 'Caisse ouverte' : 'Caisse fermée' ?></span><?php if (!$sessionOuverte): ?> <a class="small" href="<?= e(url('/cash')) ?>">Ouvrir ma caisse</a><?php endif; ?><?php endif; ?>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-outline-secondary" id="ecScan" title="Scanner la carte (code-barres du matricule)"><i class="bi bi-upc-scan"></i> Carte</button>
    <?php if ($visage): ?><button type="button" class="btn btn-outline-danger" id="ecFace"><i class="bi bi-person-bounding-box"></i> Visage</button><?php endif; ?>
    <button type="button" class="btn btn-primary" id="ecNewBtn"><i class="bi bi-person-plus"></i> Nouvelle inscription</button>
  </div>
</div>
<?php if (!$annee): ?><div class="alert alert-warning">Aucune année scolaire en cours : créez-la dans <a href="<?= e(url('/ecole/parametres')) ?>">Paramètres de l'établissement</a>.</div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-4"><div class="card"><div class="card-body">
    <input id="ecQ" class="form-control form-control-lg" placeholder="Nom, matricule ou téléphone du parent" autocomplete="off" autofocus>
    <div class="list-group mt-2" id="ecResults"></div>
    <div class="small text-secondary mt-2">Astuce : scannez la carte scolaire (douchette ou caméra) pour ouvrir directement le dossier.</div>
  </div></div></div>
  <div class="col-lg-8">
    <div class="card" id="ecDossier" hidden>
      <div class="card-body d-flex gap-3 align-items-start border-bottom">
        <img class="ec-photo-mid" data-f="photo" alt="" hidden><div class="ec-photo-mid ec-mini-empty" data-f="nophoto"><i class="bi bi-person"></i></div>
        <div class="flex-grow-1"><div class="h5 mb-0" data-f="nom"></div><div class="small text-secondary" data-f="info"></div><div class="small" data-f="tuteur"></div><div class="small text-danger" data-f="med"></div></div>
        <a class="btn btn-sm btn-outline-secondary" data-f="fiche" href="#"><i class="bi bi-person-vcard"></i> Fiche</a>
      </div>
      <div data-f="noins" class="p-3" hidden><div class="alert alert-info mb-2">Pas encore inscrit(e) pour l'année en cours.</div><button type="button" class="btn btn-primary" data-act="inscrire"><i class="bi bi-journal-plus"></i> L'inscrire maintenant</button></div>
      <div data-f="frais">
        <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th style="width:30px"></th><th>Échéance</th><th>Date limite</th><th class="text-end">Montant</th><th class="text-end">Reste</th></tr></thead><tbody data-f="echeances"></tbody></table></div>
        <div class="p-3 border-top">
          <div class="d-flex flex-wrap gap-3 small mb-2"><span>Total dû : <b data-f="du"></b></span><span>Payé : <b class="text-success" data-f="paye"></b></span><span>Reste : <b data-f="reste"></b></span><span class="text-danger">Échu : <b data-f="echu"></b></span></div>
          <div class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label small mb-0">Montant</label><input class="form-control form-control-lg text-end" id="ecMontant" inputmode="numeric"></div>
            <div class="col-md-3"><label class="form-label small mb-0">Mode</label><select class="form-select" id="ecMode"><?php foreach ($modes as $k => $l): if ($k === 'mobile_money' && $operateurs): foreach ($operateurs as $o): ?><option value="mobile_money|<?= e($o) ?>"><?= e($o) ?></option><?php endforeach; else: ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endif; endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label small mb-0">Référence</label><input class="form-control" id="ecRef" maxlength="100" placeholder="N° de transaction…"></div>
            <div class="col-md-3"><button type="button" class="btn btn-success btn-lg w-100" id="ecPay"><i class="bi bi-check2-circle"></i> Encaisser</button></div>
          </div>
          <div class="small text-secondary mt-1">Cochez des échéances pour les régler en priorité ; sinon, les plus anciennes sont réglées d'abord.</div>
          <div class="alert mt-2 mb-0" id="ecMsg" hidden></div>
        </div>
      </div>
      <div class="border-top p-3 small"><b>Derniers reçus</b><div data-f="recus"></div></div>
    </div>
    <div class="card" id="ecVide"><div class="card-body text-center text-secondary py-5"><i class="bi bi-search fs-1"></i><div>Recherchez un(e) <?= e(mb_strtolower(Ecole::apprenant())) ?> ou faites une nouvelle inscription.</div></div></div>
  </div>
</div>

<div class="ec-modal" id="ecNew" hidden><div class="ec-modal-box">
  <div class="d-flex align-items-center p-2 border-bottom"><b><i class="bi bi-journal-plus"></i> Inscription <?= $annee ? e($annee['libelle']) : '' ?></b><button type="button" class="btn-close ms-auto" data-close aria-label="Fermer"></button></div>
  <form method="post" action="<?= e(url('/ecole/caisse/inscription')) ?>" enctype="multipart/form-data" class="p-3 row g-2 small" data-ecole-form id="ecNewForm"><?= csrf_field() ?>
    <input type="hidden" name="eleve_id" id="ecNewEleve"><input type="hidden" name="uuid" id="ecNewUuid">
    <div class="col-12 alert alert-info py-1 mb-0" id="ecNewExisting" hidden></div>
    <div class="col-md-3 ec-new-only"><?php include __DIR__ . '/_photo.php'; ?></div>
    <div class="col-md-9 ec-new-only"><div class="row g-2">
      <div class="col-md-5"><label class="form-label mb-0 required">Nom</label><input name="nom" class="form-control" maxlength="80" data-req></div>
      <div class="col-md-5"><label class="form-label mb-0 required">Prénom(s)</label><input name="prenom" class="form-control" maxlength="100" data-req></div>
      <div class="col-md-2"><label class="form-label mb-0 required">Sexe</label><select name="sexe" class="form-select" data-req><option value="">—</option><option value="M">M</option><option value="F">F</option></select></div>
      <div class="col-md-4"><label class="form-label mb-0">Né(e) le</label><input type="date" name="date_naissance" class="form-control"></div>
      <div class="col-md-4"><label class="form-label mb-0">à</label><input name="lieu_naissance" class="form-control" maxlength="100"></div>
      <div class="col-md-4"><label class="form-label mb-0">Téléphone</label><input name="telephone" class="form-control" maxlength="40"></div>
      <div class="col-md-6"><label class="form-label mb-0">Parent / tuteur</label><input name="tuteur_nom" class="form-control" maxlength="150"></div>
      <div class="col-md-6"><label class="form-label mb-0">Téléphone du parent</label><input name="tuteur_tel" class="form-control" maxlength="40"></div>
      <?php include __DIR__ . '/_consentement.php'; ?>
    </div></div>
    <div class="col-md-5"><label class="form-label mb-0 required">Classe</label><select name="classe_id" class="form-select" required id="ecNewClasse"><option value="">—</option><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" data-ins="<?= (float) $c['frais_inscription'] ?>" data-sco="<?= (float) $c['frais_scolarite'] ?>" data-tr="<?= (int) $c['nb_tranches'] ?>"><?= e($c['nom']) ?> (<?= (int) $c['effectif'] ?><?= $c['capacite'] ? '/' . (int) $c['capacite'] : '' ?>)</option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label mb-0">Type</label><select name="type" class="form-select"><?php foreach (Finance::TYPES_INSCRIPTION as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label mb-0">Remise sur la scolarité</label><input name="remise" class="form-control" inputmode="numeric" value="0" id="ecNewRemise"></div>
    <div class="col-md-6"><input name="motif_remise" class="form-control" maxlength="150" placeholder="Motif de la remise (fratrie, bourse…)"></div>
    <div class="col-md-6"><label><input type="checkbox" name="sans_frais_inscription" value="1"> Sans frais d'inscription</label></div>
    <?php for ($i = 0; $i < 3; $i++): ?><div class="col-md-8"><input name="frais[<?= $i ?>][libelle]" class="form-control" maxlength="100" placeholder="Autres frais (tenue, cantine, transport…)"></div><div class="col-md-4"><input name="frais[<?= $i ?>][montant]" class="form-control" inputmode="numeric" placeholder="Montant" data-extra></div><?php endfor; ?>
    <div class="col-12 ec-recap" id="ecNewRecap"></div>
    <div class="col-md-4"><label class="form-label mb-0">Premier versement</label><input name="premier_paiement" class="form-control form-control-lg text-end" inputmode="numeric" value="0"></div>
    <div class="col-md-4"><label class="form-label mb-0">Mode</label><select name="mode" class="form-select"><?php foreach ($modes as $k => $l): if ($k === 'mobile_money' && $operateurs): foreach ($operateurs as $o): ?><option value="mobile_money|<?= e($o) ?>"><?= e($o) ?></option><?php endforeach; else: ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endif; endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label mb-0">Référence</label><input name="reference" class="form-control" maxlength="100"></div>
    <div class="col-12"><button class="btn btn-success btn-lg"><i class="bi bi-check2-circle"></i> Valider l'inscription</button></div>
  </form>
</div></div>
