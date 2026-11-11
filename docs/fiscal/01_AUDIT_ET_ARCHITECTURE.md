# MARWA FISCAL CONNECT — Phase 1 (audit) et Phase 2 (proposition d'architecture)

Statut : **proposition soumise à validation**. Aucune table, aucun code fiscal n'a encore été créé.

## Avertissements préalables

- Aucune documentation officielle de MCF (SECeF Niger, e-MECeF Bénin, Togo, FNE Côte d'Ivoire, etc.) ni d'API d'administration fiscale n'a été fournie ou vérifiée. **Aucun pilote ne sera déclaré compatible** avec un dispositif réel tant que son protocole n'aura pas été obtenu et testé sur le matériel ou l'environnement de test officiel.
- **Aucune règle fiscale ne sera inventée.** Les profils pays seront livrés vides (pays, devise, champs à renseigner) avec la mention « À configurer à partir des textes officiels ». Figurer dans la liste ne signifie pas homologation.
- Le module ne rend pas POS Marwa « homologué » : l'homologation est délivrée par l'administration fiscale de chaque pays.

## PHASE 1 — Audit de POS Marwa (constaté dans le code)

| Domaine | Constat | Conséquence pour MF CONNECT |
|---|---|---|
| Langage / socle | PHP 8.1+ (testé 8.3), sans framework ni Composer, PDO MySQL/MariaDB, autoload maison | Module écrit dans le même style, sans dépendance externe ; cURL et `openssl` de PHP pour HTTP/SOAP/mTLS |
| Extensions | Système d'addons (`addons/<code>/manifest.json`, routes, migrations numérotées, permissions, menu) | MF CONNECT = addon `addons/fiscal`, activable/désactivable, sans toucher aux autres modules |
| Événements | **Aucun système de hooks** dans le noyau | Il faut ajouter un point d'extension minimal (voir §2.5) — seule modification du noyau |
| Vente | `SaleService::create()` : tout dans une transaction, idempotence par `sales.client_uuid` UNIQUE, numéro `Sequence::next('sale')` | La certification doit se faire **après** le commit (appel réseau hors transaction) ; l'idempotence fiscale s'appuie sur `sale_id` + `client_uuid` |
| Avoirs | `SaleService::createReturn()` → numéro `Sequence::next('return')` (AV) ; annulation `cancel()` | À relier à `issueCreditNote` / `cancelInvoice` ; une facture certifiée ne s'annule plus localement sans procédure fiscale |
| Hors-ligne | La caisse met les ventes en file dans le navigateur (`queueSale`) et les renvoie au retour du réseau | Une vente encaissée hors-ligne **ne peut pas** être certifiée au moment de l'impression : ticket « NON CERTIFIÉ », mise en file, certification au rejeu |
| Taxes | `Tax` : modes `none` / `general` / `product`, prix TTC, `sale_items.tax_rate` / `tax_amount`, ventilation `Documents::vatBreakdown()` | Les données HT/TVA/TTC existent ; les **groupes de taxe** officiels (A, B, C… selon pays) devront être mappés par configuration |
| Numérotation | `Sequence` : préfixe + année + compteur, remise à zéro annuelle | Conservée ; le numéro fiscal officiel (renvoyé par le MCF) est stocké **à côté**, jamais à la place |
| Documents | `Documents::saleTicket()` / `saleA4()` alimentent 3 modèles ticket et 3 modèles A4 ; QR interne signé HMAC (`DocumentRef`) | Ajout d'un bloc « fiscal » optionnel dans les données du document ; le QR officiel remplace le QR interne seulement si certifié |
| Impression | `PrintService::receipt()` (ESC/POS, réseau 9100 ou partage Windows) + impression navigateur | Même bloc fiscal dans le ticket ESC/POS |
| Identité entreprise | Réglages `company_nif`, `company_rccm` ; `customers.nif` | Réutilisés dans la facture normalisée |
| Sécurité | RBAC (`roles`, `permissions`, `role_permissions`, permission par route), CSRF, requêtes préparées, `Audit::log`, chiffrement libsodium dérivé de `APP_KEY` (`SyncService`) | Même mécanismes ; secrets d'API chiffrés en base, jamais renvoyés au navigateur |
| Limite déjà documentée | « Factures normalisées / dispositifs fiscaux : non implémenté » | MF CONNECT comble ce manque côté logiciel |

### Limites techniques identifiées

1. **USB / COM (RS232)** : PHP tourne côté serveur (Apache/WampServer). Il peut ouvrir un port série **du serveur** (`COM3`, `/dev/ttyUSB0`) mais pas celui du poste du caissier s'il est différent. Un MCF USB doit donc être branché sur la machine qui héberge POS Marwa, ou exposé via un pont réseau. Pas d'accès série depuis l'hébergement en ligne.
2. **Délais** : un appel MCF/API bloque la réponse de la caisse ; un délai maximal configurable (par défaut court) est nécessaire, au-delà la facture passe en file d'attente.
3. **Hébergement mutualisé** : les certificats mTLS et les appels sortants dépendent de l'hébergeur.

## PHASE 2 — Architecture proposée

### 2.1 Emplacement
`addons/fiscal/` (code `fiscal`, nom « Marwa Fiscal Connect »), structure identique aux autres addons. Menu « FISCAL CONNECT » avec les 11 sous-menus demandés.

### 2.2 Moteur central (`addons/fiscal/src/`)
- `FiscalEngine` : orchestre les étapes 1 à 8 du cahier des charges (configuration → données normalisées → envoi → vérification → stockage → statut → impression).
- `InvoiceNormalizer` : construit un objet facture neutre (vendeur, NIF, client, lignes, groupes de taxe, HT/TVA/TTC, paiements, références) à partir de la vente.
- `ConnectorResolver` : choisit le connecteur (caisse → boutique → défaut) et l'environnement TEST/PROD.
- `ResponseValidator` : refuse toute réponse incomplète (numéro de certification, identifiant MCF, signature/QR selon le profil). Sans réponse valide → statut `pending` ou `failed`, **jamais** `certified`.
- `PendingQueue` : file d'attente avec verrou (`SELECT … FOR UPDATE`), tentatives limitées, délai croissant ; traitée par `bin/fiscal-queue.php` (tâche planifiée) et par un bouton « Relancer ».
- `Secrets` : chiffrement/déchiffrement libsodium des clés, jetons, mots de passe de certificats ; masquage dans les journaux.
- `FiscalAudit` : traçabilité de chaque modification de configuration.

### 2.3 Interface pilote
```php
interface FiscalDriver {
  public static function code(): string;            // ex. 'simulation', 'generic_rest'
  public static function capabilities(): array;     // fonctions réellement prises en charge
  public function connect(): DriverResult;
  public function disconnect(): DriverResult;
  public function testConnection(): DriverResult;
  public function authenticate(): DriverResult;
  public function certifyInvoice(array $invoice): DriverResult;
  public function getInvoiceStatus(string $ref): DriverResult;
  public function getCertificate(string $ref): DriverResult;
  public function getDeviceInfo(): DriverResult;
  public function getDailyReport(string $date): DriverResult;
  public function closeDay(string $date): DriverResult;
  public function cancelInvoice(string $ref, string $reason): DriverResult;
  public function issueCreditNote(array $creditNote): DriverResult;
}
```
`DriverResult` : `ok`, `code` (`certified`, `rejected`, `unsupported`, `unreachable`, `auth_failed`, `invalid_response`…), données, message, requête/réponse brutes **expurgées**. Une fonction non prise en charge renvoie `unsupported`, jamais un succès simulé.

### 2.4 Pilotes livrés
| Pilote | Contenu réel | Statut annoncé |
|---|---|---|
| `Simulation` | Fonctionne entièrement ; numéros préfixés `SIMU-`, documents marqués « NON CERTIFIÉ – TEST » ; scénarios forcés (refus, panne, délai, doublon) | Outil de test, aucune valeur fiscale |
| `Generic/RestApiDriver` | Client HTTP configurable (URL, méthode, en-têtes, API Key / Bearer / OAuth2 client credentials, SSL, mTLS, délai, tentatives), correspondance des champs requête/réponse par configuration JSON | Générique ; compatibilité à valider par API |
| `Generic/SoapDriver` | Enveloppe SOAP configurable (extension `soap` si présente, sinon XML via cURL) | Générique ; à valider |
| `Generic/TcpDriver` | Connexion socket, trame configurable | Squelette générique |
| `Generic/SerialDriver` | Ouverture du port série du serveur, paramètres (débit, parité) | Squelette ; limite §Phase 1 |
| `Niger/SecefDriver`, `Benin/…`, `Togo/…` | Squelettes qui renvoient `unsupported` et expliquent qu'il faut la spécification officielle | **Non compatibles** tant que non développés sur documentation |

L'import OpenAPI se limitera à lire un fichier OpenAPI 3 (JSON) pour proposer les URL et méthodes ; il ne devine pas la correspondance des champs fiscaux.

### 2.5 Seules modifications du noyau (minimales, rétro-compatibles)
1. `app/Core/Events.php` (nouveau, ~30 lignes) : `Events::on(name, callable)` / `Events::fire(name, payload)`. Une exception d'un écouteur est journalisée et **n'annule jamais** la vente.
2. `SaleService::create()` : après le commit et si la vente n'est pas un doublon, `Events::fire('sale.created', ['sale_id' => …])`. Idem `sale.returned`, `sale.cancelled`.
3. `Documents::saleTicket()` / `saleA4()` et `PrintService::receipt()` : ajout d'une clé `fiscal` (vide si le module est inactif) que les 6 modèles affichent s'il y a lieu. Sans module, l'impression reste identique.
4. `SaleService::cancel()` : si un écouteur signale une facture certifiée, l'annulation locale est refusée et renvoyée vers la procédure d'avoir.

Aucune vente existante n'est modifiée ; aucune table existante n'est altérée (les données fiscales vivent dans les tables `fiscal_*` reliées par `sale_id`).

### 2.6 Tables (migration `0001_fiscal.sql`, réversible par `down.sql` documenté, sans suppression de données de vente)
`fiscal_countries`, `fiscal_profiles`, `fiscal_tax_rates`, `fiscal_connectors`, `fiscal_devices`, `fiscal_api_credentials` (valeurs chiffrées), `fiscal_certificates` (fichiers hors du dossier public, mot de passe chiffré), `fiscal_certifications` (UNIQUE `sale_id` + `doc_type` → impossible de certifier deux fois ; lignes certifiées non modifiables par l'application), `fiscal_pending_queue`, `fiscal_invoice_logs` (requêtes/réponses expurgées), `fiscal_webhooks` (secret HMAC), `fiscal_audit_logs`.

### 2.7 Statuts fiscaux d'une vente
`not_required` (module inactif ou pays sans obligation configurée) → `pending` → `certified` | `rejected` | `failed` (tentatives épuisées, action manuelle). Passage à `certified` uniquement par `ResponseValidator`, jamais à la main.

### 2.8 Permissions
`fiscal.view`, `fiscal.configure`, `fiscal.secrets` (saisie des clés, jamais leur lecture), `fiscal.queue` (relancer), `fiscal.reports`, `fiscal.logs`.

### 2.9 Tests prévus (Phase 8)
Ajoutés à `tests/run.php`, exécutés réellement : connexion, authentification, réponse positive, réponse négative, panne (serveur HTTP local qui refuse), délai dépassé, double envoi (idempotence), facture déjà certifiée, réponse incomplète refusée, secret absent des journaux, ticket marqué « NON CERTIFIÉ – TEST ». Les pilotes réels ne pourront pas être testés sans matériel ni accès sandbox officiel.

### 2.10 Ordre de réalisation
Phase 3 tables → Phase 4 moteur + Simulation → Phase 5 pilotes génériques et squelettes pays → Phase 6 interface → Phase 7 intégration ventes/impression → Phase 8 tests → Phase 9 documentation (installation, ajout d'un MCF, API, configuration, rapport de tests). Chaque phase est validée par les tests avant la suivante.

## Données personnelles
Les factures transmises contiennent des données clients (nom, NIF, téléphone). Leur transmission à l'administration fiscale et leur conservation doivent respecter la loi n° 2017-28 du 3 mai 2017 relative à la protection des données à caractère personnel (Niger) ou la loi applicable dans le pays d'exploitation. Les journaux techniques n'enregistrent que le strict nécessaire.
