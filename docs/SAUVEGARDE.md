# Sauvegarde et restauration

## Ce qu'il faut sauvegarder

1. **La base MySQL/MariaDB** (commandes, paiements, grand livre, audit…).
2. **`storage/uploads/`** (documents des prestataires, photos, preuves de livraison).
3. **`.env`** (secrets) — à conserver séparément, de façon chiffrée.

## Sauvegarde

```bash
php bin/backup.php --keep=14
# WampServer :
php bin\backup.php --mysqldump="C:\wamp64\bin\mysql\mysql8.x.y\bin\mysqldump.exe"
```

Produit dans `storage/backups/` : `marwa-db-AAAAMMJJ-HHMMSS.sql.gz` (avec triggers,
`--single-transaction` donc sans bloquer l'application) et `marwa-uploads-AAAAMMJJ-HHMMSS.zip`.
Les fichiers au-delà de `--keep` sauvegardes sont supprimés.

Planifier chaque nuit (cron ou Planificateur de tâches Windows) et **copier les fichiers hors du
serveur** (autre machine, stockage externe chiffré). Une sauvegarde restée sur le même disque ne
protège pas d'une panne ou d'un piratage.

## Restauration

```bash
# 1. Mettre l'application en maintenance (arrêter le worker, couper le site)
# 2. Recréer la base
mysql -u root -p -e "DROP DATABASE IF EXISTS marwa; CREATE DATABASE marwa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c storage/backups/marwa-db-20261012-021500.sql.gz | mysql -u root -p marwa
# 3. Restaurer les fichiers
unzip -o storage/backups/marwa-uploads-20261012-021500.zip -d storage/uploads/
# 4. Appliquer d'éventuelles migrations plus récentes, contrôler
php bin/migrate.php
```

Sous Windows : décompresser le `.sql.gz` (7-Zip), puis importer avec
`mysql.exe -u root marwa < fichier.sql` ou via phpMyAdmin (fichiers volumineux : ligne de commande).

Après restauration, vérifier dans l'administration : *Paramètres* → balance du grand livre
« équilibrée », *Paiements* → rapprochement, et réaliser une commande de test.

**Tester une restauration complète au moins une fois par trimestre** sur une machine séparée.

## Conservation

Les écritures financières validées, paiements, factures et journaux d'audit ne doivent pas être
supprimés (triggers d'immuabilité). Les données personnelles des comptes supprimés sont
anonymisées. Les positions GPS détaillées sont purgées automatiquement après
`tracking.history_retention_days` (30 jours par défaut, paramétrable). Les durées légales de
conservation comptable et des données doivent être confirmées (voir CONFORMITE.md).
