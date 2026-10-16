#!/usr/bin/env bash
# Crée une base jetable "job_niger_test", importe le schéma et les seeders,
# puis exécute le test de fumée. Usage : tests/Database/run_schema_smoke.sh [options mysql]
# Exemple : tests/Database/run_schema_smoke.sh -u root -p
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DB="job_niger_test"
MYSQL=(mysql "$@")

"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS ${DB}"
for f in database/schema.sql database/seeders/001_reference_data.sql database/seeders/002_job_catalogue.sql; do
  sed -e "s/\bjob_niger\b/${DB}/g" "$ROOT/$f" | "${MYSQL[@]}"
done
OUT="$("${MYSQL[@]}" -t "$DB" < "$ROOT/tests/Database/schema_smoke.sql")"
echo "$OUT"
"${MYSQL[@]}" -e "DROP DATABASE ${DB}"
grep -q "SUCCES" <<<"$OUT"
