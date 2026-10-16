# Module Clinique (POS Marwa)

Gestion d'une clinique ou d'un cabinet médical, reliée à la pharmacie de POS Marwa.
Version 1.0.0 — nécessite POS Marwa 1.5.0 ou plus.

> ⚠️ Ce module est un outil de gestion. Ce n'est pas un logiciel médical certifié : il ne
> remplace ni le jugement du médecin, ni les protocoles de l'établissement. Les seuils
> d'alerte fournis sont indicatifs et doivent être validés par le médecin responsable.

## Installation

1. Le dossier `addons/clinique/` doit être présent dans POS Marwa.
2. **Gestion → Modules complémentaires → Clinique → Installer** (sauvegarde automatique),
   ou `php bin/addons.php install clinique`.
3. Créer les comptes du personnel (**Utilisateurs**) avec les rôles créés par le module :

| Rôle créé | Pour qui | Droits |
|---|---|---|
| Médecin (clinique) | médecins | consultations, diagnostics, ordonnances, hospitalisation, sortie |
| Infirmier(ère) (clinique) | infirmiers, sages-femmes | constantes, soins, transmissions, écran de supervision |
| Accueil / caisse (clinique) | accueil, caisse | patients, rendez-vous, file d'attente, encaissement des factures |

Les rôles existants reçoivent aussi des droits : propriétaire (accueil + gestion),
pharmacien (file des ordonnances de la clinique), auditeur (consultation).
Pour l'encaissement, le compte doit être associé à une caisse et la caisse ouverte.

## Parcours

1. **Accueil** : rechercher ou créer le dossier patient (n° PAT…), puis « Placer dans la file
   d'attente » (motif, médecin, urgence, constantes de triage) — ou depuis un rendez-vous
   (bouton « Arrivé »).
2. **Consultation** : le médecin ouvre le patient depuis la file, commence la consultation,
   saisit l'observation et le diagnostic (obligatoire pour terminer), les constantes.
3. **Ordonnance** : médicaments choisis dans le stock (le stock est affiché) ou saisis
   librement (hors stock). L'ordonnance s'imprime et part dans **Ordonnances → pharmacie**.
4. **Pharmacie** : le pharmacien ouvre l'ordonnance, indique les quantités délivrées
   (délivrance partielle possible) et encaisse : une **vente normale** est créée (stock,
   lots FEFO, caisse). Si le type de commerce « pharmacie » est activé, le **registre des
   ordonnances** est rempli automatiquement (prescripteur, patient, n° d'ordonnance).
5. **Facture** : actes cochés par le médecin ou l'accueil (tarifs du catalogue, non
   modifiables) ; la prise en charge (assurance / mutuelle, % du dossier patient) est
   déduite ; la part patient est encaissée à l'accueil (mouvement « clinique » de la caisse).
6. **Hospitalisation / mise en observation** : choix d'un lit libre, plan de soins
   (médicament / soin / surveillance, dose, voie, fréquence, durée), constantes avec
   courbe, transmissions, changement de lit, ordonnances. **Sortie** : mode de sortie,
   compte rendu, facture automatique (journées × prix du lit + actes).

## Écran de supervision des malades

**Clinique → Supervision des malades** : écran plein écran, sans menu, prévu pour un
téléviseur ou une tablette du poste infirmier. Actualisation toutes les 20 secondes.

- un cadre par lit : patient, âge, jour d'hospitalisation, diagnostic, **allergies** ;
- dernières constantes (T°, TA, pouls, SpO₂, glycémie) : valeur **en rouge** si hors seuil ;
- cadre **rouge** = alerte sur le dernier relevé (signal sonore à chaque nouvelle alerte,
  désactivable), **orange** = soin en retard, **jaune** = soin dans l'heure ou constantes à
  reprendre (aucun relevé depuis 6 h par défaut) ; les lits en alerte s'affichent en premier ;
- bouton **Fait** sur les soins dus : enregistre le soin et calcule la prochaine prise ;
- filtre par service, affichage des lits libres, plein écran, horloge.

Les seuils se règlent dans **Actes, lits, rapport → Seuils d'alerte**.

## Confidentialité

- Le dossier médical (consultations, constantes, hospitalisations, ordonnances) n'est
  visible qu'avec le droit « clinique.medical » (médecins, infirmiers, administrateur).
  L'accueil et la caisse voient la partie administrative et les factures seulement.
- Chaque ouverture d'un dossier médical, d'une consultation, d'un séjour et de l'écran de
  supervision est enregistrée dans le **journal d'audit**.
- Placer l'écran de supervision hors de la vue du public ; verrouiller les postes.
- Les données de santé sont des données sensibles : sauvegardes chiffrées et stockées en
  lieu sûr, accès au serveur limité, respect de la réglementation applicable au Niger.

## Paramètres et rapport

**Actes, lits, rapport** : rapport par période (consultations, nouveaux patients,
hospitalisations, facturé, tiers payant, encaissé ; actes ; médecins ; diagnostics ;
montants à réclamer aux organismes) avec export CSV / PDF ; tarifs des actes (16 actes
indicatifs en FCFA) ; chambres, lits et prix par jour ; seuils d'alerte.

## Limites

- Pas de laboratoire ni d'imagerie détaillés (résultats d'examens) : facturés comme actes.
- Pas de facturation électronique aux assurances : le rapport donne les montants par organisme.
- Traductions : français et anglais.

## Version 1.1.0 (POS Marwa 2.0)

- **Caisse clinique** (`/clinique/caisse`) :
  - recherche ou création rapide du patient ;
  - choix des prestations ;
  - encaissement total ou partiel (réglage `clinique_partial_payment`) ;
  - ticket.

  Elle s'ouvre automatiquement pour l'accueil en mode Clinique ou Clinique + Pharmacie.
- **Délivrance pharmacie** : contrôle des médicaments par scan (douchette ou caméra) avant la
  vente.
- En mode Clinique + Pharmacie :
  - les prestations médicales et les ventes de médicaments restent des opérations séparées,
    reliées par l'ordonnance ;
  - la caisse principale affiche la file des ordonnances à délivrer.
