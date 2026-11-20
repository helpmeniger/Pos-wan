<?php
use Addons\Fiscal\Secef\Secef;
/** @var array $cfg @var ?array $connector @var array $hints @var ?array $etat @var string $choix @var array $stats */
$on = (bool) $cfg['actif'];
$sel = fn(string $name, array $opts, string $cur) => '<select class="form-select form-select-sm" name="' . e($name) . '">' . implode('', array_map(fn($k, $v) => '<option value="' . e($k) . '"' . ((string) $k === $cur ? ' selected' : '') . '>' . e($v) . '</option>', array_keys($opts), $opts)) . '</select>';
$groupes = ['' => '—'] + array_combine(array_keys(Secef::GROUPES), array_map(fn($k, $v) => "[$k] $v", array_keys(Secef::GROUPES), Secef::GROUPES));
?>
<?= App\Core\View::partial('fiscal::_nav', ['tab' => $tab]) ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h2 class="h5 mb-0"><i class="bi bi-patch-check text-success"></i> MARWA SECEF CONNECT <small class="text-secondary">— factures certifiées e-SECeF (DGI Niger)</small></h2>
  <span class="badge <?= $on ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $on ? 'actif' : 'inactif' ?></span>
  <span class="badge <?= $cfg['certification_autorisee'] ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= $cfg['certification_autorisee'] ? 'certification réelle autorisée' : 'préparation seulement (aucune certification)' ?></span>
  <a class="btn btn-sm btn-outline-primary ms-auto" href="<?= e(url('/fiscal/secef/articles')) ?>"><i class="bi bi-box-seam"></i> Articles et taxes (<?= (int) ($stats['programmes'] ?? 0) ?> / <?= (int) $stats['articles'] ?> programmés)</a>
</div>
<?php if ($otherProfiles): ?><div class="alert alert-warning small"><i class="bi bi-exclamation-triangle"></i> D'autres profils fiscaux sont actifs (<?= e(implode(', ', array_column($otherProfiles, 'name'))) ?>) : le profil le plus précis (boutique) est appliqué en premier.</div><?php endif; ?>
<div class="alert alert-info small">
  <b>Fonctionnement :</b> au moment de la vente, le caissier coche <b>« Facture certifiée DGI »</b>. POS Marwa enregistre la vente, puis le pont local
  (Node.js + navigateur) remplit le formulaire officiel de l'e-SFE, vérifie le total et — seulement si vous l'avez autorisé ci-dessous — clique sur
  <b>CERTIFIER</b>. Le reçu porte alors le Code SECeF/DGI et le NIM affichés par le portail. Aucune référence n'est inventée : sans preuve, la facture reste « NON CERTIFIÉE ».
  La voie officielle pour un logiciel de caisse est l'<b>e-MCF</b> (logiciel homologué) : faites confirmer ce mode d'intégration par la DGI avant d'autoriser la certification.
</div>

<div class="row g-3">
<div class="col-xl-7">
<form method="post" action="<?= e(url('/fiscal/secef')) ?>" class="card mb-3"><?= csrf_field() ?>
  <div class="card-header fw-semibold"><i class="bi bi-sliders"></i> Configuration</div>
  <div class="card-body row g-2">
    <div class="col-12 form-check form-switch ms-2"><input class="form-check-input" type="checkbox" name="actif" value="1" id="sActif" <?= $on ? 'checked' : '' ?>><label class="form-check-label fw-semibold" for="sActif">Activer MARWA SECEF CONNECT</label></div>
    <div class="col-md-6"><label class="form-label small mb-0">Adresse du portail e-SECeF</label><input class="form-control form-control-sm" name="url_portail" value="<?= e($cfg['url_portail']) ?>" required></div>
    <div class="col-md-6"><label class="form-label small mb-0">Adresse du pont (sur cet ordinateur)</label><input class="form-control form-control-sm" name="url_pont" value="<?= e($cfg['url_pont']) ?>" required></div>
    <div class="col-12 mt-3 fw-semibold small">Caisse</div>
    <div class="col-md-6"><label class="form-label small mb-0">Certification des ventes</label><?= $sel('choix_caisse', ['au_choix' => 'Au choix du caissier (case « Facture certifiée DGI »)', 'toutes' => 'Toutes les ventes'], $choix === 'toutes' ? 'toutes' : 'au_choix') ?></div>
    <div class="col-md-6 form-check ms-2 mt-4"><input class="form-check-input" type="checkbox" name="case_cochee" value="1" id="sCase" <?= $cfg['case_cochee'] ? 'checked' : '' ?>><label class="form-check-label small" for="sCase">Case « Facture certifiée DGI » cochée d'office</label></div>
    <div class="col-md-4"><label class="form-label small mb-0">Type de facture</label><?= $sel('type_defaut', ['FV' => 'FV — Facture de vente', 'EV' => 'EV — Vente à l\'exportation'], $cfg['type_defaut']) ?></div>
    <div class="col-md-4"><label class="form-label small mb-0">Mode des prix</label><?= $sel('mode_prix', ['TTC' => 'PRIX TTC', 'HT' => 'PRIX HT'], $cfg['mode_prix']) ?></div>
    <div class="col-md-4 form-check ms-2 mt-4"><input class="form-check-input" type="checkbox" name="reference_commentaire" value="1" id="sRef" <?= $cfg['reference_commentaire'] ? 'checked' : '' ?>><label class="form-check-label small" for="sRef">N° de vente POS en commentaire</label></div>
    <div class="col-md-6"><label class="form-label small mb-0">Client avec NIF : type e-SFE</label><?= $sel('client_avec_nif', ['morale' => 'Personne morale', 'physique' => 'Personne physique'], $cfg['client_avec_nif']) ?></div>
    <div class="col-md-6"><label class="form-label small mb-0">Client sans NIF : type e-SFE</label><?= $sel('client_sans_nif', ['physique' => 'Personne physique', 'morale' => 'Personne morale'], $cfg['client_sans_nif']) ?></div>
    <div class="col-12 mt-3 fw-semibold small">Régime et groupes de taxation (liste officielle de l'e-SFE)</div>
    <div class="col-md-8"><label class="form-label small mb-0">Votre régime</label><?= $sel('regime', Secef::REGIMES, $cfg['regime']) ?></div>
    <div class="col-md-4"><label class="form-label small mb-0">Groupe par défaut (régime IS)</label><?= $sel('groupe_defaut', array_slice($groupes, 1, null, true), $cfg['groupe_defaut']) ?></div>
    <div class="col-12 small text-secondary">Assujetti à la TVA : groupe selon le taux de TVA de l'article dans POS Marwa.</div>
    <?php foreach (['19' => 'TVA 19 %', '10' => 'TVA 10 %', '5' => 'TVA 5 %', '0' => 'TVA 0 %'] as $rate => $lbl): ?>
      <div class="col-md-3"><label class="form-label small mb-0"><?= e($lbl) ?></label><?= $sel('taux[' . $rate . ']', $groupes, (string) ($cfg['taux'][$rate] ?? '')) ?></div>
    <?php endforeach; ?>
    <div class="col-12 small text-secondary">Le groupe d'un article ou de sa catégorie (page Articles) passe avant ces règles. POS Marwa ne devine jamais un groupe : un article sans groupe n'est pas transmis.</div>
    <div class="col-12 mt-3 fw-semibold small">Modes de paiement (POS Marwa → e-SFE)</div>
    <?php foreach (['cash' => 'Espèces', 'mobile_money' => 'Mobile money', 'card' => 'Carte', 'transfer' => 'Virement', 'cheque' => 'Chèque', 'credit' => 'Crédit (à terme)', 'voucher' => 'Bon d\'achat'] as $m => $lbl): ?>
      <div class="col-md-3"><label class="form-label small mb-0"><?= e($lbl) ?></label><?= $sel('paiements[' . $m . ']', array_combine(Secef::PAIEMENTS, Secef::PAIEMENTS), (string) ($cfg['paiements'][$m] ?? 'ESPECES')) ?></div>
    <?php endforeach; ?>
    <div class="col-12 mt-3 fw-semibold small">Articles et navigateur</div>
    <div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" name="creer_articles" value="1" id="sCreer" <?= $cfg['creer_articles'] ? 'checked' : '' ?>><label class="form-check-label small" for="sCreer">Programmer automatiquement sur l'e-SFE un article vendu en caisse qui n'y existe pas encore (désignation, code = référence POS, groupe, prix)</label></div>
    <div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" name="fenetre_visible" value="1" id="sVis" <?= $cfg['fenetre_visible'] ? 'checked' : '' ?>><label class="form-check-label small" for="sVis">Afficher la fenêtre du navigateur du pont (suivi, intervention humaine)</label></div>
  </div>
  <div class="card-footer text-end"><button class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Enregistrer</button></div>
</form>
</div>

<div class="col-xl-5">
<div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-person-lock"></i> Compte e-SECeF</div><div class="card-body">
  <?php if (can('fiscal.secrets')): ?>
  <form method="post" action="<?= e(url('/fiscal/secef/acces')) ?>" class="row g-2" autocomplete="off"><?= csrf_field() ?>
    <div class="col-6"><label class="form-label small mb-0">Identifiant (NIF)</label><input class="form-control form-control-sm" name="identifiant" placeholder="<?= e(isset($hints['identifiant']) ? 'enregistré (' . $hints['identifiant'] . ')' : 'non enregistré') ?>" autocomplete="off"></div>
    <div class="col-6"><label class="form-label small mb-0">Mot de passe</label><input class="form-control form-control-sm" type="password" name="mot_de_passe" placeholder="<?= e(isset($hints['mot_de_passe']) ? 'enregistré' : 'non enregistré') ?>" autocomplete="new-password"></div>
    <div class="col-12 small text-secondary">Chiffrés dans la base (jamais réaffichés ni journalisés). Laissez un champ vide pour le garder.</div>
    <div class="col-12"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-key"></i> Enregistrer les identifiants</button></div>
  </form>
  <?php else: ?><div class="small text-secondary">Réservé aux personnes autorisées à saisir les secrets fiscaux.</div><?php endif; ?>
</div></div>

<div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-hdd-network"></i> Pont local et session</div><div class="card-body small">
  <?php if (!$connector): ?>
    <div class="text-secondary">Enregistrez d'abord la configuration.</div>
  <?php else: ?>
    <div class="mb-2">État du pont :
      <?php if (!isset($hints['jeton_pont'])): ?><span class="badge text-bg-secondary">jeton non créé</span>
      <?php elseif (!$etat || isset($etat['erreur'])): ?><span class="badge text-bg-danger">injoignable</span> <span class="text-secondary"><?= e((string) ($etat['erreur'] ?? '')) ?></span>
      <?php else: ?><span class="badge text-bg-success">en marche</span> navigateur : <b><?= e((string) ($etat['navigateur'] ?? '?')) ?></b> · session : <b><?= e((string) ($etat['session'] ?? '?')) ?></b><?= !empty($etat['travail']) ? ' · en cours : ' . e((string) $etat['travail']) : '' ?> · v<?= e((string) ($etat['version'] ?? '')) ?><?php endif; ?>
    </div>
    <?php if (!empty($jeton)): ?>
      <div class="alert alert-warning py-2">Jeton du pont (copiez-le maintenant, il ne sera plus affiché) :<br><code class="user-select-all"><?= e($jeton) ?></code><br>
        Démarrage du pont : <code class="user-select-all">set "PONT_SECEF_JETON=<?= e($jeton) ?>" &amp;&amp; node addons\fiscal\pont-secef\serveur.js</code> (Windows) — ou enregistrez-le dans le fichier <code>jeton.txt</code> du pont.
        <form method="post" action="<?= e(url('/fiscal/secef/jeton/masquer')) ?>" class="mt-1"><?= csrf_field() ?><button class="btn btn-sm btn-light">J'ai copié le jeton</button></form></div>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-1">
      <?php if (can('fiscal.secrets')): ?><form method="post" action="<?= e(url('/fiscal/secef/jeton')) ?>" data-confirm="Créer un nouveau jeton ? Le pont devra être redémarré avec ce jeton."><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-shield-lock"></i> <?= isset($hints['jeton_pont']) ? 'Nouveau jeton' : 'Créer le jeton du pont' ?></button></form><?php endif; ?>
      <form method="post" action="<?= e(url('/fiscal/secef/tester')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-success"><i class="bi bi-plug"></i> Tester la connexion (première authentification)</button></form>
      <form method="post" action="<?= e(url('/fiscal/secef/deconnecter')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-box-arrow-right"></i> Se déconnecter</button></form>
    </div>
    <?php if ($connector['last_test_at']): ?><div class="mt-2 text-secondary">Dernier test (<?= e(fdate($connector['last_test_at'])) ?>) : <span class="<?= $connector['status'] === 'ok' ? 'text-success' : 'text-danger' ?>"><?= e((string) $connector['last_test_message']) ?></span></div><?php endif; ?>
    <div class="mt-2 text-secondary">Le test ouvre la session e-SECeF (connexion avec le NIF) puis l'application e-SFE ; la session est réutilisée pour les ventes suivantes tant qu'elle reste valide. Si le portail demande une validation humaine, cochez « Afficher la fenêtre » et terminez l'étape dans la fenêtre du pont.</div>
  <?php endif; ?>
</div></div>

<div class="card border-danger"><div class="card-header fw-semibold text-danger"><i class="bi bi-exclamation-octagon"></i> Certification réelle</div><div class="card-body small">
  <?php if ($cfg['certification_autorisee']): ?>
    <p class="mb-2">La certification réelle est <b>autorisée</b> : chaque vente cochée « Facture certifiée DGI » est certifiée sur l'e-SECeF (opération fiscale irréversible).</p>
    <form method="post" action="<?= e(url('/fiscal/secef/autorisation')) ?>"><?= csrf_field() ?><input type="hidden" name="autoriser" value="0"><button class="btn btn-sm btn-outline-secondary">Suspendre (préparer seulement)</button></form>
  <?php else: ?>
    <p class="mb-2">Actuellement, le pont <b>prépare et vérifie</b> la facture sur l'e-SFE puis l'<b>annule sans certifier</b>. Autorisez la certification seulement après l'accord de la DGI sur ce mode d'intégration.</p>
    <?php if (can('fiscal.secrets')): ?>
    <form method="post" action="<?= e(url('/fiscal/secef/autorisation')) ?>" class="row g-2"><?= csrf_field() ?><input type="hidden" name="autoriser" value="1">
      <div class="col-6"><input class="form-control form-control-sm" type="password" name="password" placeholder="Votre mot de passe" required autocomplete="current-password"></div>
      <div class="col-6"><input class="form-control form-control-sm" name="confirmation" placeholder="Tapez CERTIFIER" required pattern="CERTIFIER"></div>
      <div class="col-12"><button class="btn btn-sm btn-danger">Autoriser la certification réelle</button></div>
    </form>
    <?php endif; ?>
  <?php endif; ?>
  <div class="mt-2 text-secondary"><?= (int) ($stats['certifiees'] ?? 0) ?> facture(s) certifiée(s) par MARWA SECEF CONNECT.</div>
</div></div>
</div>
</div>
