# MARWA AI 2.0 — Documentation technique

MARWA AI est l'évolution de l'assistant existant (`addons/marwa_ia`, MARWA IA 1.2 → 2.0.0). Il n'y a pas de
second assistant : la page `/ia`, la bulle et toutes les fonctions 1.x (rapports PDF / Excel, formation, marketing,
conseils, contrôle de configuration, calculs, lexique) sont conservées. Le nouveau moteur (`src/Brain/`) traite
d'abord la question ; ce qu'il ne couvre pas est confié au moteur 1.x.

Prérequis : POS Marwa 3.9.0 ou plus (le cœur expose `Router::routes()`), PHP 8.1, MySQL/MariaDB.

## 1. Parcours d'une question

```
navigateur ──POST /ia/v2/stream (JSON + jeton CSRF)──▶ AiController::stream
   ▲                                                   │ session libérée (session_write_close)
   │  événements SSE au fil de l'eau                    ▼
   └──────────── meta · status · text · html · block · delta · replace · open · done · error
                                                       Brain::handle
   1. Scope::guard           hors domaine (moteur 1.x)            → réponse polie
   2. capacités              « que sais-tu faire ? »               → liste filtrée par les droits
   3. Actions::detect        « prépare un inventaire du dépôt »    → proposition + jeton (rien n'est fait)
   4. domaines 1.x           PDF / Excel / rapport / marketing / formation / contrôle / conseils
   5. définitions            « différence entre brut et net »     → Transactions::explain
   6. aide / procédure       « comment … »                          → base de connaissances + inventaire + documentation
                                                                     (rédigée en continu par un modèle s'il y en a un)
   7. données                Nlu::split → Nlu::classify → Nlu::slots → suite de conversation → droits → Catalog::run
   8. repli                  classement par un modèle (s'il y en a un) → moteur 1.x → « je n'ai pas compris »
   ensuite : mémoire (Memory), journal d'apprentissage (Learning::log), événement done
```

La bulle (`POST /ia/ask`) utilise le même `Brain::handle` et reçoit la réponse en un bloc, au format 1.x
(`IaController::compat`), avec sa propre conversation gardée en session.

## 2. Composants (`addons/marwa_ia/src/Brain/`)

| Classe | Moteur demandé | Rôle |
|---|---|---|
| `Brain` | orchestration | aiguillage ci-dessus, émission des événements, mise en forme (`present`), contexte de suite |
| `UserContext` | User Context & Permission | utilisateur, rôle, droits, boutique, caisse ; `allows()`, `salesWhere()` (ses ventes seulement sans `sales.view_all`), `storeWhere()` / `warehouseWhere()` (sa boutique seulement si `users.store_id` est renseigné et sans `settings.manage`) — relu à chaque requête |
| `Nlu` | compréhension | normalisation, correction des fautes (distance d'édition sur le vocabulaire des exemples), synonymes, pondération IDF, similarité (Dice pondéré 75 % + trigrammes 25 %, seuil 0,44), découpage des demandes multiples, périodes (y compris « année 1999 »), boutique / magasin, article, utilisateur, nombre |
| `Catalog` | Database Intelligence | 56 outils de lecture : chaque outil = libellé, module, droits exigés, méthode, lien. Exécution dans une transaction **READ ONLY**, durée limitée (`max_statement_time` / `max_execution_time`), résultats limités ; renvoie variantes de phrase, faits chiffrés, cartes, tableau, graphique, sources, lien, suggestions |
| `Transactions` | Transaction Intelligence | 19 types de mouvements (vente, retour, annulation, transfert, ajustement…) et 17 termes de montants (brut, net, TVA, remise, marge, bénéfice…) avec leur définition et leur colonne |
| `Analytics` | analyses / anomalies | bilan du jour ou du mois, explication d'une baisse (causes factuelles seulement), 8 règles d'anomalies présentées comme « à vérifier, pas des preuves de fraude » |
| `Responder` | réponse naturelle | choix de variante (formulations variées, mêmes chiffres), `numberGuard` (toute reformulation contenant un nombre absent des faits calculés est rejetée), consignes des modèles |
| `Memory` | mémoire | conversations et messages par utilisateur, état de contexte, titre, résumé extractif toutes les 20 réponses, renommage, suppression, purge selon `ai_retention_days` |
| `AppMap` | Knowledge Engine | inventaire automatique : modules (manifestes), menus, routes et permissions, droits, tables et colonnes, clés étrangères, **noms** des paramètres (jamais leurs valeurs), sections de la documentation ; reconstruit automatiquement quand la version, les modules, le schéma ou les droits changent (empreinte vérifiée au plus toutes les 10 min) |
| `DataDictionary` | dictionnaire | description des tables et colonnes lue dans `information_schema` + libellés |
| `Actions` | Action Engine | 6 outils : navigation, PDF, brouillon de commande fournisseur, ouverture d'inventaire, sortie de caisse (sensible), annulation de vente (sensible) ; demandes non prises en charge (supprimer un article, changer un prix, valider un inventaire, rembourser) expliquées |
| `Providers` | gestionnaire de modèles | Ollama, OpenAI, Anthropic Claude, Google Gemini, API compatible OpenAI ; clé chiffrée ; modèle par défaut + secours ; limites ; coûts ; streaming |
| `Learning` | apprentissage continu | amorçage (intentions, exemples, outils, FAQ), journal anonymisé, avis 👍/👎, correction validée par l'administrateur, banc de tests |

## 3. Sécurité et confidentialité

- **Lecture seule.** Les outils de données n'exécutent que des `SELECT` écrits dans le code (aucun SQL produit par un modèle), dans une transaction `READ ONLY` annulée ensuite, avec un délai maximal. Aucune instruction `INSERT/UPDATE/DELETE/DROP/ALTER` n'est possible par ce chemin.
- **Modifications** uniquement par les fonctions métier existantes (`InventoryService::start`, `PurchaseService::createOrder` au statut brouillon, `CashService::manual`, `SaleService::cancel`), après proposition, **confirmation** de l'utilisateur avec un jeton à usage unique (haché en base, expiration 10 min), nouvelle vérification des droits au moment de la confirmation, **mot de passe ou PIN** pour les opérations financières (`Auth::verifySupervisor` sur le compte de l'utilisateur), journal `ai_action_logs` et `audit_logs`.
- **Droits vérifiés côté serveur à chaque question** (`UserContext` est reconstruit à chaque requête) ; l'assistant n'a pas de privilèges propres ; refus formulés poliment avec le nom de la donnée refusée.
- **Mémoire cloisonnée** : toute lecture, suite, suppression ou note d'une conversation vérifie `user_id` ; un administrateur ne lit pas les conversations des autres.
- **Modèles externes** : inactifs tant que l'administrateur n'a pas coché l'accord d'envoi (loi n° 2017-28 du 3 mai 2017 sur la protection des données à caractère personnel) ; HTTPS obligatoire ; Ollama limité au réseau local. Un modèle reçoit la question, quelques extraits de documentation, les 4 derniers échanges de la conversation pour l'aide et, si la reformulation est activée, la phrase déjà calculée. **Jamais la base de données.**
- **Clés d'API** chiffrées (`sodium_crypto_secretbox`, clé dérivée de `APP_KEY`, préfixe `A1.`), jamais réaffichées (4 derniers caractères seulement).
- **HTML** : le texte des modèles est échappé côté navigateur (mise en forme minimale : gras, listes) ; le HTML du moteur 1.x et des fiches intégrées est préparé côté serveur ; les fiches saisies par l'administrateur sont échappées à l'enregistrement.
- **Journal d'apprentissage** anonymisé : numéros de téléphone, adresses électroniques et numéros de pièces masqués. Les montants ne sont pas masqués.

## 4. Streaming

`POST /ia/v2/stream` renvoie `text/event-stream`. Le navigateur lit le flux avec `fetch` + `ReadableStream`
(`public/marwa-ai.js`). Les étapes calculées (statut, phrase, cartes, tableau, graphique) sont envoyées dès qu'elles
sont prêtes. Quand un modèle rédige (aide, reformulation), chaque morceau reçu du fournisseur est relayé
immédiatement (`delta`) : il n'y a **pas** d'affichage simulé mot à mot d'un texte déjà complet. En-têtes :
`Cache-Control: no-cache`, `X-Accel-Buffering: no` (Nginx) ; la compression de sortie PHP est désactivée pour cette
réponse. Derrière un proxy qui met en tampon (certains Apache avec `mod_deflate`), le texte peut arriver en un bloc :
c'est un réglage du serveur web, pas de l'application.

## 5. Fournisseurs de modèles (`Providers`)

| Fournisseur | Requête | Flux lu |
|---|---|---|
| Ollama | `POST {url}/api/chat` | NDJSON |
| OpenAI | `POST {url}/chat/completions`, `max_completion_tokens`, `stream_options.include_usage` | SSE `choices[0].delta.content`, `usage` |
| Compatible OpenAI | idem avec `max_tokens` | idem |
| Anthropic Claude | `POST {url}/v1/messages`, en-têtes `x-api-key`, `anthropic-version: 2026-10-21`, `stream: true`, `system` | SSE `message_start`, `content_block_delta/text_delta`, `message_delta` (jetons, `stop_reason: refusal`), `error` |
| Google Gemini | `POST {url}/v1beta/models/{m}:streamGenerateContent?alt=sse`, `x-goog-api-key`, `systemInstruction` | SSE `candidates[0].content.parts[].text`, `usageMetadata` |

Le projet n'utilisant pas Composer, les appels sont faits en HTTP direct (cURL) et non avec les SDK officiels.
Pour Claude, le modèle proposé par défaut est `claude-opus-5-5` ; aucune `temperature` n'est envoyée ;
`output_config.effort = "low"` est envoyé aux modèles récents qui l'acceptent ; le **repli côté serveur**
(`fallbacks: "default"` + en-tête bêta `server-side-fallback-2026-07-01`) est activé pour les modèles qui le
prennent en charge (désactivable par `options_json` : `{"fallbacks": false}`).

Ordre d'appel : modèle **par défaut**, puis **secours** si le premier échoue avant d'avoir envoyé du texte (on ne
mélange jamais deux modèles dans une même réponse). Limites : jetons par jour et par modèle, requêtes par
utilisateur et par jour. Chaque appel est consigné dans `ai_usage_logs` (jetons, durée, coût estimé d'après les
tarifs saisis, erreur).

## 6. Tables (migration `0003_marwa_ai.sql`, retour arrière `sql/down_0003.sql`)

| Table | Contenu |
|---|---|
| `ai_conversations` | une conversation par utilisateur : titre, contexte (JSON), résumé, compteur |
| `ai_messages` | questions et réponses : texte, blocs (JSON), intention, confiance, sources, modèle, durée |
| `ai_knowledge_base` | FAQ intégrée + procédures et réponses validées par l'administrateur (droit facultatif) |
| `ai_intents` / `ai_intent_examples` | intentions et exemples (intégrés ou validés) |
| `ai_tool_registry` | outils de lecture et d'action, droit, confirmation, sensibilité |
| `ai_user_context` | préférences par utilisateur (réservé) |
| `ai_feedback` | avis sur une réponse |
| `ai_learning_logs` | questions anonymisées, intention supposée, résultat, résolution |
| `ai_model_settings` | modèles : fournisseur, adresse, modèle, clé chiffrée, rôle, accord externe, limites, tarifs, état |
| `ai_usage_logs` | appels aux modèles |
| `ai_action_logs` | actions proposées / confirmées / annulées / expirées / échouées |
| `ai_documentation_index` | inventaire automatique de l'application |

Les tables 1.x (`ia_history`, `ia_training`) sont conservées. Les réglages 1.x d'Ollama (`ia_llm_url`,
`ia_llm_model`) sont repris une fois dans `ai_model_settings` ; aucun réglage existant n'est écrasé.

Réglages : `ia_bubble`, `ai_retention_days` (90), `ai_llm_phrasing` (0 : la reformulation par un modèle est
désactivée par défaut).

## 7. Routes

| Route | Droit | Rôle |
|---|---|---|
| `GET /ia` | ia.use | conversation |
| `POST /ia/v2/stream` | ia.use | réponse en continu |
| `GET /ia/conversations`, `GET /ia/conversations/{id}` | ia.use | liste, messages |
| `POST /ia/conversations/{id}/renommer` · `/supprimer` · `POST /ia/conversations/supprimer-tout` | ia.use | gestion |
| `POST /ia/avis` | ia.use | avis |
| `POST /ia/actions/{id}/confirmer` · `/annuler` | ia.use (+ droit de l'action) | actions |
| `POST /ia/ask`, `GET /ia/etat`, `/ia/rapport`, `/ia/marketing`, `POST /ia/oubli` | ia.use | bulle et 1.x |
| `GET|POST /ia/reglages` | ia.manage | réglages |
| `GET /ia/admin`, `POST /ia/admin/banc`, `POST /ia/admin/apprentissage/{id}`, `POST /ia/admin/index` | ia.manage | pilotage |
| `GET /ia/admin/intentions`, `POST …/exemple`, `POST …/exemple/{id}/supprimer` | ia.manage | intentions |
| `GET|POST /ia/admin/connaissances` | ia.manage | connaissances |
| `GET|POST /ia/admin/modeles`, `POST …/{id}/role`, `POST …/{id}/tester` | ia.manage | modèles |
| `GET /ia/admin/dictionnaire` | ia.manage | dictionnaire de données |

## 8. Ajouter une capacité

- **Nouvelle donnée** : ajouter une entrée à `Catalog::TOOLS` (libellé, module, droits, méthode, lien), écrire la
  méthode (requête préparée, filtres `UserContext`, faits chiffrés, variantes) et 3 à 6 exemples dans
  `Nlu::EXAMPLES` ; incrémenter `Learning::SEED_VERSION`. Ajouter les questions au banc (`tests/marwa_ai.php`).
- **Nouvelle formulation** : Pilotage → « Questions à enseigner » ou Intentions → « Ajouter un exemple ». Aucun
  réentraînement de modèle : l'exemple est utilisé dès la question suivante.
- **Nouvelle procédure** : Connaissances → Nouvelle fiche.
- **Nouvelle action** : `Actions::TOOLS` + `detect` + `prepare` + exécution par une fonction métier existante.

## 9. Limites connues

- Ce n'est pas un grand modèle de langage : sans modèle configuré, la compréhension repose sur des exemples et
  des règles ; une formulation très éloignée des exemples peut ne pas être comprise (elle est alors journalisée
  pour être enseignée).
- Aucun module de tickets Wi-Fi n'existe dans POS Marwa : l'assistant le dit au lieu de compter des « tickets
  Wi-Fi ».
- Les fournisseurs réels (OpenAI, Anthropic, Google, Ollama) n'ont été essayés que contre une **fausse API locale**
  reproduisant leurs formats de flux ; à valider avec un vrai compte avant mise en service.
- Le « bénéfice » est une estimation de gestion (marge sur coût moyen − dépenses), pas un résultat comptable.
