# MARWA AI 3.0 — Documentation technique

Complète `02_DOCUMENTATION_TECHNIQUE.md` (version 2.0), qui reste valable pour la conversation, la mémoire, les modèles de
langage et les actions confirmées.

## 1. Chemin d'une question

`Brain::handle()` (conversation réelle, flux SSE) et `Brain::analyze()` (analyse à blanc : aucune écriture, aucun appel à un
modèle externe) suivent le même chemin :

1. `Recover::clean()` remet le texte en forme (fautes, abréviations, mots collés). La question d'origine reste affichée.
2. `Guard::check()` sur le texte corrigé **et** sur le texte d'origine : tentative de manipulation → refus expliqué.
3. `Actions::detect()` : demande d'opération → droits, paramètres, clarification ou proposition à confirmer.
4. Politesse, expressions idiomatiques, `General` (conversation, notions de gestion).
5. `Scope` (hors périmètre), `Expert` (conseils calculés), moteurs 1.x conservés (rapports, formation, marketing, contrôle).
6. `Nlu::classify()` + `Catalog` : outil de données en lecture seule, filtré par `UserContext` (droits, boutique).
7. Sinon : aide (base de connaissances), puis « non comprise » — journalisée pour le Training Center.

Issues possibles : `answer`, `clarify`, `refuse`, `propose`, `blocked`, `unknown`, `error`.

## 2. Moteurs

| Nom du cahier des charges | Classe | Rôle |
|---|---|---|
| Deep Intent Understanding Engine | `Brain\Nlu`, `Brain\Brain` | Classifieur par exemples (Dice pondéré 0,75 + trigrammes 0,25, seuil 0,44), index inversé, cache `storage/cache/marwa_ai_nlu.bin`, multi-demandes, paramètres (période, lieu, article, client, fournisseur, quantité, nombre) |
| Typo & Language Recovery Engine | `Brain\Recover` | Abréviations, tournures SMS, phonétique, Damerau, mots collés, élisions |
| Business Expert Engine | `Brain\Expert` | 15 outils (dernière entrée de stock, mouvements, fiche article / client / fournisseur, taux de marge, diagnostic de caisse, « qu'est-ce qui ne va pas », conseils calculés) |
| General Intelligence Engine | `Brain\General` | Conversation, plaisanteries, ~30 notions expliquées |
| Contextual Reasoning Engine | `Brain\Brain` (règles de suite), `Brain\Memory` | Période, lieu, nombre, article, exclusion, comparaison |
| Garde-fou | `Brain\Guard` | Refus expliqués ; ne remplace aucun contrôle de droits |
| Learning & Evaluation Engine | `Brain\Dataset`, `Brain\Evaluation`, `Brain\Learning` | Bibliothèque, banc d'évaluation, exemples validés, historique des versions |
| Human-like Response Engine | `Brain\Responder` | Rédaction, flux en continu, garde-fou des chiffres si un modèle reformule |

## 3. Bibliothèque de scénarios

Fichiers `addons/marwa_ia/data/scenarios/NN_domaine.txt`, une ligne par scénario :

```
intention | rôle | question | variantes d'entraînement (;) | variantes de test (;) | attentes (clé=valeur;)
```

Attentes : `out` (issue), `per` / `per2` (périodes), `loc` / `xloc` (lieu, lieu exclu), `prod`, `cust`, `sup`, `qty`, `lim`,
`ctx` (question précédente), `also` (intentions secondaires), `has` / `not` (texte présent / absent), `db` (chiffre recalculé
en SQL), `train=no` (rien n'est donné au classifieur), `user`.

Les variantes de test, les scénarios à contexte, les scénarios multiples et les `train=no` ne sont **jamais** donnés au
classifieur. `Dataset::expand()` produit le format complet demandé (identifiant, domaine, intentions, question, variantes,
variantes avec fautes, synonymes, contexte, entités, paramètres, outil métier, permissions, réponse attendue, conditions de
clarification et de refus, résultat attendu) ; il est exportable en JSON (`/ia/admin/scenarios/export` ou
`php addons/marwa_ia/bin/ai-eval.php --export=fichier.json`).

Les scénarios chiffrés ou nommés (articles, clients) sont écrits pour le jeu de démonstration.

## 4. Évaluation

```
php addons/marwa_ia/bin/ai-eval.php [--domaine=CODE] [--limite=N] [--echecs=N] [--json=fichier] [--export=fichier] [--sans-enregistrer] [--libelle=texte]
```

Chaque formulation est rejouée avec le compte du rôle prévu. Sont contrôlés : l'intention, les intentions secondaires, les
paramètres, l'issue, le texte, et pour certains scénarios le chiffre recalculé directement en SQL. Les passages sont conservés
(`ai_eval_runs`) pour repérer une régression.

## 5. MARWA AI Training Center (droit `ia.manage`)

| Menu | Adresse | Ce qu'on y fait |
|---|---|---|
| Tableau de bord IA | `/ia/admin` | Compréhension réelle (30 j), avis, dernière évaluation, banc rapide |
| Base de connaissances | `/ia/admin/connaissances` | Fiches et procédures |
| Bibliothèque des scénarios | `/ia/admin/scenarios` | Filtrer, voir le format complet, ajouter, exporter |
| Gestion des intentions | `/ia/admin/intentions` | Activer / désactiver, ajouter un exemple, ajouter une intention à réponse rédigée |
| Gestion des synonymes | `/ia/admin/synonymes` | Mot local → mot du logiciel |
| Questions incomprises | `/ia/admin/incomprises` | Rattacher à une intention ou écarter ; formulations fréquentes |
| Corrections des utilisateurs | `/ia/admin/corrections` | Traiter les avis négatifs |
| Tests automatiques | `/ia/admin/tests` | Tester une formulation à blanc ; rejouer la bibliothèque |
| Performance du modèle | `/ia/admin/performance` | Usage réel, passages d'évaluation, échecs |
| Configuration des modèles | `/ia/admin/modeles` | Fournisseurs facultatifs (inchangé) |
| Historique des améliorations | `/ia/admin/historique` | Qui a changé quoi, quand |

Une intention ajoutée par l'administrateur (`perso_…`) donne une réponse fixe rédigée par lui : elle ne lit aucune donnée et
n'exécute rien.

## 6. Base de données

Migration `0004_marwa_ai_training.sql` (retour arrière : `sql/down_0004.sql`) : `ai_scenarios`, `ai_synonyms`,
`ai_eval_runs`, `ai_knowledge_versions`. Aucune table existante n'est modifiée ni vidée. **Sauvegardez la base avant la mise à
jour** (Configuration → Sauvegardes) : c'est la règle pour toute migration.

## 7. Sécurité

- Les droits sont vérifiés côté serveur à chaque outil ; `UserContext::forUser()` permet de tester un rôle sans changer de session.
- Aucun SQL n'est construit à partir d'une question ; les lectures se font en transaction READ ONLY.
- Toute opération passe par la fonction métier existante, après confirmation (et mot de passe pour les opérations d'argent).
- Le garde-fou réduit les réponses inadaptées ; il ne rend pas le système inviolable. Les protections réelles sont les droits,
  l'absence de SQL généré et le journal d'audit.
