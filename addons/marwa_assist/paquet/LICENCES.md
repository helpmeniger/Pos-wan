# Programmes livrés avec MARWA REMOTE ASSIST (dossier `addons/marwa_assist/paquet`)

Programmes **de tiers, non modifiés**, distribués tels que publiés par leurs auteurs. Ils sont
seulement découpés en fragments de 1 Mo pour passer par la mise à jour de POS Marwa, puis
reconstitués à l'identique (empreinte SHA-256 vérifiée) dans `storage/assist/`. POS Marwa les
exécute comme des programmes séparés : ils ne font pas partie du code de POS Marwa.

| Programme | Version | Licence | Code source |
|---|---|---|---|
| RustDesk (client Windows, `client/rustdesk.exe`) | 1.5.0 | **AGPL-3.0** — `licences/rustdesk-AGPL-3.0.txt` | https://github.com/rustdesk/rustdesk/tree/1.5.0 |
| RustDesk Server OSS (`hbbs`, `hbbr`, Windows et Linux) | 1.1.16 | **AGPL-3.0** — même texte | https://github.com/rustdesk/rustdesk-server/tree/1.1.16 |

Fichiers d'origine (empreintes SHA-256) :
- `rustdesk-1.5.0-x86_64.exe` : 8555777215510d83d2d61c9dc984e4fcc838bd7e79f9d18a42585431f5e8bb47
- `rustdesk-server-windows-x86_64-unsigned.zip` (1.1.16) : b865a3a62fc8755b45480c508f1c4871c3338590408dda8c58c7e9c373b7adb0
- `rustdesk-server-linux-amd64.zip` (1.1.16) : 0565c41affe6c3f409b0bddfaf5a24ccbf3f64f5f8e3fec250b69a0d5f6bdbcf

« RustDesk » est le nom du projet de ses auteurs (Purslane Ltd.) ; POS Marwa ne le renomme pas et
ne le modifie pas. Toute modification de ces programmes devrait être publiée sous la même
licence AGPL-3.0.
