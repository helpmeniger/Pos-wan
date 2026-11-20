# Base de données — MARWA SECEF CONNECT

Migration : `addons/fiscal/migrations/0002_secef.sql` (module « fiscal » 1.1.0), appliquée automatiquement à la mise à
jour du module, **après une sauvegarde automatique**. Aucune table existante n'est modifiée ; aucune donnée n'est
effacée. Retour arrière : `addons/fiscal/sql/down.sql` (manuel, hors migrations automatiques).

## Nouvelles tables

| Table | Rôle | Colonnes principales |
|---|---|---|
| `fiscal_sale_requests` | Choix du caissier, vente par vente (historique) | `sale_id` (clé), `mode` certifiee/ordinaire, `user_id`, `created_at` |
| `secef_articles` | Article POS Marwa ↔ article programmé sur l'e-SFE | `product_id` (unique), `designation`, `code`, `groupe` (A–L ou NULL = règle), `prix`, `mode` TTC/HT, `statut` a_programmer/programme/erreur/ignore, `derniere_erreur`, `programme_le` |
| `secef_portail_articles` | Copie de consultation des articles relevés sur l'e-SFE | `designation`, `groupe`, `prix_ttc`, `prix_ht`, `mode`, `taxe_specifique`, `releve_le` |
| `secef_categories` | Groupe de taxation par catégorie (prioritaire sur le régime) | `category_id` (clé), `groupe` |

## Tables existantes utilisées (sans modification de structure)

| Table | Usage |
|---|---|
| `sales`, `sale_items`, `sale_payments`, `customers`, `products`, `categories` | Lecture de la vente, des lignes, des paiements, du client, du groupe |
| `fiscal_connectors` | Connecteur « MARWA SECEF CONNECT (e-SECeF Niger) », pilote `ne_esfe_pont`, environnement prod, délai 120 s |
| `fiscal_profiles` | Profil « Niger — e-SECeF » rattaché au connecteur |
| `fiscal_api_credentials` | Identifiant, mot de passe e-SECeF et jeton du pont, **chiffrés** (`Secrets`, APP_KEY), jamais réaffichés |
| `fiscal_certifications` | Une ligne par facture (unique) : `status`, `idempotency_key`, `fiscal_number` = **Code SECeF/DGI**, `device_id` = **SECeF NIM**, `counters` = SECeF Compteurs, `qr_payload` (seulement s'il vient du portail), `proof_json` (réponse masquée : heure SECeF, ISF, PDF), `attempts`, `last_error` |
| `fiscal_pending_queue` | File d'attente (pont injoignable, réponse lente) |
| `fiscal_invoice_logs` | Journal de chaque appel au pont (identifiants retirés) |
| `settings` | `secef_config` (JSON : régime, règles, paiements, options), `fiscal_choix_caisse` (toutes / au_choix) |

## Données du pont (hors MySQL)

`addons/fiscal/pont-secef/donnees/` (droits restreints) : `travaux/<clé>.json` (état de chaque facture, écrit avant
chaque action), `pdf/<clé>.pdf` (PDF officiel téléchargé), `profil-navigateur/` (session du portail), `pont.log`
(journal sans secret). À inclure dans les sauvegardes ; à ne pas communiquer (la session du portail y est conservée).
