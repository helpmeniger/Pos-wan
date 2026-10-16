<?php $this->layout('layouts/app'); ?>
<div class="container py-4" style="max-width:900px">
  <h1 class="h3">Présentation de JOB NIGER</h1>
  <p class="lead">JOB NIGER met en relation celles et ceux qui ont besoin d'un coup de main ou d'un renfort, et celles et ceux qui veulent travailler.</p>
  <div class="row g-3 my-2">
    <div class="col-md-6"><div class="jn-card p-3 h-100">
      <h2 class="h6"><i class="bi bi-briefcase text-primary" aria-hidden="true"></i> Pour les demandeurs</h2>
      <p class="small text-muted mb-0">Particuliers, commerces, restaurants, hôtels, entreprises, ONG, écoles, organisateurs d'événements, agriculteurs…
        Publiez une mission, comparez les candidatures, négociez, puis suivez la réalisation et le paiement.</p>
    </div></div>
    <div class="col-md-6"><div class="jn-card p-3 h-100">
      <h2 class="h6"><i class="bi bi-person-workspace text-primary" aria-hidden="true"></i> Pour les prestataires</h2>
      <p class="small text-muted mb-0">Étudiants, jeunes diplômés, personnes sans emploi, artisans, techniciens, indépendants : présentez vos compétences
        (déclarées ou vérifiées), vos disponibilités et vos zones, postulez et construisez votre réputation.</p>
    </div></div>
  </div>
  <h2 class="h5 mt-4">Transparence des commissions</h2>
  <p>JOB NIGER se rémunère par une commission sur les prestations suivies sur la plateforme. Le montant de la commission, la partie qui la paie,
    le total payé par le demandeur et le montant net du prestataire sont <strong>toujours affichés avant</strong> l'acceptation d'une mission.</p>
  <div class="jn-card p-3 mb-3">
    <div class="fw-semibold mb-2">Exemple avec la commission standard actuelle</div>
    <table class="table table-sm mb-0">
      <tbody>
        <tr><td>Prix de la prestation</td><td class="text-end"><?= e(money($example['service'])) ?></td></tr>
        <tr><td>Commission JOB NIGER à la charge du prestataire (<?= e($example['worker_rate']) ?> %)</td><td class="text-end"><?= e(money($example['worker_commission'])) ?></td></tr>
        <?php if ($example['client_commission'] > 0): ?>
        <tr><td>Commission JOB NIGER à la charge du demandeur (<?= e($example['client_rate']) ?> %)</td><td class="text-end"><?= e(money($example['client_commission'])) ?></td></tr>
        <?php endif; ?>
        <tr><td>Montant net du prestataire</td><td class="text-end fw-semibold"><?= e(money($example['worker_net'])) ?></td></tr>
        <tr><td>Montant payé par le demandeur</td><td class="text-end"><?= e(money($example['client_total'])) ?></td></tr>
      </tbody>
    </table>
  </div>
  <h2 class="h5 mt-4">Paiements</h2>
  <p>Au lancement, le paiement se fait <strong>directement</strong> entre le demandeur et le prestataire (Mobile Money ou espèces) : la plateforme
    enregistre la déclaration et les preuves, mais <strong>ne détient pas les fonds</strong>. Le paiement intégré sera proposé uniquement avec un prestataire
    de paiement habilité.</p>
  <h2 class="h5 mt-4">Sécurité</h2>
  <p class="mb-0">Les missions à risque (travail en hauteur, électricité, conduite professionnelle, travail auprès d'enfants…) sont réservées aux profils
    vérifiés et qualifiés. Signalez tout comportement suspect : aucune offre honnête ne vous demandera de payer pour postuler.</p>
</div>
