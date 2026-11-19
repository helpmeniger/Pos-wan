# Outils de l'application HELP-ME

Le projet Android complet se trouve dans `app_android/`. Ce dossier contient les **outils** et les **sources de
données** qui alimentent l'application à partir du manuel.

| Dossier / fichier | Rôle |
|---|---|
| `tools/export_manuel.py` | Lit le manuel (`manuel/build/manuel.html`) et écrit `assets/lessons.json` (65 fiches de leçon, une par code-barres) et `knowledge/knowledge_manuel.json` |
| `knowledge/` | Sources de l'assistant : `knowledge_manuel.json` (manuel), `knowledge_plus.json` (fiches rédigées pour combler les manques), `knowledge_pro.json`, `data_clean.json`, `data.json` (bases d'origine de l'application) |
| `tools/build_brain.py` | Fusionne les sources, supprime les doublons, classe par thème, calcule les fiches voisines → `app_android/app/src/main/assets/brain.json` |
| `tools/brain_eval/` | Évaluation de l'assistant : `cases.txt` (réglage), `cases_controle.txt` (contrôle), comparaison avec l'ancien moteur |
| `tools/preview/` | Rendu sur ordinateur des démonstrations animées (même code de scène que l'application) |
| `preview/` | Aperçu vidéo de la démonstration « Créer et organiser » |

## Mettre à jour l'assistant après une modification du manuel

```
python3 manuel/tools/build.py              # reconstruit le manuel
python3 app_update/tools/export_manuel.py  # fiches de leçon + fiches du manuel
python3 app_update/tools/build_brain.py    # base unique de l'assistant (brain.json)
app_update/tools/brain_eval/probe.sh "comment faire une somme dans excel"   # essai en ligne de commande
CLS=Eval app_update/tools/brain_eval/probe.sh                               # évaluation chiffrée
CASES=cases_controle.txt CLS=Eval app_update/tools/brain_eval/probe.sh
```

Pour ajouter une connaissance : ajouter une fiche dans `knowledge/knowledge_plus.json`
(`intent` = thème, `title`, `question` = plusieurs façons de demander, `tags`, `answer`), puis relancer `build_brain.py`.

## L'assistant hors ligne

Le moteur est dans `app_android/app/src/main/java/com/hightechniger/help_me/brain/` (Java pur, sans Internet) :

- **Compréhension** : accents, apostrophes, fautes de frappe (« coment fair une somme sur exel »), abréviations
  (mdp, ppt, pc), expressions (« mot de passe », « capture d'écran », « tableau croisé dynamique »), synonymes.
- **Recherche** : BM25F sur les questions, les mots-clés et les réponses ; priorité au manuel ; détection du logiciel
  (Word, Excel, Windows…) ; précision de la question ; confiance mesurée.
- **Conversation** : salutations, aide, contact, calculs (« 15 % de 2000 »), suites de question (« et dans Excel ? »),
  « plus de détails », « non, autre chose », propositions quand la question est ambiguë, réponse honnête hors sujet.
- **Apprentissage local** : les avis « Utile / Pas utile » sont mémorisés sur le téléphone et améliorent les réponses suivantes.
