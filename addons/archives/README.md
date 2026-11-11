# Module Archives et documents (1.0.0)

Archivage des documents de l'entreprise ou de l'organisation. Requiert POS Marwa 3.5.0.
Installation : Configuration → Modules complémentaires → « Archives et documents » → Installer. Le menu **Archives** apparaît.

## Ce que fait le module
- **Dossiers et sous-dossiers** à volonté, avec fil d'Ariane ; dossiers confidentiels.
- **Dépôt** d'un ou plusieurs fichiers à la fois : PDF, images (JPG, PNG, WEBP, GIF), Word, Excel, PowerPoint, OpenDocument, texte, CSV, ZIP.
- **Fiche du document** : titre, référence, catégorie, date, **échéance** (fin de validité d'un contrat, d'un agrément…), étiquettes, description.
- **Recherche** dans toutes les archives : titre, référence, étiquette, description, nom du fichier ; filtre par catégorie.
- **Versions** : un nouveau fichier pour un même document devient la version suivante ; les précédentes restent téléchargeables.
- **Aperçu** des PDF et des images dans la page ; téléchargement des autres types.
- **Intégrité** : chaque fichier garde son empreinte SHA-256 ; le bouton « Vérifier l'intégrité » signale un fichier modifié ou endommagé ; un doublon de contenu est signalé au dépôt.
- **Échéances** : liste des documents qui arrivent à échéance ou l'ont dépassée (réglage `archives_alerte_jours`, 30 par défaut).
- **Corbeille** : un document supprimé est restaurable. La suppression définitive est réservée (`archives.admin`), se fait depuis la corbeille et demande de retaper le titre.
- **Journal** : dépôt, consultation, affichage, téléchargement, modification, mise à la corbeille sont inscrits au journal d'audit et affichés sur la fiche.
- Registre exportable en CSV ou PDF.

## Sécurité
- Types acceptés par liste blanche : l'extension **et** le contenu réel du fichier sont contrôlés ; aucun fichier exécutable (PHP, HTML, script).
- Les fichiers sont rangés dans `storage/archives/AAAA/MM/` sous un nom aléatoire, **hors du dossier public** : ils ne sont accessibles que par POS Marwa, après contrôle des droits.
- Un document confidentiel (ou rangé dans un dossier confidentiel) n'est visible qu'avec le droit `archives.confidential`.
- Taille maximale par fichier : réglage `archives_taille_max_mo` (20 Mo), dans la limite du serveur (`upload_max_filesize`, `post_max_size` de PHP).

## Droits
`archives.view`, `archives.upload`, `archives.manage`, `archives.confidential`, `archives.admin`. Rôle créé : **Archiviste**.

## Limites
- Les fichiers ne sont pas chiffrés sur le disque : protégez le serveur et ses sauvegardes.
- **Sauvegarde** : la sauvegarde de POS Marwa couvre la base de données et les photos ; copiez aussi le dossier `storage/archives` (clé USB, disque externe). La synchronisation en ligne ne copie pas ces fichiers.
- Pas de recherche dans le texte des documents (contenu des PDF), pas de numérisation directe depuis un scanner : numérisez en PDF puis déposez.
- Données personnelles (loi n° 2017-28) : ne conservez que les documents nécessaires, pour la durée utile.
