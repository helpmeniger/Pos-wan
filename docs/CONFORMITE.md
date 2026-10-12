# Points juridiques et réglementaires à valider avant le lancement (Niger)

Ce document **n'est pas un avis juridique**. Il liste les sujets que l'opérateur de MARWA doit
faire valider par un juriste et, le cas échéant, par les autorités compétentes **avant
l'exploitation commerciale**. Les références citées sont à confirmer (textes, numéros, mises à jour).

## 1. Protection des données personnelles
- Cadre nigérien relatif à la protection des données à caractère personnel (loi n° 2017-28 du
  3 mai 2017, modifiée — **à vérifier**) et autorité de contrôle (Haute Autorité de Protection des
  Données à caractère Personnel, HAPDP — **à vérifier**) : formalités préalables (déclaration ou
  autorisation), notamment pour la **géolocalisation** des chauffeurs/livreurs et des clients.
- Information des personnes (politique de confidentialité dans *Paramètres → Contenus*),
  consentement à la géolocalisation (demandé par le navigateur), droits d'accès/rectification/suppression
  (suppression de compte = anonymisation intégrée).
- Durées de conservation : positions GPS (paramètre, 30 jours par défaut), messages, pièces
  d'identité et documents des prestataires, journaux. Hébergement des données hors du Niger :
  vérifier les conditions de transfert.

## 2. Transport de personnes (taxis, moto-taxis)
- Autorisations / licences exigées des chauffeurs et des véhicules (municipales, ministère des
  Transports), assurance couvrant le transport de passagers à titre onéreux, règles propres aux
  moto-taxis. Vérifier si l'activité d'intermédiation de MARWA requiert un agrément spécifique.
- Adapter les **documents exigés** (types de pièces dans *Véhicule et documents*) à la réglementation.
- Encadrement éventuel des tarifs de taxi : le mode « prix négocié » doit rester conforme.

## 3. Livraison et marchandises
- Liste des **objets interdits ou réglementés** (paramétrable) : à faire valider.
- Restauration : règles d'hygiène applicables aux restaurants partenaires, responsabilité en cas
  d'intoxication, mentions sur les produits.
- Responsabilité en cas de perte ou de dommage (valeur déclarée des colis) : à définir dans les CGU.

## 4. Paiements et monnaie électronique (UEMOA / BCEAO)
- MARWA **ne doit pas détenir de fonds pour le compte de tiers** sans agrément : le « portefeuille »
  intégré est un **suivi comptable** des sommes dues, pas un porte-monnaie électronique.
  Tout stockage/transfert réel d'argent doit passer par un établissement agréé (émetteur de monnaie
  électronique, banque, établissement de paiement) selon la réglementation BCEAO.
- Contrats avec les prestataires de paiement (Mobile Money, cartes) : obligations de lutte contre le
  blanchiment (identification, plafonds), conservation des preuves.
- Encaissements en espèces par les chauffeurs pour le compte des restaurants : à encadrer
  contractuellement (mandat d'encaissement).

## 5. Fiscalité et facturation
- Régime fiscal de MARWA (commissions, frais de service, abonnements) : **TVA** applicable et taux
  (le paramètre `finance.vat_rate_bp` est à 0 par défaut — le renseigner après validation),
  retenues éventuelles, impôt sur les bénéfices.
- Mentions obligatoires des factures (NIF, RCCM, numérotation continue, TVA) : les reçus actuels
  sont des **reçus non fiscaux** ; la facturation légale (et la facturation périodique des
  entreprises) doit être conforme avant le lancement.
- Statut fiscal des chauffeurs/livreurs indépendants et des restaurants (déclaration de leurs revenus).
- Durées légales de conservation des pièces comptables (grand livre immuable déjà en place).

## 6. Droit commercial et relations avec les partenaires
- Immatriculation de la société (RCCM), NIF, statuts.
- CGU clients, conditions partenaires (chauffeurs, livreurs, restaurants, entreprises) :
  commissions, modalités et délais de versement, suspension, litiges, annulations et éventuels frais.
- Qualification de la relation avec les chauffeurs/livreurs (indépendants) : risque de
  requalification à étudier.
- Protection du consommateur : affichage des prix en FCFA TTC, estimation non garantie (déjà
  signalée dans l'interface), procédure de réclamation (intégrée).

## 7. Communications
- SMS et WhatsApp : consentement pour les messages non transactionnels, identifiant d'expéditeur,
  coûts ; utilisation exclusive de l'API WhatsApp Business officielle.
- Numéros d'urgence affichés (police, pompiers, secours) : **à confirmer auprès des autorités**
  avant publication ; le bouton d'urgence de MARWA n'appelle pas automatiquement les secours.

## 8. Cybersécurité
- Respect du cadre national relatif à la cybercriminalité et à la sécurité des systèmes
  d'information (à vérifier), notification des violations de données le cas échéant.
- Voir la liste de contrôle de [SECURITE.md](SECURITE.md).
