# Migrations

Placez ici les évolutions du schéma sous la forme `AAAAMMJJ_HHMM_description.sql`.
`php scripts/install_db.php` (ou `scripts\update.bat`) applique dans l'ordre les fichiers
non encore enregistrés dans la table `schema_migrations`. Ne modifiez jamais une migration
déjà appliquée : créez-en une nouvelle.
