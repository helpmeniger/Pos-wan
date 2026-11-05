<section class="container py-5 saas-terms" style="max-width:860px">
  <h1 class="h3">Conditions du service et données personnelles</h1>
  <p class="text-muted">Service exploité par <b><?= e($operator['operator_name']) ?></b><?= $operator['operator_address'] ? ', ' . e($operator['operator_address']) : '' ?>. Logiciel POS Marwa © <?= e(config('licence.author.name')) ?>.</p>
  <h2 class="h5">1. Le service</h2>
  <p>Le service donne accès, par un navigateur, au logiciel POS Marwa dans un espace réservé au client (son adresse, sa base de données). Il comprend l'hébergement, les sauvegardes et les mises à jour du logiciel.</p>
  <h2 class="h5">2. Essai, abonnement et paiement</h2>
  <p>L'essai gratuit est accordé une fois par commerce. L'abonnement est payé d'avance, par mois ; le paiement (mobile money, virement…) est vérifié manuellement puis l'abonnement est prolongé et une facture est émise. Sans paiement à l'échéance, l'espace passe en <b>lecture seule</b> : les données restent consultables et peuvent être sauvegardées, les ventes et modifications sont suspendues jusqu'au paiement.</p>
  <h2 class="h5">3. Données personnelles (loi n° 2017-28 du 3 mai 2017)</h2>
  <p>Le client reste responsable des données qu'il saisit (clients, fournisseurs, employés, patients, élèves…). L'exploitant agit comme hébergeur et sous-traitant : il ne consulte pas ces données, sauf demande d'assistance du client ou obligation légale. Les données de chaque client sont dans une base séparée. Les données d'inscription (nom, téléphone, e-mail) servent à la gestion de l'abonnement et ne sont ni vendues ni cédées.</p>
  <p>Le client peut à tout moment télécharger une sauvegarde de ses données (Paramètres → Sauvegardes) et demander la suppression de son espace ; la suppression est faite après une dernière sauvegarde remise au client. Le client informe lui-même les personnes dont il enregistre les données et, lorsque la loi l'exige, accomplit les formalités auprès de la Haute Autorité de Protection des Données à caractère Personnel (HAPDP).</p>
  <h2 class="h5">4. Sécurité et disponibilité</h2>
  <p>Mots de passe chiffrés de façon irréversible, connexions protégées, journal des opérations, sauvegardes régulières. Aucun système informatique n'est invulnérable : l'exploitant met en œuvre des mesures raisonnables mais ne peut garantir l'absence totale d'incident ou d'interruption (coupure du réseau, maintenance).</p>
  <h2 class="h5">5. Contact</h2>
  <p><?= e($operator['operator_name']) ?><?= $operator['operator_phone'] ? ' · ' . e($operator['operator_phone']) : '' ?><?= $operator['operator_email'] ? ' · ' . e($operator['operator_email']) : '' ?></p>
</section>
