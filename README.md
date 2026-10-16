# JOB NIGER

**« Un travail, une opportunité, un revenu. »**

Plateforme web de mise en relation pour petits boulots, missions ponctuelles et emplois temporaires au Niger
(Niamey, Maradi, Zinder, Tahoua, Agadez, Dosso, Diffa, Tillabéri) : particuliers, commerces, restaurants,
hôtels, entreprises, ONG et écoles y trouvent des prestataires ; étudiants, jeunes diplômés, artisans et
travailleurs indépendants y trouvent des missions rémunérées.

Monnaie : XOF (FCFA) · Fuseau : Africa/Niamey · Langue : français (haoussa, zarma, anglais prévus)
Stack : PHP 8.3 (MVC), MySQL 8 / MariaDB 10.6+, Bootstrap 5, WampServer en développement.

## État du projet

**Phase 1 — Conception : livrée.** Les phases 2 à 7 (code applicatif) restent à développer.
Ce dépôt ne contient pas encore d'application exécutable.

| Document | Contenu |
|---|---|
| [docs/01-ARCHITECTURE.md](docs/01-ARCHITECTURE.md) | Choix techniques, arborescence, sécurité, évolutivité |
| [docs/02-REGLES-METIER.md](docs/02-REGLES-METIER.md) | Rôles, vérifications, workflows annonces / missions / litiges / avis |
| [docs/03-SYSTEME-FINANCIER.md](docs/03-SYSTEME-FINANCIER.md) | Commissions, modes de paiement, grand livre, remboursements, versements |
| [docs/04-PLAN-DES-PHASES.md](docs/04-PLAN-DES-PHASES.md) | Plan, fichiers prévus, intégrations externes à configurer |
| [database/schema.sql](database/schema.sql) | Schéma MySQL complet et exécutable |

## Essayer le schéma

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p < database/seeders/001_reference_data.sql
mysql -u root -p < database/seeders/002_job_catalogue.sql
# Test de fumée (base jetable job_niger_test) :
tests/Database/run_schema_smoke.sh -u root -p
```
Sous WampServer : phpMyAdmin → Importer, dans le même ordre.

## Avertissement

Les paiements intégrés (Mobile Money, carte) ne sont **pas** actifs : ils exigent des contrats avec des
prestataires habilités et une validation réglementaire. Les CGU et la politique de confidentialité doivent
être validées juridiquement avant toute mise en production.
