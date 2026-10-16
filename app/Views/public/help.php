<?php $this->layout('layouts/app');
$faq = [
  'Compte' => [
    ['L\'inscription est-elle gratuite ?', 'Oui. La création d\'un compte prestataire ou demandeur est gratuite. Des offres professionnelles payantes pourront être proposées aux entreprises : leur prix sera toujours affiché avant toute souscription.'],
    ['Faut-il une adresse e-mail ?', 'Non : le numéro de téléphone suffit pour créer un compte et se connecter.'],
    ['J\'ai oublié mon mot de passe', 'Utilisez « Mot de passe oublié » sur la page de connexion : vous recevrez un lien à usage unique, valable 30 minutes.'],
    ['Pourquoi me demande-t-on ma date de naissance ?', 'Certaines missions sont réservées aux personnes majeures ou à partir d\'un âge minimum. Votre date de naissance n\'est jamais affichée publiquement.'],
  ],
  'Missions et sécurité' => [
    ['Quels documents dois-je fournir ?', 'Aucun pour les missions standard. Une pièce d\'identité ou un diplôme n\'est demandé que pour les missions qui l\'exigent (travail auprès d\'enfants, électricité, conduite professionnelle…). Ces documents ne sont jamais publiés.'],
    ['Une annonce me demande de payer pour postuler', 'C\'est interdit sur JOB NIGER. Ne payez rien et signalez l\'annonce ou contactez-nous.'],
    ['Que faire en cas de danger ou d\'abus ?', 'En cas de danger immédiat, contactez d\'abord les services d\'urgence. Signalez ensuite la situation au support : les signalements « danger » sont traités en priorité.'],
  ],
  'Paiements et commissions' => [
    ['Combien prend JOB NIGER ?', 'La commission standard est indiquée sur la page « Comment ça marche ». Elle est toujours affichée avant l\'acceptation d\'une mission, avec le montant net du prestataire.'],
    ['Comment se fait le paiement ?', 'Au lancement, le demandeur paie directement le prestataire (Mobile Money ou espèces) et la plateforme enregistre le paiement. Ne communiquez jamais votre code secret Mobile Money : aucun agent ne vous le demandera.'],
  ],
];
?>
<div class="container py-4" style="max-width:900px">
  <h1 class="h3">Centre d'aide</h1>
  <p class="text-muted">Les réponses aux questions les plus fréquentes. Vous ne trouvez pas ? <a href="<?= e(url('/contact')) ?>">Écrivez-nous</a>.</p>
  <?php $i = 0; foreach ($faq as $section => $items): ?>
    <h2 class="h5 mt-4"><?= e($section) ?></h2>
    <div class="accordion" id="faq-<?= $i ?>">
      <?php foreach ($items as $j => [$q, $a]): $id = "q{$i}-{$j}"; ?>
        <div class="accordion-item">
          <h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $id ?>" aria-expanded="false" aria-controls="<?= $id ?>"><?= e($q) ?></button></h3>
          <div id="<?= $id ?>" class="accordion-collapse collapse" data-bs-parent="#faq-<?= $i ?>"><div class="accordion-body"><?= e($a) ?></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php $i++; endforeach; ?>
</div>
