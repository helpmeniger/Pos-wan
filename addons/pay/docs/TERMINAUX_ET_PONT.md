# Terminaux de paiement et pont local

## Pilotes
| Pilote | Liaison | Fonctionnement |
|---|---|---|
| TPE simulé | — | Formation et tests (TEST, aucun encaissement) |
| TPE autonome | aucune | Le caissier saisit le n° d'autorisation ; « À vérifier » jusqu'au rapprochement bancaire |
| TPE réseau | Ethernet, Wi-Fi, TCP/IP | Messages JSON d'une ligne (si le fabricant documente une telle interface) |
| TPE USB / COM / Bluetooth | via le pont local | Le pont dialogue avec le port série du poste de caisse |
| Terminal Android | API HTTP locale | Si le terminal expose une API locale documentée (HTTP accepté seulement vers une adresse privée) |

La plupart des TPE bancaires utilisent un protocole propriétaire fourni sous contrat (souvent avec un SDK) : un pilote dédié ou un adaptateur
du fabricant est alors nécessaire. **Le code PIN est toujours saisi sur le terminal.** Seuls les 4 derniers chiffres de la carte peuvent être conservés.

## Pont local « Marwa Pay Bridge » (Windows)
Pourquoi : POS Marwa s'exécute sur le serveur ; il ne peut pas ouvrir l'USB, le port COM ou le Bluetooth du poste de caisse.
1. Copier le dossier `addons/pay/bridge` sur le poste où le terminal est branché (PHP de WampServer ou PHP portable requis).
2. POS Marwa → Marwa Pay → Gestion des terminaux → terminal « USB / COM / Bluetooth » : port (`COM3`), adresse du pont (`http://IP-DU-POSTE:8765`)
   → **Générer le secret du pont** (affiché une seule fois).
3. Copier `bridge.example.json` en `bridge.json` : `secret` = le secret généré, `allowed_devices` = `["COM3"]`,
   `listen` = `0.0.0.0:8765` si le serveur POS Marwa est une autre machine (limiter l'accès au seul serveur dans le pare-feu Windows), sinon `127.0.0.1:8765`.
4. Lancer `demarrer-pont-paiement.bat` ; pour un démarrage automatique : Planificateur de tâches → « À l'ouverture de session ».
5. POS Marwa → **Tester la communication**, puis **Activer**, et affecter le terminal à la caisse.
Bluetooth : appairer le terminal dans Windows ; il apparaît comme un port COM virtuel à déclarer dans `allowed_devices`.

Sécurité du pont : signature HMAC-SHA256 de chaque requête (horodatage ± 60 s, nonce à usage unique), seuls les ports déclarés sont ouverts,
aucune donnée n'est conservée par le pont. Si le secret fuit, régénérez-le dans POS Marwa et dans `bridge.json`.
