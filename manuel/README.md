# Manuel HELP-ME — Apprendre l'informatique de bureau

Sources et outils de fabrication du manuel connecté HELP-ME (un seul volume, format A5).

## Livrables (`build/`)

| Fichier | Contenu |
|---|---|
| `HELP-ME_Apprendre-l-informatique-de-bureau_Manuel.pdf` | Le manuel complet (A5) : Parties 1 à 7 et annexes |
| `HELP-ME_Couverture.pdf` | Couverture (4e + 1re de couverture, format de l'original 612 × 396 pt) |
| `codes.json` | Rapport de fabrication (codes et pages) |

Plan : 1. L'ordinateur · 2. Windows · 3. Internet · 4. Trucs, astuces et exercices · 5. Word · 6. Excel · 7. Intelligence artificielle · Annexes (corrigés, raccourcis Word/Excel, glossaire).

Mise en page fidèle à l'original : A5, Garamond (EB Garamond, licence OFL) 12 pt, tableaux 10 pt, titres rouges, sous-titres bleus, bandeaux et en-têtes de tableaux bleu clair, texte justifié sans césure, numéro de page encadré.

## Codes-barres et application

`codes-helpme.json` : pour chacun des 65 emplacements, le code, la page, la partie, le chapitre et le statut.

- Code 39 sans clé de contrôle, valeur à 4 chiffres (`*NNNN*`), placé **à la verticale en haut, dans le coin extérieur** de la page (à droite sur les pages impaires, à gauche sur les pages paires), sans numéro ni texte, comme dans l'original.
- Les 28 codes de l'original sont conservés et placés sur la page qui contient **le même contenu** qu'à l'origine, y compris les doublons (1008 et 1011 deux fois chacun).
- Nouveaux codes pour les nouvelles leçons : 2020-2034, 1019-1027, 3001-3011. Numéros non utilisés : 2006-2013, 1002, 1005.
- La fabrication relit chaque code dans le PDF final (150 dpi) et échoue si l'un manque.

## Reconstruire

```bash
pip install pymupdf pyzbar pillow      # + apt install libzbar0
python3 tools/make_ill.py              # illustrations SVG
python3 tools/build.py                 # manuel PDF + placement et vérification des codes
python3 tools/build_cover.py           # couverture
```

- `src/m*.html` : le texte (un fichier par partie), `src/style.css` : la maquette.
- `assets/crops/` : captures de l'original découpées avec leurs flèches et légendes (`tools/crop_orig.py`).
- `assets/fonts/` : EB Garamond ; `assets/img/` : visuels de couverture et ISBN.
