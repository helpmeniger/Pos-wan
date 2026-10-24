# Module Hôtel — POS Marwa

Gestion d'un hôtel, d'une auberge ou d'une résidence meublée : réception, chambres, réservations,
séjours, facturation, ménage et rapports. Les paiements passent par la caisse ouverte, comme les
ventes : la clôture de caisse et la comptabilité restent justes.

## Installation

1. **Système & maintenance → Modules complémentaires → Hôtel → Installer.**
   - Le module crée 4 types de chambres, 12 chambres sur deux étages et un catalogue de prestations.
   - Tout cela est modifiable dans **Hôtel → Chambres, tarifs et réglages**.
2. (Facultatif) **Paramètres → Mode de fonctionnement → Hôtel** :
   - les menus des autres activités (clinique, pressing) sont masqués ;
   - la caisse reste disponible pour le bar et le restaurant.
3. Donnez les rôles :
   - **Réceptionniste (hôtel)** : réservations, arrivées, départs, encaissements, ménage ;
   - **Ménage (hôtel)** : état des chambres seulement ;
   - le **gérant** et le **propriétaire** reçoivent tous les droits.

## Les écrans

| Écran | À quoi il sert |
|---|---|
| **Tableau de bord hôtel** | Occupation du jour, arrivées et départs à traiter, mini-plan des chambres, encaissements, prix moyen (ADR), RevPAR, soldes à recouvrer, audit de nuit. |
| **Plan des chambres (écran)** | Plein écran, actualisé toutes les 20 s, thème clair ou sombre. Une tuile par chambre, rangée par étage, colorée selon l'état : libre, à nettoyer, occupée, arrivée attendue, départ du jour, départ dépassé, hors service. Filtres par état, étage, type ; recherche. Un clic ouvre le panneau de la chambre : installer un client, réserver, enregistrer l'arrivée, ouvrir le séjour, ajouter une prestation, départ, ménage. |
| **Nouvelle réservation** | Dates, nombre de nuits, personnes. Les types de chambres s'affichent avec le nombre de chambres libres et le prix. Choix de la chambre (ou attribution à l'arrivée), recherche d'un client déjà venu, devis en direct, acompte. |
| **Client sans réservation** | Même formulaire : le client est installé tout de suite. Pièce d'identité exigée. |
| **Fiche du séjour** | Client, chambre, dates, folio (nuitées, taxe de séjour, prestations, remises), paiements, historique. Selon l'état : arrivée, prestations, encaissement, changement de chambre, prolongation, départ, annulation, non-présentation, remboursement d'un trop-perçu. |
| **Planning des chambres** | Une ligne par chambre, une colonne par jour (7 à 31 jours). Les séjours apparaissent en barres colorées. Une case libre permet de réserver la chambre à cette date. Les réservations sans chambre sont signalées. |
| **Ménage des chambres** | Pour le téléphone de la gouvernante. Chambres à faire d'abord, en priorité celles où un client arrive. Boutons « Commencer » → « Terminé » → « Inspectée ». |
| **Clients de l'hôtel** | Fiches clients avec pièce d'identité, VIP, client refusé ; historique des séjours, nuits, montant dépensé. |
| **Registre des voyageurs** | Arrivées de la période avec l'identité complète, export CSV ou PDF pour les autorités. |
| **Rapport hôtel** | Taux d'occupation nuit par nuit, chiffre d'affaires de l'hébergement et des prestations, ADR, RevPAR, répartition par type de chambre, origine des réservations, encaissements par mode. Export CSV ou PDF. |
| **Chambres, tarifs et réglages** | Types de chambres (prix, prix week-end, capacité, équipements, couleur), chambres, prestations et réglages. |

**Réglages disponibles**

- Heures d'arrivée et de départ.
- Taxe de séjour par adulte et par nuit.
- Départ tardif, en % de la nuitée.
- Pièce d'identité obligatoire.
- Chambre propre exigée.
- Conditions imprimées sur la facture.

## Les règles (vérifiées par le serveur)

### Disponibilité

- Une chambre ne peut pas être prise deux fois sur les mêmes nuits.
  - Un client resté après sa date de départ occupe toujours sa chambre.
- Une chambre hors service n'est pas proposée.
  - Elle revient en service toute seule à la date indiquée.
  - Une chambre occupée ne peut pas être mise hors service.

### Arrivée

- La pièce d'identité est exigée (type et numéro), sauf si le réglage est désactivé.
- La chambre doit être propre ou inspectée. Le responsable peut passer outre.
- Une arrivée anticipée ou tardive démarre le séjour le jour même.

### Prix

- Le prix vient du type de chambre. Le tarif week-end s'applique aux nuits du vendredi et du samedi.
- Un tarif négocié (prix modifié) est réservé au responsable.
- Une remise est réservée au responsable ou au caissier qui a le droit de remise.
- Le prix d'une prestation du catalogue ne se modifie que par le responsable.

### Nuitées

- Elles sont portées au folio nuit par nuit, avec la taxe de séjour éventuelle :
  - par l'**audit de nuit** (bouton du tableau de bord, à faire chaque matin) ;
  - lors d'un **changement de chambre** ;
  - au **départ**.
- L'audit relancé ne porte rien en double.
- Les nuitées futures sont affichées « estimées » et ne sont pas facturées d'avance.

### Départ

- Une nuit au moins est facturée, même pour un départ le jour de l'arrivée.
- Le solde doit être payé.
  - Seul le responsable peut laisser partir un client avec un solde, en indiquant un motif.
  - Le séjour apparaît alors dans « Soldes à recouvrer » et peut être réglé plus tard.
- Un trop-perçu doit être remboursé avant le départ.
- La facture reçoit un numéro (FH2026-000001…).
- La chambre passe « À nettoyer ».

### Paiements

- En espèces, la monnaie est rendue.
- Un paiement électronique ne peut pas dépasser le reste dû.
- Chaque paiement crée un mouvement de caisse :
  - « hotel » pour un encaissement ;
  - « hotel_refund » pour un remboursement.
- La caisse doit être ouverte (même règle que les ventes).

### Annulation

- Seulement avant l'arrivée, motif obligatoire.
- L'acompte est soit remboursé en espèces, soit conservé comme frais d'annulation.
- « Client non présenté » à partir du jour d'arrivée prévu ; l'acompte est conservé.

## Comptabilité

Le module Comptabilité reprend les mouvements de caisse « hotel » et « hotel_refund » comme des
prestations de services encaissées : source « Recettes des modules ».

## Données personnelles

La fiche client et le registre des voyageurs contiennent des données personnelles :

- identité ;
- pièce d'identité ;
- provenance et destination.

Ces données sont soumises à la loi nigérienne n° 2017-28 du 3 mai 2017 relative à la protection
des données à caractère personnel :

- le registre et son export sont réservés au droit « Hôtel : chambres, tarifs… » (responsable) ;
- ne communiquez ces données qu'aux autorités habilitées ;
- conservez-les seulement le temps exigé par la réglementation locale.

La fiche de police imprimée rappelle cette finalité.

## Limites connues (version 1.0.0)

- Pas de connexion aux plateformes de réservation en ligne (Booking, Expedia…).
  - Ces réservations se saisissent à la main, origine « Site Internet / plateforme ».
- Pas de serrures électroniques ni d'encodage de cartes-clés.
- Les consommations du bar ou du restaurant (caisse POS) ne sont pas encore reportées
  automatiquement sur la chambre. Ajoutez-les au folio comme prestation (« Restauration »).
- Pas de facturation groupée de plusieurs chambres sur une seule facture (groupes, séminaires) :
  une facture par séjour.
- La TVA n'est pas calculée séparément sur les nuitées. Les prix saisis sont des prix toutes taxes
  comprises.
- L'audit de nuit ne se lance pas tout seul : il faut cliquer sur le bouton.
  - Les nuitées sont de toute façon portées au départ ou au changement de chambre.
