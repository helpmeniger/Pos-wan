# Marwa Pay Connect — Guide de configuration

> Aucun prestataire n'est déclaré intégré. Chaque connexion se configure d'après la documentation officielle et le contrat
> marchand du prestataire, puis se teste sur son environnement de test (sandbox) avant la production.

## 1. Fournisseurs (catalogue)
Visa, Mastercard, PayPal, My NiTA, AMANA, Mobile Money, Banque, TPE : simples fiches. « Intégration vérifiée » ne peut être cochée
qu'avec la référence de la documentation officielle et des essais réalisés.

## 2. Gestion des API (connexions)
1. **Ajouter** : nom, compte, connecteur (Simulation, REST/JSON, SOAP/XML, page de paiement + notification, ou squelette), environnement TEST/PRODUCTION, établissement.
2. **Paramètres JSON (sans secret)** : URL, points d'accès (`initiate`, `status`, `cancel`, `void`, `refund`, `refund_status`), gabarits de requête,
   correspondance de la réponse (`response`) et des statuts (`status_map`), unité des montants (`amount_unit` : `major` ou `minor` = centimes).
3. **Secrets** (chiffrés, jamais réaffichés) : clé d'API, clé secrète, Bearer, Basic, OAuth 2.0 (client_id / client_secret), secret de signature des notifications.
4. **Certificats** : CA et certificat / clé client (mTLS), rangés hors du dossier public.
5. **Notifications (webhook)** : adresse générée + secret HMAC-SHA256 ; doublons ignorés ; le statut est revérifié auprès du prestataire quand c'est possible.
6. **OpenAPI** : import d'un fichier JSON pour lister les opérations (aucune correspondance n'est devinée).
7. **Tester**, puis **Activer**.

## 3. Moyens de paiement
Libellé affiché en caisse, type (carte, portefeuille, mobile money, banque, TPE), connexion, **règlement enregistré dans la vente**
(Carte, Mobile money ou Virement : les rapports et clôtures existants restent cohérents), devise, frais estimés, minimum / maximum,
délai d'expiration, caisses autorisées. Un moyen « TPE » utilise le terminal affecté à la caisse.

## 4. À la caisse
Fenêtre d'encaissement → **PAIEMENT ÉLECTRONIQUE** → choix du moyen → montant (au plus le reste dû) → **Envoyer la demande**.
- Réponse immédiate, demande poussée sur le téléphone du client, lien vers la page du prestataire, ou terminal.
- La caisse suit l'état ; seul un paiement **confirmé par le prestataire** (ou le terminal) est ajouté à la vente.
- Paiement mixte : espèces + électronique + crédit client, le reste est calculé automatiquement.
- Hors connexion : paiement électronique indisponible (une confirmation serveur est indispensable).
- Un paiement déjà encaissé reste attaché au panier (même après rechargement) ; vider le panier ou retirer la ligne ne le rembourse pas.

## 5. Transactions, remboursements, rapprochement
- **Transactions** : filtres date, caisse, utilisateur, prestataire, terminal, statut, nature (réelles / TEST) ; export Excel (CSV) et PDF.
- **Remboursement** : depuis la transaction, total ou partiel, justification obligatoire, permission `pay.refund` ou validation d'un responsable ;
  transmis au prestataire d'origine ; enregistré « effectué » seulement après sa confirmation ; crée une sortie dans la méthode d'origine (jamais en espèces).
- **Annulation** d'une demande en attente ou d'un paiement non encore rattaché : seulement si le prestataire la confirme.
- **Rapprochement** : import du relevé CSV (référence, montant, date, statut) ; signale écarts de montant ou de statut, lignes absentes de part et d'autre ;
  confirme les paiements « À vérifier » (TPE autonome) concordants ; chaque écart se marque « traité » avec une note.

## 6. Paramètres
- Exiger « Paiement électronique » pour les règlements Carte et Mobile money.
- Formation : autoriser des transactions TEST à régler des ventes (**base de démonstration uniquement**).
- Devises acceptées ; **taux de change** avec source et date (aucune conversion sans taux) ; le montant réellement débité est celui confirmé par le prestataire.

## 7. Permissions
`pay.use` (caisse), `pay.view`, `pay.configure`, `pay.secrets` (saisie seule), `pay.refund`, `pay.reconcile`, `pay.reports`, `pay.logs`.
