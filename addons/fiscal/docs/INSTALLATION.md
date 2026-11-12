# Marwa Fiscal Connect — Installation

## Prérequis
- POS Marwa 3.6.0 ou plus récent, PHP 8.1+ (testé en 8.3), MySQL 5.7+ ou MariaDB 10.4+ (testé sur MariaDB 10.11).
- Extensions PHP : `curl` et `openssl` (API, SSL, mTLS), `sodium` ou la bibliothèque incluse (chiffrement des secrets), `dom` (SOAP/XML).
- `APP_KEY` renseignée dans `.env` : elle sert à chiffrer les clés d'API. **Si elle change, les secrets doivent être saisis de nouveau.**
- Port série / USB : le dispositif doit être branché sur **la machine qui exécute POS Marwa** (pas sur le poste d'un caissier distant). Impossible sur un hébergement en ligne.

## Installation
1. Copier le dossier `addons/fiscal` dans `addons/` (déjà présent dans la version complète).
2. Menu **Système > Modules** : installer « Marwa Fiscal Connect ». Une sauvegarde est faite avant l'installation.
3. La migration crée 12 tables `fiscal_*`. **Aucune table existante n'est modifiée, aucune vente n'est touchée.**
4. Planifier le traitement de la file d'attente (toutes les 1 à 5 minutes) :
   - Windows (Planificateur de tâches) : `php C:\wamp64\www\pos-marwa\addons\fiscal\bin\fiscal-queue.php`
   - Linux (cron) : `* * * * * php /chemin/pos-marwa/addons/fiscal/bin/fiscal-queue.php`
   Le bouton « Traiter la file maintenant » (Factures en attente) fait la même chose à la demande.

## Ce qui change après l'installation
Rien tant qu'aucun **profil fiscal** n'est actif : les ventes, tickets et factures restent identiques.
Une fois un profil activé, chaque nouvelle vente de son périmètre est transmise au connecteur choisi.

## Désactivation et retour arrière
- Désactiver le module (Système > Modules) : les ventes ne sont plus transmises ; l'historique est conservé.
- Supprimer les tables : script manuel `addons/fiscal/sql/down.sql`, **uniquement** après une sauvegarde vérifiée,
  une confirmation écrite du responsable et la vérification de la durée légale de conservation des preuves fiscales.

## Données personnelles
Les factures transmises peuvent contenir des données clients (nom, NIF, téléphone). Leur traitement doit respecter
la loi n° 2017-28 du 3 mai 2017 relative à la protection des données à caractère personnel (Niger) ou la loi du pays d'exploitation.
