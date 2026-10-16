# Conception technique

## 1. Modules

| Module | Fichiers principaux | Rôle |
|---|---|---|
| Noyau | `app/Core/*` | Routeur, session, CSRF, authentification/RBAC, PDO, validation, chiffrement, audit |
| Produits & photos | `ProductController`, `ImageService` | Fiches, photos (webcam, import, smartphone), import CSV |
| Apprentissage IA | `TrainingController`, `TrainingService`, `ai-service/app/training/trainer.py` | Préparation, validation, versions, activation/restauration |
| Caméras | `CameraController`, `CameraService`, `ai-service/app/cameras/*` | USB/IP, test, démarrage, lignes virtuelles |
| Surveillance en direct | `LiveController`, `public/assets/js/live.js` | Grille d'images relayées par PHP |
| Vision | `ai-service/app/vision/*` | Détecteur, suivi, ligne virtuelle, reconnaissance |
| Réception des événements | `IngestService`, `ApiController::aiEvents` | Idempotente, validation stricte |
| Rapprochement caisse | `ReconciliationService` | Sorties observées ↔ lignes de vente |
| Alertes | `AlertService`, `AlertController` | Niveaux, indices, vérification humaine |
| Caisse | `PosController`, `SaleService`, API `/api/v1/sales`, import CSV | Ventes, retours, annulations |
| Stocks | `StockService`, `StockController` | Mouvements validés, inventaires, rapprochement |
| Rapports | `ReportService`, `Export`, `PdfWriter` | Journalier, période, PDF/Excel/CSV, impression |
| Maintenance | `MaintenanceService`, `BackupService`, `scripts/*` | Rétention, quota disque, sauvegardes, diagnostic |
| Notifications | `NotificationService` (+ interface `NotificationChannel`) | Internes ; canaux externes à brancher |

## 2. Chaîne d'analyse vidéo

```
capture (thread) ──► dernière image ──► analyse (analysis_fps)
                                          │
                     détecteur ───────────┤ MotionDetector (MOG2) par défaut, YOLO facultatif
                     suivi (ByteTracker) ─┤ association IoU + distance, pistes perdues conservées
                     reconnaissance ──────┤ empreinte du recadrage → galerie → vote par piste
                     ligne virtuelle ─────┤ côté signé + hystérésis + segment → sortie/entrée/ambigu/perte_suivi
                     tampon circulaire ───┘ JPEG des N dernières secondes → extrait de l'événement
                                          │
                     EventSender ─────────► POST /api/ai/events (file persistante si PHP indisponible)
```

* **Une piste = un objet** : les détections stockées en base sont résumées par piste (et non par
  image), ce qui limite le volume et évite de compter chaque image comme un article.
* **Identité d'une piste** : vote pondéré des reconnaissances successives ; si aucun produit ne
  domine (≥ 40 % des votes) → « Produit non identifié ».
* **Score de confiance calibré** : 0,5 = 95ᵉ centile des similarités entre produits différents,
  1,0 = similarité médiane entre photos d'un même produit (calculés sur les photos de référence).
  Un produit n'est nommé que si le score dépasse son seuil (`min_confidence`) **et** si l'écart
  avec le 2ᵉ produit le plus proche dépasse `ai.unknown_margin` (produits similaires).

## 3. Reconnaissance : méthode retenue

Prototype : **similarité visuelle** (pas de ré-entraînement de réseau). Pour chaque version :

1. contrôle qualité des photos (résolution, netteté, luminosité, contraste) ;
2. séparation **référence / test** (photos marquées « test », sinon 1 photo sur 4) ;
3. empreintes des références + variantes (éclairage, rotation, recadrage, flou) ;
4. calibration sur les références (validation croisée « une photo exclue ») ;
5. **validation sur les photos de test**, jamais utilisées comme référence → précision réelle par
   produit, produit le plus confondu ;
6. version « prête » seulement si la précision ≥ `ai.min_validation_accuracy` ; activation manuelle ;
   retour possible à une version antérieure.

Évolution prévue (non incluse) : pipeline supervisé (annotation de boîtes, entraînement d'un
détecteur spécialisé, export ONNX). La table `ai_models.method` prévoit `supervised_detector`.

## 4. Base de données

Script : `database/schema.sql` (36 tables, InnoDB, utf8mb4, clés étrangères et index).
Tables demandées : `users, roles, permissions, user_roles, stores, store_users, products,
product_categories, product_images, ai_models, ai_model_versions, ai_training_jobs,
ai_validation_results, cameras, camera_zones, virtual_lines, camera_events, object_tracks,
detection_results, exit_events, alerts, alert_reviews, sales, sale_items, inventory_movements,
stock_snapshots, daily_reports, video_records, notifications, audit_logs, system_settings,
backup_logs`. Tables ajoutées : `role_permissions` (droits configurables), `login_attempts`
(limitation des connexions), `notification_reads` (lecture par utilisateur), `schema_migrations`.

Séparation observée / comptable :

* **Observations** : `object_tracks`, `detection_results`, `exit_events`, `camera_events`,
  `video_records` — ne modifient jamais le stock.
* **Comptabilité** : `sales`, `sale_items`, `inventory_movements`, `stock_snapshots` — seul
  `StockService::record()` modifie `products.stock_quantity`.
* **Lien** : `exit_events.matched_sale_item_id` (rapprochement) et
  `inventory_movements.alert_id` (mouvement validé par un humain depuis une alerte).

Chaque événement porte un `uuid` unique fourni par le service IA (idempotence).

## 5. Rapprochement et niveaux d'alerte

| Situation | Alerte |
|---|---|
| Entrée (vers l'intérieur) | aucune (enregistrée) |
| Sortie rapprochée d'une vente | Information (clôturée automatiquement, désactivable) |
| Sortie, rapprochement désactivé | Information |
| Sortie d'un produit identifié sans vente dans la fenêtre | À vérifier |
| … + reconnaissance ≥ seuil prioritaire + suivi net (≥ 5 images) + aucune vente du produit à proximité élargie | Prioritaire |
| Objet non identifié franchissant la sortie | configurable (À vérifier) |
| Perte de suivi près de la ligne en direction de la sortie | configurable (À vérifier) |
| Franchissement ambigu (suivi trop court) | configurable (À vérifier) |

Une vente enregistrée **après** la sortie (horloges décalées, passage en caisse tardif) déclenche
un rapprochement différé : l'alerte passe à « Vente retrouvée ». Une vente annulée remet les
sorties rapprochées à vérifier.

## 6. Sécurité

Voir [SECURITY.md](SECURITY.md).
