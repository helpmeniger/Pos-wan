# Tests

## 1. Tests automatisés fournis

| Suite | Commande | Ce qui est vérifié |
|---|---|---|
| Service IA (27 tests) | `cd ai-service && .venv\Scripts\python -m pytest tests` | ligne virtuelle (sens, hystérésis anti-doublon, aller-retour, extrémités du segment, suivi trop court, perte de suivi), suivi (identité stable, occultation, deux objets, pas de transfert d'identité en sens inverse, suppression), préparation/validation sur jeu de test distinct, rejet des objets inconnus, photos insuffisantes, comptage vidéo de bout en bout, travailleur caméra (statuts, images et extraits écrits), API (clé, SSRF, traversée de chemins, préparation, activation, détection sur image) |
| Application complète (82 vérifications) | `python tests\e2e_test.py --base http://localhost/smart-guard-ai --password ... --synthetic C:\temp\synth --ai-token <AI_CALLBACK_TOKEN>` | accès anonyme, CSRF, mot de passe erroné, 25 pages sans erreur PHP, produit, photo, fichier non image refusé, préparation IA + résultats + activation, reconnaissance d'une photo, objet inconnu, comptage d'une vidéo, caméra IP hors réseau refusée, mot de passe caméra masqué, ligne virtuelle, ingestion des événements, idempotence, chemin malveillant rejeté, alertes « sortie sans vente » et « objet non identifié », absence du mot « vol » dans les alertes, vente et rapprochement différé, vérification humaine, stocks, inventaire, rapprochement, rapport journalier, exports CSV/XLSX/PDF valides, compte caissier et 7 refus d'accès, sauvegarde, déconnexion |

Le test E2E nécessite le jeu synthétique (`ai-service\scripts\make_synthetic_dataset.py`) et les
données de démonstration (`php scripts\seed_demo.php --synthetic=...`). Il crée des données de
test préfixées « E2E » : exécutez-le sur une installation de test, pas en production.

## 2. Résultats réellement obtenus lors du développement

Environnement : Linux (conteneur), PHP 8.3.6, MariaDB 10.11, Python 3.13, OpenCV 5.0,
Apache 2.4 + mod_php (même disposition que WampServer : sous-dossier `/smart-guard-ai`,
`.htaccess`) et serveur PHP intégré.

* `pytest` : **27 réussis**.
* `tests/e2e_test.py` : **82/82 vérifications réussies**, via Apache et via le serveur intégré.
* Apache : `.env`, `app/`, `database/`, `storage/`, `ai-service/.env` → **403**.
* Sauvegarde puis restauration : données et mots de passe de caméra chiffrés intacts.
* Jeu **synthétique** (3 produits fictifs de couleurs/motifs très différents, 12 images chacun,
  9 images de test) : précision de validation 9/9, objet inconnu rejeté, vidéo de 600 images :
  3 sorties et 1 entrée comptées = valeurs attendues, aucun double comptage.

**Ce qui n'a PAS été testé** (pas de matériel dans l'environnement de développement) :
webcam USB réelle, caméra IP réelle, WampServer sous Windows (les scripts `.bat` n'ont pas été
exécutés), vrais produits. Les résultats synthétiques **ne prédisent pas** la précision sur de
vrais emballages : elle doit être mesurée chez vous avec la procédure ci-dessous. Aucun
pourcentage de précision réelle n'est donc annoncé.

## 3. Procédure de test de la reconnaissance (avec vos produits)

1. Choisir 5 à 10 produits, dont **au moins deux visuellement proches** (ex. deux marques de riz).
2. Pour chacun, 12 à 20 photos de référence variées (faces, distances, éclairages).
3. Prendre **séparément**, un autre jour ou dans un autre éclairage, 5 photos de test par produit
   et les marquer « Test (validation) » sur la fiche produit (elles ne servent jamais de référence).
4. Ajouter 10 photos d'objets **non enregistrés** (autres articles du magasin).
5. Lancer la préparation ; noter pour chaque produit : correctes, erronées, non identifiées,
   produit le plus confondu.
6. Tester les 10 objets non enregistrés (Apprentissage IA > Tester une image) : compter combien
   sont nommés à tort (**faux positifs**) — l'objectif est 0.
7. Calculer : précision = correctes / photos de test ; taux de faux positifs = erronées /
   (photos de test + objets inconnus). Ajuster les photos ou `min_confidence` et recommencer.

## 4. Procédure de validation des comptages

1. Installer la caméra de sortie, tracer la ligne, démarrer l'analyse.
2. Réaliser **50 passages contrôlés** (personne portant un article identifié), en notant sur une
   feuille l'heure, l'article et le sens : 30 sorties, 10 entrées, 5 allers-retours sur la ligne,
   5 passages avec deux articles ou un article partiellement caché.
3. Comparer avec **Sorties observées** (filtrer la période du test) :
   * sorties correctement comptées / 30 ;
   * entrées correctement comptées / 10 ;
   * **doubles comptages** (deux événements pour un passage) ;
   * passages manqués ;
   * événements ambigus / pertes de suivi ;
   * produit correctement identifié lors des sorties.
4. Rapprochement caisse : enregistrer en caisse 20 des 30 sorties, puis vérifier que 20 sorties
   sont « Vente rapprochée » et 10 « Aucune vente identifiée » (alertes à vérifier). Tester aussi
   une vente saisie 2 minutes **après** le passage et une vente annulée.
5. Recommencer à différentes heures (éclairage) et avec affluence. Conserver les résultats
   (date, version IA, réglages) : ils constituent la mesure réelle de fiabilité.

## 5. Tests de performance et de robustesse à réaliser sur site

| Test | Méthode | Attendu |
|---|---|---|
| Analyse prolongée | laisser tourner 8 h, noter mémoire du service IA (tableau de bord) | mémoire stable |
| Caméra déconnectée | débrancher la webcam / couper la caméra IP | notification « Caméra déconnectée », reprise automatique et « reconnectée » |
| Caméra masquée | couvrir l'objectif ~10 s | « Flux vidéo inutilisable » |
| Service IA arrêté | fermer sa fenêtre | redémarrage automatique (run_ai.bat) ; aucun événement perdu (outbox) |
| PHP/Apache arrêté | arrêter WampServer pendant des passages | événements renvoyés au redémarrage, sans doublon |
| Redémarrage du PC | redémarrer | service IA relancé (tâche planifiée), galerie active rechargée |
| Disque presque plein | baisser `storage.min_free_gb` au-dessus de l'espace libre | notification « Espace disque insuffisant », purge des médias non protégés |
| Charge | ajouter des caméras une par une | temps de traitement < 1000 / images analysées par seconde |
