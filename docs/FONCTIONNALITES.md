# État des fonctionnalités

Légende : **✅ Opérationnel** (codé et vérifié par des tests automatisés et/ou en navigateur) ·
**🔌 Nécessite un fournisseur externe** (interface prête, mode log/démo en attendant) ·
**⚠️ Limite connue** · **❌ Non implémenté**.

## Comptes, rôles, sécurité

| Fonctionnalité | État |
|---|---|
| Inscription par téléphone et/ou e-mail (client, chauffeur, livreur, commerce, entreprise) | ✅ |
| Clients : connexion par numéro de téléphone vérifié (code SMS), sans mot de passe, compte créé automatiquement | ✅ testé ; 🔌 fournisseur SMS requis en production (pilote `http` configurable) |
| « Se souvenir de moi » (30 jours, jeton renouvelé à chaque usage, détection de vol) | ✅ testé |
| Connexion, verrouillage après échecs, déconnexion, jetons API mobiles | ✅ |
| Vérification du téléphone par OTP | ✅ logique ; 🔌 SMS réel (en local, le code s'affiche en mode développement et est écrit dans `storage/logs/outbox.log`) |
| Mot de passe oublié : lien e-mail / code SMS | ✅ ; 🔌 e-mail SMTP à configurer, SMS fournisseur |
| Profil, photo, changement de mot de passe, suppression de compte (anonymisation) | ✅ |
| Rôles et permissions côté serveur (6 profils + 3 rôles du personnel) | ✅ testé |
| Double authentification (TOTP + codes de secours), obligatoire pour le personnel si activée | ✅ testé |
| Déconnexion de tous les appareils, alerte de connexion depuis un nouvel appareil | ✅ testé |
| Choix de la langue : français, haoussa, zarma, anglais (+ éditeur de traductions) | ✅ ; ⚠️ haoussa/zarma à faire relire par des locuteurs natifs |

## Client

| Fonctionnalité | État |
|---|---|
| Accueil avec carte, départ/destination, estimation, services, promotions, restaurants | ✅ |
| Position GPS (après autorisation), clic sur la carte, marqueur déplaçable, recherche, repères, favoris, saisie manuelle | ✅ ; ⚠️ recherche d'adresses OSM soumise à Nominatim (voir SERVICES_EXTERNES) |
| Taxi : tarif automatique ou prix négocié, contre-propositions, acceptation/refus, modification du prix | ✅ testé |
| Programmation d'une course, code promo, passagers, instructions, paiement entreprise | ✅ |
| Colis, documents, courses, urgent : expéditeur/destinataire, nature, poids, preuve (code/photo/signature), objets interdits | ✅ |
| Repas : recherche, filtres, menu, variantes/suppléments, panier, frais de livraison, commande | ✅ testé |
| Suivi en temps réel : icône voiture/moto orientée, itinéraire, trajet parcouru, heure de dernière position, alerte position non actualisée, ETA | ✅ testé en navigateur (positions réelles du navigateur du chauffeur) |
| Messagerie liée à la commande (numéros et liens masqués) | ✅ testé |
| Historique, reçus imprimables (PDF via le navigateur), évaluation, réclamations, objets perdus, bouton d'urgence | ✅ ; ⚠️ le bouton d'urgence alerte l'équipe MARWA, il n'appelle **pas** les secours |

## Chauffeur / livreur

| Fonctionnalité | État |
|---|---|
| Profil pro, véhicule, documents avec expiration, statut de validation | ✅ |
| En ligne / hors ligne, envoi GPS réel (limité en fréquence), maintien de l'écran allumé si possible | ✅ ; ⚠️ arrêt de la géolocalisation en arrière-plan par les navigateurs |
| Demandes à proximité avec compte à rebours, gain net affiché, accepter/refuser/contre-proposer | ✅ testé |
| Navigation (liens vers l'application de navigation, OSM, Google Maps), étapes de la course, preuve de livraison, échec/retour | ✅ |
| Déclaration des espèces encaissées, revenus, relevé, demande de retrait | ✅ testé |

## Restaurant / commerce

| Fonctionnalité | État |
|---|---|
| Boutique (adresse GPS, logo, horaires, fermetures, rayon, minimum, mode de livraison) | ✅ |
| Catalogue : catégories, produits, photos, promotions, variantes, suppléments, stock, ruptures, import CSV | ✅ |
| Commandes : accepter (temps de préparation) / refuser (motif) / préparation / prête ; livraison propre avec code | ✅ testé |
| Recherche automatique du livreur selon le temps de préparation, état du livreur affecté | ✅ testé |
| Finances, versements, rapports de ventes + exports CSV/Excel/PDF, livreurs internes | ✅ |

## Entreprise

| Fonctionnalité | État |
|---|---|
| Profil, collaborateurs (rôles, plafonds mensuels, autorisation), adresses | ✅ |
| Commandes pour les employés, paiement différé avec encours maximal et budget | ✅ testé |
| Relevés mensuels + exports CSV/Excel/PDF | ✅ |
| Facturation périodique avec numérotation fiscale | ⚠️ relevé fourni ; la facture fiscale légale reste à définir (CONFORMITE.md) |

## Administration et finance

| Fonctionnalité | État |
|---|---|
| Tableau de bord de direction (CA brut, commissions acquises/encaissées, dus partenaires, marge, bénéfice estimé, opérations, graphiques) | ✅ testé (chiffres issus de la base) |
| Suivi en direct de toutes les commandes (carte + liste, alertes : attente longue, sans prestataire, GPS non actualisé, prestataire hors de l'app) | ✅ testé |
| Contacter un prestataire ou un client : notification app + téléphone, SMS, appel, WhatsApp ; proposer une course à un prestataire précis | ✅ testé |
| Prestataire qui se connecte : les courses restées sans prise en charge autour de lui lui sont proposées | ✅ testé |
| Promotions avec photo (bannière sur l'accueil client) | ✅ testé |
| Notifications : glisser vers la droite pour effacer ; relais SMS des alertes importantes si la personne est injoignable | ✅ testé |
| Précision GPS : meilleure mesure sur quelques secondes, cercle de précision, filtre (Kalman, sauts impossibles) côté prestataire | ✅ |
| Utilisateurs, suspension motivée, comptes du personnel | ✅ |
| Validation des chauffeurs/livreurs (documents), commerces, entreprises | ✅ |
| Commandes : détail, attribution, négociation, annulation, réaffectation, relance, litige, remboursement | ✅ |
| Tarification, commissions (%, fixe, mixte, abonnement, contrats individuels, promotions temporaires, simulateur) | ✅ testé |
| Villes, zones dessinées sur la carte, quartiers, repères (import OSM) | ✅ |
| Paiements, rapprochement, écarts de caisse, doublons, remboursements, versements, reversements, dépenses | ✅ testé |
| Grand livre en partie double immuable, ajustements, contre-passation, balance | ✅ testé |
| 12 rapports + exports CSV, Excel, PDF avec logo | ✅ testé |
| Réclamations, alertes de sécurité, modération des avis, notifications de masse, audit, paramètres, contenus | ✅ |

## Transversal

| Fonctionnalité | État |
|---|---|
| Notifications in-app + navigateur (onglet ouvert) | ✅ |
| Notifications push téléphone en veille / application fermée (Web Push VAPID) | ✅ chiffrement vérifié avec les bibliothèques de référence ; ⚠️ réception réelle via Google/Apple/Mozilla non testable ici (iPhone : application installée sur l'écran d'accueil, iOS 16.4+) |
| Application installable sur l'écran d'accueil (PWA) | ✅ |
| Taxis / livreurs disponibles à proximité sur la carte | ✅ testé |
| Navigation intégrée chauffeur/livreur (consignes, recalcul, voix) | ✅ testé avec un OSRM simulé ; 🔌 OSRM réel requis pour les consignes routières |
| Photos multiples des produits, couverture, logos | ✅ testé |
| E-mail | ✅ SMTP intégré ; 🔌 compte à configurer |
| SMS / WhatsApp | 🔌 / ❌ (voir SERVICES_EXTERNES) |
| Paiement Mobile Money / carte réels | 🔌 interface prête, **aucune API simulée comme réelle** |
| Paiement en espèces, compte entreprise | ✅ |
| Mode réseau faible : indicateur, reprises, dernières données, cache statique, aucune fausse confirmation | ✅ |
| Temps réel par interrogation incrémentale | ✅ ; WebSocket ❌ (évolution prévue) |
| Applications Android natives | ❌ (API prête avec jetons Bearer) |
