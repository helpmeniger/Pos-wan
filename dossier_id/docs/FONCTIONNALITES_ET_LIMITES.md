# Fonctionnalités opérationnelles et limites restantes

## Fonctionnalités opérationnelles (testées)

- Authentification sécurisée, changement de mot de passe imposé, verrouillage, expiration de session,
  désactivation des comptes inactifs ou compromis.
- 4 rôles (administrateur, agent d'enregistrement, agent habilité à consulter, responsable de contrôle)
  et matrice des permissions modifiable, tracée, avec garde-fous.
- Fiches individuelles : création, rectification motivée, archivage / désarchivage, accès restreint,
  numéro unique, impression propre.
- Recherche multicritère paginée et filtrée selon les droits ; export CSV journalisé.
- Contrôle des doublons à la création et analyse globale, décision motivée de l'agent.
- Comparaison visuelle manuelle de deux fiches (photos côte à côte, zoom synchronisé).
- Photographies de référence : import contrôlé, ré-encodage, stockage chiffré, retrait motivé.
- Historique des passages ; procédures avec statuts encadrés et historique des changements.
- Documents justificatifs chiffrés, rattachables à un passage ou une procédure.
- Tableau de bord : indicateurs, statistiques agrégées, alertes de sécurité, état de la base.
- Journal d'audit chaîné (consultations, recherches, modifications, refus, exports), vérification
  d'intégrité, export.
- Sauvegardes chiffrées (interface et ligne de commande), vérification, restauration avec
  sauvegarde de sécurité.
- Scripts d'installation, de vérification de l'environnement et de données fictives.
- Documentation en ligne (page « Documentation ») et guides dans `docs/`.

## Limites restantes

| Limite | Conséquence / recommandation |
|---|---|
| Non testé sur Windows / WampServer / Apache / MySQL 8 (tests réalisés sous Linux, MariaDB, serveur intégré PHP) | Recette obligatoire sur le poste cible (RAPPORT_TESTS.md §5). |
| Champs texte (noms, observations) non chiffrés en base — nécessaires à la recherche | Protéger l'accès au serveur MySQL, au disque (BitLocker recommandé) et aux sauvegardes. |
| Pas de rotation automatisée des clés de chiffrement | En cas de compromission d'une clé : procédure manuelle (rechiffrement) à développer. |
| Pas d'authentification à deux facteurs | À envisager si l'application est accessible en réseau. |
| HTTPS non configuré automatiquement | À mettre en place par l'administrateur (INSTALLATION.md, étape 8). |
| Fusion automatique de doublons non implémentée | Doublon confirmé → archivage manuel de la fiche redondante avec le motif. |
| Restriction d'accès par niveau (standard / restreint), pas par service ni par dossier individuel | Suffisant pour une première version ; un cloisonnement par service peut être ajouté. |
| Durées de conservation non automatisées | Archivage manuel ; règles à définir par le service. |
| Les entrées du journal postérieures à une sauvegarde restaurée ne restent que dans la sauvegarde de sécurité | Conserver les sauvegardes « avant-restauration ». |
| Filtre PDF par mots-clés (`/JavaScript`, `/OpenAction`, `/AA`…) : prudent, peut refuser des PDF légitimes avec formulaires | Convertir le document en PDF simple ou en image. |
| Restauration par l'interface limitée par `upload_max_filesize` | Utiliser `scripts\backup.php --restore` pour les fichiers volumineux. |
| Données de démonstration et portraits synthétiques uniquement | Aucune validation sur données réelles. |

## Préparation d'une éventuelle extension biométrique

Comme demandé, **aucune reconnaissance faciale ni recherche biométrique n'est implémentée**.
L'architecture est modulaire (services séparés dans `app/Services/`, photographies rattachées aux
fiches avec référence d'autorisation, contrôle d'accès centralisé dans `Access`, journalisation dans
`Audit`, migrations versionnées). Une étude ultérieure pourrait donc s'y raccorder sans refondre
l'application.

Une telle étude ne devrait être engagée qu'après : validation juridique du cadre applicable,
autorisation institutionnelle, analyse d'impact sur la protection des données, évaluation technique
indépendante (taux d'erreur, biais entre groupes de population), et définition de garanties
(validation humaine systématique, traçabilité, droits des personnes). Ces étapes ne relèvent pas
du développement logiciel.
