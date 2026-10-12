# Services externes : besoins, configuration et coûts

MARWA fonctionne **sans aucun service payant** en mode local/démonstration. Pour une exploitation
réelle, les services ci-dessous sont nécessaires ou fortement recommandés. Les coûts indiqués sont
des **ordres de grandeur à vérifier** auprès des fournisseurs : aucun tarif n'est garanti ici.

| Service | Statut dans MARWA | Configuration | Coût à prévoir |
|---|---|---|---|
| **Hébergement VPS** | Requis en production | voir DEPLOIEMENT_VPS.md | Abonnement mensuel (quelques dizaines d'euros/mois pour un petit VPS ; selon l'hébergeur) |
| **Nom de domaine + HTTPS** | Requis en production | DNS + Let's Encrypt | Domaine : abonnement annuel (prix variable selon l'extension, ex. `.ne`, `.com`) ; certificat Let's Encrypt gratuit |
| **Tuiles cartographiques** | Fonctionnel (OpenStreetMap) | `MAP_TILE_URL`, `MAP_TILE_HOST` | `tile.openstreetmap.org` est gratuit mais réservé à un **usage modéré** (politique de la Fondation OSM : pas d'usage intensif commercial). En production : fournisseur de tuiles commercial (offres gratuites limitées puis payantes au volume) ou serveur de tuiles auto-hébergé (coût serveur) |
| **Calcul d'itinéraires (OSRM)** | Fonctionnel + repli automatique | `ROUTING_DRIVER=osrm`, `OSRM_URL` | Le serveur public `router.project-osrm.org` est une **démonstration sans garantie** (limites d'usage, disponibilité). Recommandé : OSRM auto-hébergé avec l'extrait OpenStreetMap du Niger (Geofabrik) — logiciel libre, coût = serveur (≈ 2–4 Go RAM pour le Niger). Sans OSRM, MARWA utilise une estimation à vol d'oiseau × 1,35 signalée « approximative » |
| **Recherche d'adresses (Nominatim)** | Fonctionnel + cache 30 j + limite 1 req/s | `GEOCODING_DRIVER`, `NOMINATIM_URL`, `GEOCODING_USER_AGENT` (contact obligatoire) | Nominatim public : gratuit, **1 requête/s maximum**, usage modéré. En production : instance auto-hébergée ou fournisseur commercial. Les repères MARWA (table `landmarks`, import OSM `bin/import-landmarks.php`) sont prioritaires et ne coûtent rien |
| **SMS** (OTP, alertes) | **Interface prête, aucun envoi réel** (pilote `log`) | implémenter `App\Services\Notification\SmsGateway` pour l'agrégateur retenu, puis `SMS_DRIVER` | **Payant au message** : obtenir des devis auprès d'opérateurs présents au Niger ou d'agrégateurs SMS internationaux ; prévoir aussi l'enregistrement de l'identifiant expéditeur (« MARWA ») |
| **E-mail** | Fonctionnel (SMTP intégré) | `MAIL_DRIVER=smtp` + `SMTP_*` | Boîte professionnelle ou service transactionnel (offres gratuites limitées puis payantes) |
| **WhatsApp** | Non intégré | — | Uniquement via l'**API WhatsApp Business officielle** (Meta ou fournisseur agréé) : conversations facturées, modèles de messages à faire approuver |
| **Mobile Money** | **Non intégré** (interface `PaymentGateway` prête) | nouvelle classe implémentant `PaymentGateway` + webhook | Contrat marchand avec un opérateur Mobile Money actif au Niger ou un agrégateur de paiement couvrant l'UEMOA ; **commission par transaction** + éventuels frais d'intégration. Vérifier l'existence et les conditions de l'API auprès du fournisseur avant tout développement |
| **Carte bancaire** | Non intégré | idem | Prestataire de paiement agréé (PSP) : frais par transaction, exigences PCI-DSS (déléguer la saisie de carte au PSP, ne jamais stocker de numéro de carte) |
| **Passerelle de démonstration** | Fonctionnelle, **aucun argent réel** | `PAYMENT_DEMO_ENABLED`, `PAYMENT_DEMO_SECRET` | Gratuit — à désactiver en production |
| **Notifications push (Web Push)** | Non implémenté (notifications navigateur quand l'onglet est ouvert) | — | Gratuit (VAPID) mais nécessite HTTPS et un développement complémentaire |
| **Sauvegarde externe** | Script fourni (`bin/backup.php`) | copie hors serveur à organiser | Stockage objet / second serveur (faible coût mensuel) |

## Intégrer un vrai prestataire de paiement

1. Obtenir le contrat marchand, la documentation officielle de l'API et les clés de test.
2. Créer `app/Services/Payment/<Fournisseur>Gateway.php` implémentant `PaymentGateway` :
   `initiate()` (création de la transaction chez le fournisseur), `verifyWebhook()` (vérification
   de signature selon **la documentation du fournisseur**), `parseWebhook()` (référence, statut, montant, frais).
3. Déclarer le moyen de paiement dans `PaymentService::gateway()` et `handleWebhook()`, l'ajouter à
   `payments.methods` (paramètres) et exposer l'URL `POST /api/webhooks/payments/<code>` au fournisseur.
4. Ajouter les tests (signature invalide, montant incohérent, rejeu, échec) sur le modèle de `PaymentTest`.
5. Rapprocher quotidiennement les relevés du fournisseur avec le compte `1100` du grand livre.

Toute la logique comptable (compte 2300, frais 5100, règlement, remboursement) est déjà en place
et testée avec la passerelle de démonstration.

## Intégrer un fournisseur SMS

Implémenter `App\Services\Notification\SmsGateway` (`name()`, `send($telephoneE164, $message)`),
l'activer au démarrage (`SmsSender::useGateway(new MonGateway(...))` dans `bootstrap/app.php`) ou
étendre `SmsSender::send()` avec un nouveau pilote. Les SMS sont déjà mis en file
(`outbound_messages`) avec relance automatique par le worker en cas d'échec.
