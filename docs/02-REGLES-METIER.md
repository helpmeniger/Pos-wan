# JOB NIGER — 02. Règles métier, rôles et workflows

## 1. Acteurs et rôles

Un même compte peut cumuler **WORKER** et **CLIENT** (un étudiant peut aussi recruter).
Les rôles du personnel sont attribués uniquement par un `SUPER_ADMIN`.

| Rôle | Périmètre |
|---|---|
| `WORKER` — Prestataire | Profil, compétences, disponibilités, candidatures, missions, revenus |
| `CLIENT` — Demandeur | Annonces, recherche, recrutement, paiements, favoris ; via `businesses` : comptes professionnels multi-membres et multi-sites |
| `SUPER_ADMIN` | Tout, y compris rôles, passerelles, règles de commission, ajustements |
| `ADMIN` | Exploitation (utilisateurs, catalogue, annonces, missions, litiges, paramètres) — **sans** finance sensible ni rôles |
| `FINANCE_ADMIN` | Transactions, grand livre, rapprochement, versements, remboursements, exports, règles de commission |
| `MODERATOR` | Annonces, avis, signalements, vérifications, catalogue |
| `SUPPORT` | Tickets, consultation limitée des comptes et missions |

Matrice détaillée : `database/seeders/001_reference_data.sql` (table `role_permissions`).
Membres d'une entreprise : `OWNER`, `MANAGER`, `RECRUITER`, `FINANCE`, `VIEWER`.

## 2. Inscription et niveaux de vérification

Inscription : nom, prénom, téléphone (E.164 `+227…`), mot de passe, ville, type de compte,
acceptation CGU + confidentialité (horodatées dans `user_consents`). E-mail facultatif
(paramètre `auth.email_required`). OTP SMS activable quand un fournisseur est configuré.

| Niveau | Nom | Exigences | Débloque |
|---|---|---|---|
| 0 | Inscrit | Formulaire | Navigation, candidatures aux métiers `LOW` niveau 0 |
| 1 | Téléphone vérifié | OTP (ou vérification manuelle) | Métiers `MEDIUM` ; badge « Téléphone vérifié » |
| 2 | Identité vérifiée | Pièce d'identité contrôlée par un modérateur, date de naissance | Métiers `HIGH`, travail chez des particuliers, auprès de mineurs/personnes vulnérables ; badge « Profil vérifié » |
| 3 | Qualification vérifiée | Diplôme / autorisation / permis valide pour le métier | Métiers `REGULATED` (sécurité, santé, conduite pro, drone…) |

- On **ne demande pas** de pièce d'identité à tout le monde : seulement quand le métier visé l'exige.
- Les pièces sont stockées en privé, consultées uniquement avec `users.view_identity_docs` (accès journalisé),
  purgées après `privacy.identity_doc_retention_days` ; seuls les 4 derniers caractères du numéro sont conservés.
- Les pièces ne sont **jamais** visibles sur un profil public.
- Compétences : statut **DÉCLARÉE** ou **VÉRIFIÉE** affiché distinctement ; expérience séparée.

## 3. Âge et métiers à risque

- On ne suppose jamais qu'un étudiant est majeur : `birth_date` est demandée dès qu'un métier a `min_age > 16`
  ou un niveau ≥ 2.
- Par défaut `min_age = 18`. Certaines tâches numériques légères sont à 16 ans **sous réserve de validation
  juridique** (Code du travail nigérien, autorisation parentale) — paramétrable par métier.
- Un candidat ne peut postuler que si : âge ≥ `min_age` du métier **et** de l'annonce, niveau ≥ niveau requis,
  qualification `VERIFIEE` si `requires_qualification`.
- `risk_level` : `LOW`, `MEDIUM`, `HIGH` (dangereux, en hauteur, électricité, mineurs/personnes vulnérables),
  `REGULATED` (profession réglementée). Les annonces `HIGH`/`REGULATED` passent en **modération obligatoire**
  et affichent la consigne de sécurité de la catégorie.

## 4. Annonces (job_posts)

Champs obligatoires : titre, description (≥ 40 caractères), catégorie/métier, type de mission, localisation
(ville, ou « à distance »), dates de début et fin, date limite de candidature, nombre de postes, unité de
rémunération (`HEURE`, `JOUR`, `TACHE`, `FORFAIT`), montant unitaire, conditions d'annulation.

Contrôles à la publication :
1. Montant total estimé dans `[finance.min_mission_amount ; finance.max_mission_amount]`.
2. `end_date ≥ start_date`, date limite ≤ début, début ≥ aujourd'hui.
3. Métier actif et autorisé dans la ville / pour ce type de mission.
4. Filtre `moderation_rules` (frais de recrutement, caution, gain garanti…) → `BLOQUER` ou `EN_MODERATION`.
5. Limite d'annonces actives du plan d'abonnement de l'entreprise.

### Cycle de vie d'une annonce
```
BROUILLON ─► (EN_MODERATION ─► REFUSEE) ─► PUBLIEE/CANDIDATURES_OUVERTES
   CANDIDATURES_OUVERTES ─► EN_NEGOCIATION ─► ATTRIBUEE (tous les postes pourvus)
   ATTRIBUEE ─► EN_COURS ─► EN_ATTENTE_DE_VALIDATION ─► TERMINEE
   * ─► ANNULEE  |  date limite dépassée sans attribution ─► EXPIREE  |  litige ─► EN_LITIGE
```
Le statut de l'annonce est un **résumé** ; la vérité opérationnelle est portée par chaque **mission**
(une par travailleur engagé). Une annonce de 5 postes donne 5 missions.

## 5. Candidatures, invitations, attribution

- Une seule candidature par travailleur et par annonce (clé unique). Statuts :
  `SOUMISE → VUE → PRESELECTIONNEE / INFOS_DEMANDEES → EN_NEGOCIATION → ACCEPTEE | REFUSEE`, `RETIREE`, `EXPIREE`.
- Le demandeur peut inviter un travailleur (`job_invitations`) ; accepter l'invitation crée la candidature.
- **Anti-surréservation :** l'acceptation s'exécute dans une transaction :
  `SELECT … FROM job_posts WHERE id = ? FOR UPDATE` → vérifie `positions_filled < positions_total` →
  crée la mission → incrémente. Un trigger interdit en dernier recours tout dépassement.
- Quand tous les postes sont pourvus, les autres candidatures passent `REFUSEE` (motif « poste pourvu ») avec notification.

## 6. Négociation et contrat

- Négociation possible si l'annonce est `is_negotiable`. Chaque offre/contre-offre est une ligne **immuable**
  de `negotiation_messages` (montant unitaire, unités, total calculé serveur, dates).
- Une partie ne peut accepter que la **dernière** offre de l'autre partie.
- Accord ⇒ `ContractService` génère le **récapitulatif contractuel** : description, dates, horaires, lieu,
  prix convenu, unité, frais pris en charge, conditions d'annulation, mode de paiement, **ventilation de la
  commission** (voir doc 03), version des CGU. Le contenu est figé en JSON + empreinte SHA-256.
- Les **deux** parties acceptent explicitement (case + bouton, horodatage, IP) ; l'acceptation porte sur
  l'empreinte. Toute modification ⇒ nouvelle version, nouvelles acceptations.
- La mission ne passe `ATTRIBUEE` qu'après les deux acceptations.

## 7. Workflow d'une mission (missions.status)

```
EN_NEGOCIATION ──(2 acceptations du contrat)──► ATTRIBUEE
ATTRIBUEE ──(prestataire « Je commence » ou heure de début)──► EN_COURS
EN_COURS ──(prestataire « Travail terminé »)──► EN_ATTENTE_DE_VALIDATION   (auto_confirm_at = +72 h)
EN_ATTENTE_DE_VALIDATION ──(demandeur valide | délai écoulé sans contestation)──► TERMINEE
ATTRIBUEE / EN_COURS ──(annulation selon conditions)──► ANNULEE
EN_COURS / EN_ATTENTE_DE_VALIDATION / TERMINEE(≤ 7 j) ──(litige)──► EN_LITIGE ──(décision)──► TERMINEE | ANNULEE
```
- Chaque transition passe par `MissionWorkflowService::transition()` qui vérifie la **table des transitions
  autorisées**, l'acteur (Policy), écrit `mission_status_history` et déclenche les notifications.
- **Paiement et mission sont indépendants** : en mode intégré, `EN_COURS` exige un paiement `CONFIRMED` ;
  en mode direct, le paiement est déclaré après la mission.
- Archivage : 30 jours après `TERMINEE`/`ANNULEE` (lecture seule, documents conservés).

## 8. Messagerie

- Conversations `DIRECTE`, `ANNONCE`, `MISSION`, `LITIGE`, `SUPPORT` ; accès **uniquement** aux membres
  (`conversation_members`) — vérifié par `ConversationPolicy` sur chaque lecture/écriture.
- Texte, photos et PDF (contrôle de type réel, taille), horodatage, accusé de lecture (`last_read_message_id`),
  blocage (`user_blocks`), signalement.
- Le personnel ne lit une conversation que si elle est **signalée** ou **versée à un litige**
  (`messages.view_reported`), accès journalisé. Pas de surveillance non annoncée.
- Le filtre anti-hameçonnage (« code secret », « code PIN ») **avertit** l'utilisateur, il ne bloque pas.

## 9. Litiges

Motifs : travail non réalisé, incomplet, retard, non-paiement, désaccord sur le prix, comportement inapproprié,
fausse annonce, fraude, dommages, non-respect des conditions.
Statuts : `OUVERT → EN_INSTRUCTION ⇄ INFOS_DEMANDEES → MEDIATION → RESOLU_AMIABLE | RESOLU_DECISION | REJETE → CLOS`.
- Ouverture ⇒ mission `EN_LITIGE`, paiement éventuel gelé (pas de versement), conversation de litige créée.
- Chaque action est tracée dans `dispute_events` (notes internes distinguées).
- Résolutions possibles : remboursement total/partiel, paiement du prestataire, reprise de mission,
  sanction (suspension), accord des parties.
- La plateforme agit en **médiateur** ; les CGU rappellent les voies de recours (juridictions compétentes au Niger).

## 10. Avis et réputation

- Seulement après une mission `TERMINEE` (ou litige clos en faveur de l'exécution), dans les 30 jours, une fois par partie.
- 5 critères notés 1–5 : ponctualité, qualité, communication, respect des engagements, professionnalisme ;
  moyenne calculée serveur ; agrégats recalculés dans `user_profiles`.
- Modification : nouvelle révision dans `review_revisions` (historique conservé) ; modération possible (masquage motivé).
- Badges : « Profil vérifié » (niveau ≥ 2), « Missions terminées » (≥ 5), « Prestataire bien noté » (≥ 4,5 sur ≥ 5 avis).

## 11. Signalements et prévention des abus

Motifs dont `FRAIS_RECRUTEMENT`, `MINEUR_EN_DANGER`, `DANGER_IMMEDIAT` (priorité critique, notification immédiate
des modérateurs). Suspension temporaire / bannissement motivés, notifiés, avec **procédure de recours** via le support.
Les annonces exigeant des frais de candidature sont interdites.

## 12. Prestation indépendante vs emploi salarié

Les missions sont par défaut des **prestations de services indépendantes**. Le type `EMPLOI_TEMPORAIRE` et les
missions récurrentes de longue durée pour un même donneur d'ordre affichent un avertissement : la relation peut
relever du **Code du travail** (contrat, déclarations sociales) ; la plateforme ne doit pas servir à contourner
ces obligations. **Validation juridique requise avant la mise en production** (droit nigérien, règles UEMOA/BCEAO
sur les paiements, loi nigérienne sur la protection des données personnelles).

## 13. Lutte contre le contournement des commissions

Mesures proportionnées, sans blocage abusif : messagerie intégrée, contrat récapitulatif, reçus, historique,
avis réservés aux missions suivies, protection en cas de litige uniquement pour les missions suivies,
commission annoncée **avant** tout engagement, CGU explicites, signalement « CONTOURNEMENT ».
Les numéros de téléphone sont partagés après attribution (`contact_visibility`), sans collecte cachée.
