# MARWA AI — Audit de l'assistant existant et architecture

## 1. Audit (constaté dans le code)

**Module concerné :** `addons/marwa_ia` (MARWA IA 1.2.0, ~4 300 lignes). Deux autres modules portent « MARWA » mais ne sont pas des assistants conversationnels : `marwa_assist` (assistance à distance) et `marwa_voice` (annonces vocales). Ils ne sont pas modifiés.

| Élément | Existant | Constat |
|---|---|---|
| Compréhension | `Engine::intent()` : 20 intentions par expressions régulières pondérées ; `Text` : normalisation, racines, distance d'édition, lecture des périodes (« hier », « du 1er au 15 octobre »…) | Efficace sur les formulations prévues ; ne généralise pas aux synonymes non listés ; une seule intention par question |
| Données | `Reports` : 22 rapports SQL préparés (ventes, stock, ruptures, péremption, créances, bénéfice, dépenses, caisse, vendeurs, heures, paiements, catégories, bilan, réapprovisionnement, prévision, détail de vente) | Requêtes sûres, mais aucune réponse chiffrée directe « en une phrase » ; pas de stock par boutique/entrepôt, pas de transferts, pas de Marwa Pay ni Fiscal Connect |
| Droits | `Auth::can()` vérifié par rapport ; ventes limitées à l'utilisateur sans `sales.view_all` | Pas de restriction par boutique (`users.store_id`) ; contrôle fait à la main dans chaque rapport |
| Connaissance | `Knowledge` : FAQ rédigée (~40 entrées) + documentation (guide, notices) indexée en BM25 ; `Modules` : présentation des modules | Statique : ne découvre ni les routes, ni les menus, ni les tables, ni les permissions |
| Contexte | `Session` : dernière demande comprise (« et en PDF ? », « et le mois dernier ? ») | Une seule demande mémorisée, en session ; pas d'historique de conversation |
| Historique | Table `ia_history` (questions, 90 jours) ; `ia_training` (formation) | Pas de messages ni de réponses conservés |
| Modèle IA | `Llm` : Ollama local facultatif (adresses du réseau local seulement), reformule l'aide à partir de la documentation | Un seul fournisseur ; pas de streaming ; pas de clé, de coûts, de secours |
| Interface | Page `/ia` + bulle sur toutes les pages (`marwa-ia.js`), tableaux, indicateurs, lecture vocale, copier | Pas de streaming, pas de liste de conversations, pas de graphiques, pas de sources affichées |
| Autres | Formation et quiz (`Trainer`), marketing (`Marketing`), conseils (`Advisor`), vérification de configuration (`Checkup`), calculs (`Tools`), garde-fou hors domaine (`Scope`) | À conserver tels quels |

**Faits importants pour les réponses :**
- POS Marwa n'a **aucun module de tickets Wi-Fi / « Marwa Connect »** : les « tickets » sont les tickets de caisse (table `sales`). L'assistant le dira au lieu d'inventer des chiffres de tickets Wi-Fi.
- Une « boutique » est un `store` ; un « magasin » (dépôt) est un `warehouse` rattaché ou non à une boutique.
- Valeur du stock = Σ `stock_levels.quantity` × `products.cost_price` (coût moyen pondéré) ; au prix de vente avec `sale_price`.
- Chiffre d'affaires = ventes non annulées (`sales.status <> 'cancelled'`) ; net = CA − retours ; marge = net − coût des ventes ; bénéfice = marge − dépenses + recettes (règles de `ReportService::summary`).

## 2. Architecture proposée (évolution de `marwa_ia` en 2.0, sans second assistant)

Nouveau sous-dossier `addons/marwa_ia/src/Brain/` ; l'ancien moteur reste le **repli** pour tout ce que le nouveau ne traite pas (formation, marketing, conseils, rapports PDF…).

| Moteur | Classe | Rôle |
|---|---|---|
| A. Intent | `Nlu` | Normalisation, correction des fautes (dictionnaire du métier), synonymes, classification par similarité avec une bibliothèque d'exemples (table `ai_intent_examples`), extraction des paramètres (période, boutique, magasin, article, client, vendeur, nombre), plusieurs demandes dans une phrase, références au contexte (« et hier ? », « et le magasin ? », « fais la comparaison ») |
| B. Knowledge | `AppMap`, `DataDictionary` | Découverte automatique : modules (manifestes), menus, routes et permissions, tables / colonnes / clés (information_schema), réglages ; index `ai_documentation_index` régénéré quand la version, les modules ou le schéma changent ; base `ai_knowledge_base` (FAQ existante + procédures + réponses validées par l'administrateur) |
| C. Database | `Catalog` + `SqlGuard` | Catalogue d'outils de données : requêtes préparées sur tables et colonnes en liste blanche, périmètre de l'utilisateur injecté côté serveur, résultats limités, délai maximal, transaction en lecture seule ; requête libre générée par un modèle IA **uniquement** pour l'administrateur, validée (SELECT seul, tables autorisées, limite, délai) et exécutée sur un compte MySQL en lecture seule si configuré |
| D. Permission | `UserContext` | Utilisateur, rôle, boutique, caisse, magasins, permissions → périmètre (toutes boutiques / sa boutique / ses propres ventes) appliqué dans chaque requête ; refus poli sinon |
| E. Transaction | `Transactions` | Catalogue des types de mouvements (vente, retour, annulation, dépense, recette, entrées/sorties de caisse, transfert, ajustement, inventaire, règlements, crédit, paiement électronique, certification fiscale…) avec leur table, leur signe et le sens des montants (brut, net, TVA, remise, marge) |
| F. Analytics | `Analytics` | Comparaisons de périodes, bilan, classements, réapprovisionnement, explication d'une baisse, anomalies statistiques (écarts de caisse, annulations, remboursements, prix sous le coût, stock négatif, opérations répétées, paiements à vérifier) — formulées comme « à vérifier », jamais comme une accusation |
| G. Response | `Responder` | Réponses naturelles et variées à partir de faits calculés ; reformulation facultative par le modèle IA avec contrôle que **tous les chiffres** de la réponse figurent dans les faits ; streaming réel (SSE) des étapes et des jetons du modèle |
| H. Memory | `Memory` | Conversations et messages par utilisateur (`ai_conversations`, `ai_messages`), état de contexte, résumé des longues conversations, durée de conservation, suppression |
| I. Action | `Actions` | Registre d'outils (`ai_tool_registry`) : navigation, rapports PDF, fiches, et actions métier (brouillon de commande fournisseur, ouverture d'inventaire, sortie de caisse, annulation de vente…) avec vérification des droits, **confirmation explicite** et journal (`ai_action_logs`) |
| J. Training | `Learning` | Questions non comprises, avis des utilisateurs, corrections validées par l'administrateur (nouvel exemple d'intention ou réponse validée), banc de tests de compréhension, tableau de bord |
| Modèles | `Providers` | Ollama (existant conservé), OpenAI, Anthropic Claude, Google Gemini, API compatible OpenAI ; clé chiffrée, modèle par défaut et de secours, limites et coûts (`ai_model_settings`, `ai_usage_logs`) ; seules les données strictement nécessaires (faits calculés) sont transmises, jamais la base |

**Nouvelles tables :** `ai_conversations`, `ai_messages`, `ai_knowledge_base`, `ai_intents`, `ai_intent_examples`, `ai_tool_registry`, `ai_user_context`, `ai_feedback`, `ai_learning_logs`, `ai_model_settings`, `ai_usage_logs`, `ai_action_logs`, `ai_documentation_index`. Les tables `ia_history` et `ia_training` sont conservées.

**Principes :** aucun chiffre sans requête ; si la donnée n'existe pas, le dire ; jamais d'action annoncée sans exécution réelle ; aucun envoi de la base à un fournisseur externe ; fournisseurs externes désactivés par défaut (activation explicite par l'administrateur, données personnelles : loi n° 2017-28).
