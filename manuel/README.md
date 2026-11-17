# Manuel HELP-ME — Apprendre l'informatique de bureau (2e édition, 2026)

Sources et outils de fabrication de la 2e édition du manuel connecté HELP-ME.

## Livrables (`build/`)

| Fichier | Contenu |
|---|---|
| `HELP-ME_Tome1_Informatique-Windows-Internet-IA_2e-edition.pdf` | Tome 1 : ordinateur, Windows 11, Internet, cybersécurité, **intelligence artificielle**, astuces et exercices (166 p.) |
| `HELP-ME_Tome2_Word-Excel_2e-edition.pdf` | Tome 2 : Word et Excel (Microsoft 365), avec l'IA (101 p.) |
| `HELP-ME_Manuel-complet_2e-edition.pdf` | Les deux tomes réunis |
| `HELP-ME_Couverture_Tome1/2_2e-edition.pdf` | Couvertures (4e + 1re de couverture, format de la 1re édition : 612 × 396 pt) |
| `codes.json` | Rapport de fabrication : codes déclarés et pages où ils ont été relus |

Format intérieur : **A6 (105 × 148 mm)**, identique à la 1re édition.

## Codes-barres et application

`codes-helpme.json` liste les 65 codes (code → tome, leçon, statut, pages) pour la synchronisation avec l'application.

- Symbologie : **Code 39**, sans clé de contrôle, valeur à 4 chiffres (texte encodé `*NNNN*`), comme la police « Bar-Code-39 » de la 1re édition.
- Les **28 codes de la 1re édition** (2001-2005, 2014-2019, 1000-1001, 1003-1004, 1006-1018) sont conservés à l'identique et rattachés aux mêmes leçons.
- Nouveaux codes : **2020-2034** (Tome 1), **3001-3011** (module IA), **1019-1027** (Tome 2).
- Numéros non utilisés volontairement (absents de la 1re édition, peut-être réservés dans l'application) : 2006-2013, 1002, 1005.
- Chaque fabrication relit automatiquement tous les codes dans le PDF final (pyzbar) ; ils sont lisibles dès 120 dpi.

## Reconstruire

```bash
pip install pymupdf pyzbar pillow      # + apt install libzbar0
python3 tools/make_ill.py              # illustrations SVG (src/ill/)
python3 tools/build.py all             # PDF des deux tomes + vérification des codes
python3 tools/build_cover.py           # couvertures
```

Prérequis : Node.js avec Playwright/Chromium, police Inter.

## Organisation

- `src/*.html` : le texte du manuel (un fichier par partie), `src/style.css` : la maquette d'impression.
- `src/ill/` : illustrations vectorielles générées par `tools/make_ill.py`.
- `assets/orig/` : images extraites de la 1re édition ; `assets/img/` : visuels de couverture.
- `tools/code39.py` : générateur Code 39 ; `tools/build.py` : assemblage, sommaire, PDF, vérification.

Voir `CHANGELOG.md` pour le détail des corrections et nouveautés.
