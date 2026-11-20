# Pont MARWA SECEF CONNECT

Service local (Node.js + Playwright) entre POS Marwa et l'application e-SFE de l'e-SECeF (DGI Niger).
Documentation complète : `documentation/marwa-secef/` (API : `API_BRIDGE.md`, installation : `GUIDE_INSTALLATION.md`).

- Démarrage (Windows) : `demarrer-pont-secef.bat` (jeton dans `jeton.txt`).
- Démarrage (autres) : `PONT_SECEF_JETON=… node serveur.js`.
- Variables : `PONT_SECEF_PORT` (8760), `PONT_SECEF_DONNEES` (./donnees), `PONT_SECEF_NAVIGATEUR` (msedge | chrome | chromium | chemin),
  `PONT_SECEF_PROXY`, `PONT_SECEF_DELAI_CHARGEMENT` (s), `PONT_SECEF_VERBEUX`.
- Essais : `npm test` (sans portail).

Sécurité : écoute sur 127.0.0.1 seulement, jeton obligatoire, identifiants jamais écrits, écritures vers le portail
bloquées sauf « programmer un article » et « CERTIFIER ».
