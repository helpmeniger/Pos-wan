# Marwa Pay Connect — Installation

## Prérequis
- POS Marwa 3.8.0 ou plus récent, PHP 8.1+ (testé en 8.3), MySQL 5.7+ ou MariaDB 10.4+ (testé sur 10.11).
- Extensions PHP : `curl`, `openssl`, `sodium` (ou la bibliothèque incluse), `dom` (SOAP).
- `APP_KEY` dans `.env` : elle chiffre les clés des prestataires. **Si elle change, ressaisissez les secrets.**
- Communications externes en **HTTPS** obligatoire en production.

## Installation
1. Menu **Système > Modules** : installer « Marwa Pay Connect » (une sauvegarde est faite avant).
2. La migration crée 13 tables `payment_*`. **Aucune table existante n'est modifiée ; aucune vente n'est touchée.**
3. Planifier la vérification des paiements en attente (toutes les 1 à 5 minutes) :
   - Windows : Planificateur de tâches → `C:\wamp64\bin\php\php8.x\php.exe C:\wamp64\www\pos-marwa\addons\pay\bin\pay-sweep.php`
   - Linux : `* * * * * php /chemin/pos-marwa/addons/pay/bin/pay-sweep.php`
4. Donner le droit **pay.use** aux caissiers concernés (accordé par défaut au rôle Caissier).

Tant qu'aucun moyen de paiement n'est activé, la caisse reste identique : le bouton « PAIEMENT ÉLECTRONIQUE » n'apparaît que si le module est actif et l'utilisateur autorisé, et il n'affiche que les moyens actifs pour sa caisse.

## Terminal branché sur un poste de caisse (USB, COM, Bluetooth)
Voir `TERMINAUX_ET_PONT.md` : installation du pont local « Marwa Pay Bridge » sur le poste concerné.

## Désinstallation
- Désactiver le module : la caisse n'affiche plus le bouton ; l'historique est conservé.
- Supprimer les tables : `addons/pay/sql/down.sql`, **uniquement** après sauvegarde vérifiée et accord écrit du responsable
  (les transactions et remboursements sont des pièces comptables).

## Données personnelles
Le module conserve le minimum : téléphone du payeur masqué, aucune donnée de carte. Le traitement des données des payeurs
relève de la loi n° 2017-28 du 3 mai 2017 (Niger) ou de la loi du pays d'exploitation.
