#!/usr/bin/env bash
# MARWA GUARDIAN — construction des exécutables Windows (64 bits), sans runtime à installer.
#   guardian/construire.sh <dossier de sortie> [version]
#   MarwaGuardian.exe                service, installation, commandes (mode console)
#   MarwaGuardian-Notificateur.exe   notificateur et console (mode fenêtre : aucune console noire)
set -euo pipefail
cd "$(dirname "$0")"
OUT=$(mkdir -p "${1:?dossier de sortie}" && cd "$1" && pwd)
VER=${2:-$(date +%Y.%m.%d)}
export GOTOOLCHAIN=local CGO_ENABLED=0
go test ./... >/dev/null 2>&1 || { echo "Tests en échec : construction annulée" >&2; go test ./...; exit 1; }
GOOS=windows GOARCH=amd64 go vet ./...
export GOOS=windows GOARCH=amd64
go build -trimpath -ldflags "-s -w -X main.version=$VER" -o "$OUT/MarwaGuardian.exe" .
go build -trimpath -ldflags "-s -w -H windowsgui -X main.version=$VER" -o "$OUT/MarwaGuardian-Notificateur.exe" .
( cd "$OUT" && sha256sum MarwaGuardian.exe MarwaGuardian-Notificateur.exe > SHA256SUMS.txt && cat SHA256SUMS.txt )
