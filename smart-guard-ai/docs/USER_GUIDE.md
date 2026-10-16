# Guide utilisateur

## Rôles

| Rôle | Peut notamment |
|---|---|
| Administrateur général | tout : magasins, comptes, droits, caméras, modèles IA, paramètres, journaux |
| Propriétaire | rapports et exports, produits, stocks, alertes, caméras autorisées, versions IA |
| Responsable de magasin | événements, vérification et clôture des alertes, mouvements, inventaires, caisse |
| Caissier | ventes, retours, consultation des produits (ses propres ventes) |
| Agent de surveillance | caméras, vérification/annotation/clôture des alertes |

Les droits se règlent dans **Utilisateurs > Droits par rôle** et sont vérifiés par le serveur.

## Tableau de bord

Indicateurs du jour (caméras en ligne/hors ligne, durée de surveillance, produits reconnus,
sorties observées, événements à vérifier et vérifiés, taux de reconnaissance **mesuré** sur les
photos de test), état du système (service IA, processeur, mémoire, GPU, disque, temps de
traitement) et graphiques. Actualisation automatique toutes les minutes.

## Enregistrer un article et préparer sa reconnaissance

1. **Produits > Nouveau produit** : nom, catégorie, marque, référence, code-barres, prix, unité,
   seuil d'alerte, stock initial, confiance minimale (par défaut 75 %).
2. Sur la fiche, ajouter des **photos de référence** (8 minimum, 15 recommandées) :
   * **Importer** des fichiers depuis l'ordinateur ;
   * **Smartphone** : ouvrir l'application sur le téléphone, bouton « Prendre une photo » ;
   * **Webcam** : onglet Webcam > Activer > Prendre la photo, en tournant l'article.
   Variez les faces (avant, arrière, côtés, dessus, dessous), les distances, l'éclairage et les
   variantes d'emballage. Supprimez ou marquez « Exclue » les photos floues.
3. Cliquer **« Enregistrer l'article et préparer sa reconnaissance IA »**. Le système contrôle les
   photos, construit la galerie, teste la reconnaissance sur des photos de test **distinctes** et
   affiche les résultats réels (précision, erreurs, produit le plus confondu).
4. Si les résultats sont satisfaisants, **Activer** la version. Sinon, ajouter des photos
   (notamment pour les produits confondus) et relancer.
5. **Apprentissage IA** : historique des versions, retour à une version précédente, test sur une
   image et sur une courte vidéo.

Quand la ressemblance est insuffisante, le système affiche **« Produit non identifié »** plutôt
que d'inventer un nom.

## Surveillance en direct

Grille des caméras (1, 2 ou 3 par ligne), rectangles et étiquettes « n° de suivi – produit –
confiance », lignes virtuelles, nombre d'objets suivis, fréquence de traitement. Boutons :
Pause de l'affichage, Afficher/masquer les détections, Analyse (marche/arrêt par caméra),
Capture d'image, Historique.

## Sorties observées et détections

* **Détections** : un objet suivi = une ligne (caméra, heure, produit ou « non identifié »,
  confiance, numéro de suivi, rectangle).
* **Sorties observées** : franchissements de ligne (sortie, entrée, ambigu, perte de suivi),
  résultat du rapprochement avec la caisse, lien vers l'alerte et l'image.

## Vérifier une alerte

1. **Alertes** : liste filtrable (période, caméra, produit, niveau, statut), triée par priorité.
2. Ouvrir l'alerte : identifiant, date, caméra, produit éventuel, confiance, indices, image de
   l'événement, **extrait vidéo** (bouton Lire), résultat du rapprochement.
3. Choisir une conclusion : En cours d'examen, Mouvement normal confirmé, Vente retrouvée, Erreur
   de détection, Anomalie confirmée après vérification (commentaire obligatoire), Non
   concluante, Clôturée. Facultatif : enregistrer un **effet sur le stock** (droit « stock »).

Une alerte, même prioritaire, **n'est pas une preuve**. Ne mettez jamais en cause une personne
sur la seule base d'une alerte ; le système n'identifie personne.

## Caisse

**Caisse** : scanner ou rechercher, ajuster les quantités, choisir le mode de paiement (espèces,
Mobile Money, carte, crédit), saisir le montant reçu (monnaie calculée), valider. Les ventes
mettent à jour le stock et sont rapprochées des sorties observées. **Ventes** : liste, ticket
imprimable, retour client (partiel), annulation avec motif. **Importer des ventes** : CSV d'un
logiciel de caisse tiers (voir aussi l'API).

## Stocks

* **Stocks** : stock théorique, seuils, saisie d'**inventaire physique** (écart enregistré et
  régularisation facultative).
* **Mouvements** : réception, retour client/fournisseur, transfert entre zones, casse, périmé,
  ajustement, sortie autorisée, mouvement observé (validé), mouvement non expliqué.
* **Rapprochement** : stock initial, entrées, ventes, sorties autorisées, stock théorique, stock
  physique, écart, et pour comparaison les sorties observées et non rapprochées.

Une détection vidéo ne modifie **jamais** le stock sans validation.

## Rapports

**Rapports** : période libre ou semaine/mois/année, filtres caméra/produit/niveau, comparaison
par jour, tableau par produit, fonctionnement des caméras (durée de surveillance, périodes sans
vidéo). Exports **PDF, Excel, CSV** et impression. **Rapports journaliers** : générés
automatiquement chaque nuit (tâche planifiée) ou à la demande.

Aucun « nombre de vols » n'est calculé : les ventes ne sont pas soustraites des sorties.

## Paramètres et maintenance

* **Paramètres** : fenêtres de rapprochement, règles des niveaux d'alerte, durées de conservation,
  quota de stockage, exigences de reconnaissance, texte d'information des personnes filmées.
* **Maintenance** : diagnostic, espace disque, purge des médias expirés, sauvegardes
  (téléchargement), journal d'activité, tentatives de connexion, journal du jour.
