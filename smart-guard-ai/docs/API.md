# API

## 1. Service IA (Python, `http://127.0.0.1:8765`)

Authentification : en-tête `X-API-Key: <AI_SERVICE_KEY>` sur toutes les routes sauf
`/health/public`. Erreurs : `401` clé invalide, `403` chemin interdit, `404`, `409` conflit,
`413` requête trop volumineuse, `422` paramètres invalides (`{"detail": ...}`), `503`.
Le navigateur n'appelle jamais ce service : seul PHP l'interroge.

| Méthode | Route | Description |
|---|---|---|
| GET | `/health/public` | `{"ok":true}` (surveillance) |
| GET | `/health` | version, détecteur, extracteur, galerie active, caméras actives, état de l'envoi des événements |
| GET | `/system/performance` | CPU, mémoire, disque, GPU (si torch+CUDA), temps de traitement par caméra |
| GET | `/cameras` | état de toutes les caméras démarrées |
| GET | `/cameras/devices` | caméras USB détectées sur cet ordinateur |
| POST | `/cameras/test` | corps `{"type":"usb","index":0}` ou `{"type":"url","url":"rtsp://..."}` → `{ok,width,height,fps,open_ms,quality,snapshot_jpeg_b64}` |
| POST | `/cameras/{id}/start` | `{source, analysis_fps, analysis_enabled, recording_enabled, lines:[{id,name,x1,y1,x2,y2,exit_direction,min_track_frames,hysteresis}]}` |
| POST | `/cameras/{id}/stop` | arrêt (les pistes en cours sont clôturées) |
| POST | `/cameras/{id}/config` | mise à jour à chaud des lignes / analyse / enregistrement |
| GET | `/cameras/{id}/state` | statut, fps reçus, ms/analyse, pistes visibles, compteurs de session |
| GET | `/cameras/{id}/frame?overlay=1` | dernière image JPEG (avec ou sans annotations) |
| POST | `/detection/image` | multipart `file` → résultats de reconnaissance + image annotée |
| POST | `/detection/video` | `{path:"cache/x.mp4", analysis_fps, lines}` → `{job_id}` |
| GET | `/detection/video/{job}` | progression puis compteurs, pistes et événements |
| POST | `/training/jobs` | `{model_id, version, min_images, min_accuracy, unknown_margin, products:[{product_id,name,min_confidence,images:[{id,path,split,bbox}]}]}` → `{job_id}` |
| GET | `/training/jobs/{id}` | `{status, progress, message, result}` (métriques réelles, par produit, par image) |
| POST | `/recognition/activate` | `{gallery_file:"gallery_m1_v3.npz"}` |
| GET | `/recognition/status` | galerie active et calibration |
| POST | `/images/quality?path=` | indicateurs qualité d'une photo de `storage/product_images` |

## 2. Événements IA → PHP

`POST /api/ai/events` (application PHP) — en-tête `X-AI-Token: <AI_CALLBACK_TOKEN>`, IP dans
`AI_CALLBACK_ALLOWED_IPS`. Corps : `{"events":[...]}` (200 maximum). Réponse :
`{"ok":true,"accepted":n,"duplicates":n,"rejected":n,"errors":[...]}`.
Horodatages : ISO 8601 avec fuseau (le service IA envoie de l'UTC), convertis dans `APP_TIMEZONE`.

```json
{"type":"camera_status","uuid":"…","camera_id":1,"event_type":"deconnexion","message":"…","occurred_at":"2024-05-12T09:42:16.120+00:00"}
{"type":"track_closed","uuid":"…","camera_id":1,"session_id":"…","track_number":154,"product_id":12,"label":"Huile 1 L",
 "confidence":0.93,"detector_label":"objet_en_mouvement","detector_confidence":0.97,"bbox":[0.41,0.22,0.08,0.19],
 "frames_seen":17,"first_seen_at":"…","last_seen_at":"…","end_reason":"sorti_du_champ","model_version":{"model_id":1,"version":3}}
{"type":"line_event","uuid":"…","camera_id":1,"line_id":2,"direction":"sortie","reason":"franchissement net","session_id":"…",
 "track_number":154,"track_frames":17,"product_id":12,"label":"Huile 1 L","confidence":0.93,"bbox":[…],"occurred_at":"…",
 "snapshot_path":"snapshots/cam1/2024-05-12/<uuid>.jpg","clip_path":"videos/cam1/2024-05-12/<uuid>","clip_frames":25}
```

`direction` : `sortie`, `entree`, `ambigu`, `perte_suivi`. Les chemins de médias doivent commencer
par `snapshots/cam<ID>/` ou `videos/cam<ID>/`. Un `uuid` déjà reçu est ignoré (renvoi après coupure).
Si PHP est indisponible, le service IA conserve les événements dans `ai-service/runtime/outbox.jsonl`
et les renvoie automatiquement.

## 3. API de ventes (logiciel de caisse tiers)

Authentification : `Authorization: Bearer <SALES_API_TOKEN>` (généré dans `.env` ; API désactivée
si vide). À n'utiliser que sur le réseau local ou derrière HTTPS.

`POST /api/v1/sales`

```json
{"store_code":"MAG-001","external_ref":"F-2024-000123","sold_at":"2024-05-12 10:42:00",
 "payment_method":"especes","items":[{"barcode":"6001234567890","quantity":2,"unit_price":4250}]}
```

Réponses : `201 {"ok":true,"sale_id":..,"receipt":"T240512-..","matched_exits":1}` ;
`200 {"ok":true,"duplicate":true}` si `external_ref` déjà reçue ; `422` produit inconnu ou données
invalides ; `401` jeton invalide. Chaque vente met à jour le stock et déclenche le rapprochement
avec les sorties observées.

`GET /api/v1/products?store_code=MAG-001` : liste des produits (synchronisation).

## 4. Application web (routes internes)

Toutes les routes exigent une session et une permission (voir `config/routes.php`). Les routes
AJAX renvoient du JSON lorsque `Accept: application/json` est envoyé, et exigent l'en-tête
`X-CSRF-Token` pour toute écriture. `GET /api/health` (public) : état de l'application et de la base.
