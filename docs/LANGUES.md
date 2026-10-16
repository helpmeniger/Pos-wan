# Langues de l'interface

MARWA est disponible en **français** (langue source), **haoussa**, **zarma** et **anglais**.
L'utilisateur choisit sa langue avec le bouton 🌐 de l'en-tête (proposé automatiquement à la
première visite). Le choix est mémorisé sur l'appareil et dans le compte : les notifications
push sont envoyées dans cette langue.

## Qualité des traductions — à lire avant l'ouverture

Les dictionnaires `public/assets/i18n/ha.json`, `dje.json` et `en.json` (1 507 textes chacun)
ont été produits par **traduction automatique assistée**. L'anglais est fiable. Le **haoussa**
et surtout le **zarma** (qualité estimée moyenne) **doivent être relus par des locuteurs natifs**
avant l'ouverture au public : certains termes sont des emprunts au français, d'autres des
propositions à valider (mot de passe, versement, prestataire, points cardinaux…).

## Corriger ou compléter sans programmer

Administration → **Langues et traductions** :

- chaque texte français est affiché avec sa traduction : modifiez-la, elle est enregistrée
  immédiatement et prime sur le fichier livré ;
- filtre « Non traduits seulement » et recherche ;
- cases à cocher pour proposer d'autres langues aux utilisateurs (peul/fulfulde, kanouri,
  tamajaq, arabe) : une langue sans traduction s'affiche en français jusqu'à ce que ses textes
  soient saisis.

## Fonctionnement technique

- `public/assets/js/i18n.js` traduit les textes dès leur insertion dans la page (y compris les
  messages créés par JavaScript) ; `app/Support/I18n.php` fait de même côté serveur.
- Les textes variables utilisent des emplacements : « Commande {1} acceptée ».
- Mise à jour de la liste des textes après une évolution du code :
  `python3 tools/i18n/extract.py` (et `node tests/e2e/i18n-collect.mjs > tools/i18n/runtime.json`
  pour inclure les textes réellement affichés), puis traduire les nouveaux textes dans l'éditeur.
- Test : `node tests/e2e/i18n.mjs` (pages affichées dans chaque langue, sans erreur).

Limites : les textes saisis par les utilisateurs (noms de plats, adresses, messages) et les pages
juridiques (CGU, confidentialité) ne sont pas traduits automatiquement.
