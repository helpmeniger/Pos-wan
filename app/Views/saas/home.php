<section class="saas-hero">
  <div class="container">
    <div class="row align-items-center g-4">
      <div class="col-lg-7">
        <span class="kicker">Caisse · Stock · Achats · Clients · Rapports</span>
        <h1>Votre commerce géré en ligne, depuis un ordinateur, une tablette ou un téléphone.</h1>
        <p class="lead">POS Marwa en ligne : aucun logiciel à installer, vos données sauvegardées sur nos serveurs, les mises à jour faites pour vous. Essayez gratuitement pendant <b><?= (int) $trialDays ?> jours</b>, sans engagement.</p>
        <div class="d-flex flex-wrap gap-2">
          <a href="<?= e(url('/inscription')) ?>" class="btn btn-warning btn-lg fw-semibold"><i class="bi bi-rocket-takeoff"></i> Créer mon espace</a>
          <a href="#offres" class="btn btn-outline-light btn-lg">Voir les offres</a>
        </div>
      </div>
      <div class="col-lg-5">
        <ul class="saas-checks">
          <li><i class="bi bi-shop"></i><span><b>Votre espace à votre nom</b>votre-commerce.<?= e(config('saas.domain')) ?></span></li>
          <li><i class="bi bi-shield-lock"></i><span><b>Données séparées</b>une base de données propre à chaque client</span></li>
          <li><i class="bi bi-wifi-off"></i><span><b>Caisse qui tient les coupures</b>les ventes en attente sont envoyées au retour du réseau</span></li>
          <li><i class="bi bi-phone"></i><span><b>Paiement par mobile money</b>ou virement, avec facture à chaque règlement</span></li>
        </ul>
      </div>
    </div>
  </div>
</section>

<section class="container py-5" id="offres">
  <h2 class="text-center mb-1">Nos offres</h2>
  <p class="text-center text-muted mb-4">Toutes commencent par <?= (int) $trialDays ?> jours d'essai gratuit. Prix mensuels, payables d'avance.</p>
  <div class="row g-4 justify-content-center">
    <?php foreach ($plans as $i => $p): $mods = $p['addons'] === '*' ? array_values($addonNames) : array_values(array_intersect_key($addonNames, array_flip(array_filter(array_map('trim', explode(',', $p['addons'])))))); ?>
    <div class="col-md-6 col-lg-4">
      <div class="saas-plan <?= $i === 1 ? 'featured' : '' ?>">
        <?php if ($i === 1): ?><span class="badge-star">Le plus choisi</span><?php endif; ?>
        <h3><?= e($p['name']) ?></h3>
        <p class="text-muted small mb-2"><?= e($p['description']) ?></p>
        <div class="price"><?= e(number_format((float) $p['price_month'], 0, ',', ' ')) ?> <small>FCFA / mois</small></div>
        <ul>
          <li><i class="bi bi-people"></i> <?= (int) $p['max_users'] ?> utilisateur(s)</li>
          <li><i class="bi bi-shop"></i> <?= (int) $p['max_stores'] ?> boutique(s)</li>
          <li><i class="bi bi-cash-stack"></i> <?= (int) $p['max_registers'] ?> caisse(s)</li>
          <li><i class="bi bi-check2"></i> Ventes, stock, achats, clients, devis, rapports PDF</li>
          <?php if ($mods): ?><li><i class="bi bi-puzzle"></i> Modules : <?= e(implode(', ', $mods)) ?></li><?php endif; ?>
        </ul>
        <a class="btn <?= $i === 1 ? 'btn-warning' : 'btn-outline-primary' ?> w-100 fw-semibold" href="<?= e(url('/inscription', ['offre' => $p['code']])) ?>">Essayer l'offre <?= e($p['name']) ?></a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="saas-steps">
  <div class="container py-5">
    <h2 class="text-center mb-4">Comment ça marche</h2>
    <div class="row g-4 text-center">
      <div class="col-md-3"><div class="step-n">1</div><b>Inscription</b><p class="small text-muted">Nom du commerce, activité, votre identifiant et votre mot de passe.</p></div>
      <div class="col-md-3"><div class="step-n">2</div><b>Votre espace est créé</b><p class="small text-muted">Base de données, boutique, caisse et compte administrateur, en une minute.</p></div>
      <div class="col-md-3"><div class="step-n">3</div><b>Vous vendez</b><p class="small text-muted">Ajoutez vos articles, vos caissiers, ouvrez la caisse.</p></div>
      <div class="col-md-3"><div class="step-n">4</div><b>Vous payez à l'échéance</b><p class="small text-muted">Mobile money ou virement ; l'abonnement est prolongé après vérification.</p></div>
    </div>
  </div>
</section>
