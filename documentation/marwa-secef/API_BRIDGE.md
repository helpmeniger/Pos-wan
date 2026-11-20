# API du pont MARWA SECEF (`addons/fiscal/pont-secef/serveur.js`)

Service local : `http://127.0.0.1:8760` (port : `PONT_SECEF_PORT`). Toutes les requêtes portent l'en-tête
`X-Pont-Jeton` (jeton créé dans POS Marwa, ≥ 32 caractères). Refusées : jeton absent ou faux (401), autre nom
d'hôte que 127.0.0.1/localhost (403, protection contre le « DNS rebinding »), en-tête `Origin` présent (403 :
jamais appelé par une page web). Corps JSON (1 Mo au plus). Les identifiants e-SECeF (`acces`) ne sont ni écrits
sur disque ni journalisés.

| Méthode et chemin | Corps | Réponse |
|---|---|---|
| `GET /etat` | — | `{ok, version, navigateur: arrete|demarre, session: fermee|inconnue|ouverte, travail, ecritures_bloquees}` |
| `POST /session/tester` | `{acces:{identifiant, mot_de_passe}, url_portail, fenetre_visible}` | `{ok, nim, message}` — connexion SyGMEF si nécessaire, ouverture de l'e-SFE |
| `POST /session/fermer` | `{url_portail}` | `{ok}` — « Se déconnecter » puis fermeture du navigateur |
| `POST /articles/lister` | `{acces, url_portail, fenetre_visible}` | `{ok, articles:[{designation, groupe, prix_ttc, prix_ht, mode, ts}]}` — lecture seule |
| `POST /articles/programmer` | `{acces, article:{designation, code, groupe, mode, prix}, …}` | `{ok, etat:"programme"}` — **écriture sur le portail** (rien si l'article existe déjà) |
| `POST /factures/soumettre` | `{acces, facture, certifier, creer_articles, attente_s, url_portail, fenetre_visible}` | état de la facture (ci-dessous) ; attend au plus `attente_s` (5–110 s), puis répond « en_cours » |
| `GET /factures/{clé}` | — | état de la facture, ou 404 si inconnue |

## Facture transmise (`facture`)

```json
{ "cle": "<sha256 de la vente>", "reference_pos": "V-000123", "type": "FV", "mode": "TTC",
  "client": {"type": "physique|morale", "nif": "", "nom": "", "adresse": "", "contact": ""} | null,
  "lignes": [{"product_id": 12, "code": "REF12", "designation": "…", "groupe": "H", "quantite": 2, "prix_unitaire": 1500, "montant": 3000}],
  "paiement": "ESPECES", "total": 3000, "commentaire": "Réf. POS Marwa V-000123", "reference_origine": null }
```

## État d'une facture

```json
{ "ok": true, "cle": "…", "etat": "…", "message": "…", "reessayable": false, "certification_envoyee": false,
  "resultat": {"total": 3000, "repartition": ["H - 0% …"], "code_secef": "…", "nim": "…", "compteurs": "…", "heure": "…", "isf": "…", "pdf": "…"} }
```

| `etat` | Signification | Suite dans POS Marwa |
|---|---|---|
| `recue`, `en_cours` | traitement en cours | attente ; état redemandé (aucun second envoi) |
| `preparee` | remplie, vérifiée, **annulée sans certifier** (certification non autorisée) | échec explicite « préparée » ; rien de certifié |
| `certifiee` | Code SECeF/DGI et NIM lus à l'écran après CERTIFIER | certifiée (preuve conservée) |
| `incertaine` | CERTIFIER a pu partir, résultat non relevé | jamais renvoyée ; vérification dans le Journal de l'e-SFE |
| `erreur` | problème avant CERTIFIER (article absent, écart de total, réseau…) | corrigeable ; renvoi sans risque |
| `identifiants` | NIF ou mot de passe refusé (aucune nouvelle tentative automatique) | administrateur |
| `intervention` | vérification humaine demandée (code, CAPTCHA, opérateur manquant) | fenêtre du pont visible |

`certification_envoyee` indique si le bouton CERTIFIER a été actionné : tant qu'il vaut `false`, rien n'existe sur le
portail et la facture peut être renvoyée.
