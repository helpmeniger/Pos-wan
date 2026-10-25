# JOB NIGER

**« Un travail, une opportunité, un revenu. »**

Plateforme web de mise en relation pour petits boulots, missions ponctuelles et emplois temporaires au Niger
(Niamey, Maradi, Zinder, Tahoua, Agadez, Dosso, Diffa, Tillabéri) : particuliers, commerces, restaurants,
hôtels, entreprises, ONG et écoles y trouvent des prestataires ; étudiants, jeunes diplômés, artisans et
travailleurs indépendants y trouvent des missions rémunérées.

Monnaie : XOF (FCFA) · Fuseau : Africa/Niamey · Langue : français (haoussa, zarma, anglais prévus)
Stack : PHP 8.3 (MVC), MySQL 8 / MariaDB 10.6+, Bootstrap 5, WampServer en développement.

## État du projet

| Phase | Statut |
|---|---|
| 1 — Conception (architecture, règles métier, finance, schéma SQL) | ✅ |
| 2 — Socle : comptes, connexion, rôles et permissions, sécurité, tableaux de bord de base | ✅ |
| 3 — Annonces, candidatures, recherche, messagerie, négociation, missions, avis | à venir |
| 4 — Paiements, commissions, grand livre, remboursements, versements, rapports | à venir |
| 5 — Administration complète · 6 — Sécurité et qualité · 7 — Déploiement | à venir |

**Fonctionnel aujourd'hui :** site public (accueil, 21 domaines / 221 métiers, aide, contact → ticket, CGU et
confidentialité en brouillon), inscription prestataire / particulier / organisation, connexion par téléphone ou e-mail,
mot de passe oublié, vérification du téléphone par code SMS (pilote de développement), changement de mot de passe,
rôles et permissions, verrouillage anti-force brute, tableaux de bord prestataire / demandeur / administration
alimentés par la base, notifications internes, API JSON des référentiels, console d'exploitation.

**Installation sous WampServer :** décompressez le projet dans `C:\wamp64\www\job-niger`, ouvrez
<http://localhost/job-niger/> : l'assistant d'installation fait le reste (base, tables, administrateur, configuration).
Détails, installation manuelle et mise en ligne : **[INSTALLATION.md](INSTALLATION.md)**.

| Document | Contenu |
|---|---|
| [docs/01-ARCHITECTURE.md](docs/01-ARCHITECTURE.md) | Choix techniques, arborescence, sécurité, évolutivité |
| [docs/02-REGLES-METIER.md](docs/02-REGLES-METIER.md) | Rôles, vérifications, workflows annonces / missions / litiges / avis |
| [docs/03-SYSTEME-FINANCIER.md](docs/03-SYSTEME-FINANCIER.md) | Commissions, modes de paiement, grand livre, remboursements, versements |
| [docs/04-PLAN-DES-PHASES.md](docs/04-PLAN-DES-PHASES.md) | Plan, fichiers prévus, intégrations externes à configurer |
| [database/schema.sql](database/schema.sql) | Schéma MySQL complet et exécutable |

## Démarrage rapide

```bash
composer install
cp .env.example .env            # renseigner DB_* puis :
php bin/console key:generate
php bin/console migrate         # schéma + données de référence + migrations
php bin/console admin:create --phone="90 00 00 01" --first=Prénom --last=Nom
php -S 127.0.0.1:8080 -t public public/index.php   # ou VirtualHost Apache (voir INSTALLATION.md)
vendor/bin/phpunit              # 93 tests (base dédiée job_niger_test), validés sur MariaDB 10.11 et MySQL 8.0
```

## Avertissement

Les paiements intégrés (Mobile Money, carte) ne sont **pas** actifs : ils exigent des contrats avec des
prestataires habilités et une validation réglementaire. Les CGU et la politique de confidentialité doivent
être validées juridiquement avant toute mise en production.
