<?php /** Page d'accueil publique */ ?>
<section class="hero">
  <div class="hero-inner">
    <span class="badge amber">Niamey · Niger</span>
    <h1 style="margin-top:12px">Votre taxi, vos repas et vos colis, en quelques gestes.</h1>
    <p>Indiquez votre départ et votre destination : MARWA estime le prix en FCFA, vous choisissez le tarif proposé ou vous négociez votre prix avec les chauffeurs.</p>
  </div>
</section>

<div class="order-panel">
  <div class="order-layout">
    <div class="card" id="home-order">
      <div class="services" role="tablist" aria-label="Services">
        <button class="service-btn active" data-service="taxi"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 11l1.5-4.5A2 2 0 018.4 5h7.2a2 2 0 011.9 1.5L19 11m-14 0h14m-14 0a2 2 0 00-2 2v3h2m14-5a2 2 0 012 2v3h-2M5 16v2m14-2v2"/></svg></span>Taxi</button>
        <button class="service-btn" data-service="parcel"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8l-9-5-9 5 9 5 9-5zm0 0v8l-9 5m0-8v8m-9-13v8l9 5"/></svg></span>Livraison</button>
        <a class="service-btn" href="<?= e(url('client#/repas')) ?>"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11h16a8 8 0 01-16 0zm8-7v3M3 20h18"/></svg></span>Repas</a>
        <a class="service-btn" href="<?= e(url('client#/colis')) ?>"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h7l5 5v13H7zM14 3v5h5"/></svg></span>Colis</a>
        <a class="service-btn" href="<?= e(url('inscription?profil=business')) ?>"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h16v11H4zm5 0V5h6v3M4 13h16"/></svg></span>Pro</a>
      </div>
      <hr class="divider">
      <div id="home-pickup"></div>
      <div id="home-dropoff"></div>
      <button class="btn btn-primary btn-block btn-lg" id="home-estimate">Voir les prix</button>
      <div id="home-estimates" class="stack" style="margin-top:14px"></div>
    </div>
    <div class="card" style="padding:8px">
      <div class="map-wrap"><div id="home-map" class="map tall" aria-label="Carte"></div></div>
    </div>
  </div>
</div>

<section class="section">
  <div class="grid-3">
    <div class="feature"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M3 6h18v12H3zm9 9a3 3 0 100-6 3 3 0 000 6z"/></svg></div><div><h3>Prix clair en FCFA</h3><p class="muted">Tarif automatique ou prix négocié : le prix final est confirmé par les deux parties avant le départ.</p></div></div>
    <div class="feature"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M9 4l-6 2v14l6-2 6 2 6-2V4l-6 2zm0 0v14m6-12v14"/></svg></div><div><h3>Suivi sur la carte</h3><p class="muted">Suivez le chauffeur ou le livreur grâce à la position GPS envoyée par son téléphone.</p></div></div>
    <div class="feature"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 3l8 3v6c0 5-3.4 8.4-8 9-4.6-.6-8-4-8-9V6z"/></svg></div><div><h3>Prestataires vérifiés</h3><p class="muted">Chaque chauffeur, livreur et restaurant est validé par l'équipe MARWA avant de travailler.</p></div></div>
  </div>
</section>

<section class="section" id="home-promos" hidden>
  <h2>Promotions du moment</h2>
  <div class="grid-3" data-promos></div>
</section>

<section class="section" id="home-restaurants" hidden>
  <div class="row between"><h2>Restaurants</h2><a href="<?= e(url('client#/repas')) ?>">Tout voir</a></div>
  <div class="grid-3" data-restaurants></div>
</section>

<section class="section">
  <div class="card" style="background:var(--green-50)">
    <h2>Travaillez avec MARWA</h2>
    <p class="muted">Chauffeurs, livreurs, restaurants et commerces : créez votre compte professionnel. Il sera activé après vérification de vos documents.</p>
    <div class="row">
      <a class="btn btn-primary" href="<?= e(url('inscription?profil=driver')) ?>">Devenir chauffeur</a>
      <a class="btn btn-outline" href="<?= e(url('inscription?profil=courier')) ?>">Devenir livreur</a>
      <a class="btn btn-outline" href="<?= e(url('inscription?profil=merchant')) ?>">Inscrire mon restaurant</a>
      <a class="btn btn-outline" href="<?= e(url('inscription?profil=business')) ?>">Compte entreprise</a>
    </div>
  </div>
</section>

<footer class="site-footer">
  <div class="container">
    <div class="row between">
      <div><strong style="color:#fff">MARWA</strong> — mobilité et livraison au Niger. Montants en francs CFA (XOF).</div>
      <div class="row"><a href="<?= e(url('pages/cgu')) ?>">Conditions</a><a href="<?= e(url('pages/confidentialite')) ?>">Confidentialité</a><a href="<?= e(url('pages/securite')) ?>">Sécurité</a></div>
    </div>
  </div>
</footer>
