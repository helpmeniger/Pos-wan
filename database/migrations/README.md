# Migrations

- `database/schema.sql` crée le schéma initial (version `2026_10_16_000001_initial_schema`).
- Chaque fichier `AAAA_MM_JJ_NNNNNN_description.sql` de ce dossier est appliqué **une seule fois**, dans l'ordre
  alphabétique, par `php bin/console migrate`, qui l'enregistre dans la table `schema_migrations`.
- Ne jamais modifier une migration déjà appliquée : en créer une nouvelle.
- Import manuel (phpMyAdmin) : importer `schema.sql`, les seeders, puis chaque migration dans l'ordre, et insérer
  la version correspondante dans `schema_migrations`.
