# Sauvegarde et restauration

## Contenu et protection d'une sauvegarde

Une sauvegarde (`storage\backups\dossier_id_AAAAMMJJ_HHMMSS_xxxx.didbak`) contient :

- toutes les tables de la base (fiches, passages, procédures, comptes, permissions, journal d'audit…) ;
- toutes les photographies et tous les documents (sous leur forme chiffrée) ;
- un manifeste : version du schéma, date, empreinte SHA-256 de chaque élément, empreinte de la clé de stockage.

L'archive est chiffrée en flux (XChaCha20-Poly1305) avec la clé `backup.key`. Les fichiers stockés
y restent chiffrés avec `storage.key`. **La restauration exige donc les deux clés.**

## Les clés : à protéger absolument

Répertoire par défaut : `C:\wamp64\dossier_id_keys\` (`storage.key`, `backup.key`).

- Copiez ces deux fichiers **dès l'installation** sur un support amovible chiffré, conservé dans un
  coffre ou un local sécurisé, **séparément** des sauvegardes.
- Sans `storage.key`, photographies et documents sont définitivement illisibles.
- Ne les envoyez jamais par messagerie et ne les placez pas dans `C:\wamp64\www`.

## Créer une sauvegarde

- **Interface** : Sauvegardes → *Créer une sauvegarde* (rôle Administrateur).
- **Ligne de commande** :

```bat
C:\wamp64\bin\php\php8.3.x\php.exe C:\wamp64\www\dossier_id\scripts\backup.php --create --keep=30
```

`--keep=30` conserve les 30 sauvegardes les plus récentes.

## Planifier une sauvegarde quotidienne (Planificateur de tâches Windows)

1. Planificateur de tâches → *Créer une tâche*.
2. Général : « Exécuter même si l'utilisateur n'est pas connecté ».
3. Déclencheur : tous les jours à 19 h 00.
4. Action : programme `C:\wamp64\bin\php\php8.3.x\php.exe`, arguments
   `scripts\backup.php --create --keep=30`, démarrer dans `C:\wamp64\www\dossier_id`.
5. Vérifier le lendemain dans *Sauvegardes* que le fichier existe. Le tableau de bord signale
   l'absence de sauvegarde de plus de 7 jours.

## Externaliser

Copiez régulièrement (au moins chaque semaine) les fichiers `.didbak` sur un support distinct
(disque externe, serveur de fichiers du service), conservé dans un autre local. Une sauvegarde
restée sur le même disque ne protège pas contre une panne ou un vol.

## Vérifier une sauvegarde

- Interface : bouton *Vérifier* (déchiffrement complet et contrôle de toutes les empreintes,
  sans rien modifier).
- Ligne de commande : `php scripts\backup.php --verify=NOM_DU_FICHIER.didbak`

## Restaurer

**Attention : la restauration remplace toutes les données actuelles.** Une sauvegarde de sécurité
de l'état courant (`…_avant-restauration.didbak`) est créée automatiquement juste avant.

- Interface : Sauvegardes → *Restaurer* (saisir `RESTAURER`), ou *Restaurer depuis un fichier*.
- Ligne de commande (recommandé pour les gros volumes) :

```bat
C:\wamp64\bin\php\php8.3.x\php.exe scripts\backup.php --restore=C:\chemin\dossier_id_xxx.didbak
```

Contrôles effectués avant toute modification : déchiffrement authentifié, empreinte de chaque table
et fichier, version de schéma identique, clé de stockage identique, chemins autorisés. En cas
d'échec, rien n'est modifié. Après restauration, tous les utilisateurs doivent se reconnecter et
l'opération est inscrite au journal d'audit. Les entrées du journal postérieures à la sauvegarde
restaurée figurent dans la sauvegarde de sécurité.

## Restauration sur un nouveau poste (reprise après sinistre)

1. Installer WampServer et copier l'application (voir INSTALLATION.md, étapes 1 à 5).
2. Copier `storage.key` et `backup.key` d'origine dans le répertoire `storage.key_dir`.
3. Exécuter `scripts\install.php` (crée le schéma ; les clés existantes sont conservées).
4. Restaurer : `php scripts\backup.php --restore=… --yes`.
5. Se connecter, vérifier quelques fiches et lancer *Vérifier l'intégrité* du journal d'audit.

## Procédure de test de restauration (à réaliser chaque trimestre)

Sur un **poste de test** (jamais sur le poste de production) :
1. restaurer la dernière sauvegarde externalisée selon la procédure ci-dessus ;
2. contrôler le nombre de fiches (tableau de bord), ouvrir 3 fiches avec photographies et documents ;
3. vérifier l'intégrité du journal d'audit ;
4. consigner la date, la sauvegarde testée et le résultat.

Cette procédure a été exécutée lors du développement (voir RAPPORT_TESTS.md).
