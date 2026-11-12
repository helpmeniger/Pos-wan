# Marwa Fiscal Connect — Rapport de tests

**Date d'exécution :** 12/11/2026 · **Environnement :** Linux, PHP 8.3.6, MariaDB 10.11.14, serveur PHP intégré.
**Commande :** `TEST_DB_USER=… TEST_DB_PASS=… php tests/run.php` (base de test recréée à chaque exécution).
**Résultat global :** 174 tests réussis, 0 échec (dont 7 blocs consacrés au module fiscal ; les 167 autres vérifient que le reste de POS Marwa n'est pas affecté).

## Tests réellement exécutés

| Phase | Ce qui est vérifié | Résultat |
|---|---|---|
| 3 — Migration | 12 tables créées ; 11 pays sans aucune règle ni taux ; réinstallation sans effet ; ventes intactes ; retour arrière hors des migrations automatiques | Réussi |
| 4 — Moteur (simulation) | Certification ; double envoi sans nouvel appel ; ligne certifiée non modifiable ; refus ; dispositif injoignable → file → tentatives épuisées → relance manuelle ; réponse perdue → interrogation de l'état sans second envoi ; réponse incomplète refusée ; authentification refusée ; simulation interdite en production ; taux sans groupe non envoyé ; annulation bloquée ; secrets chiffrés et absents des journaux | Réussi |
| 5 — Pilotes (fausse API locale) | REST : clé absente, mauvaise clé, connexion, certification par le moteur, clé masquée dans journaux et preuve, état connu/inconnu, point d'accès non configuré, HTTP 422/503, réponse non JSON, délai dépassé, reprise par la file, HTTP refusé en production. OAuth2 : jeton obtenu, chiffré, demandé une seule fois. SOAP : certification, échappement XML, SOAP Fault. TCP : certification, dispositif éteint. Série : port invalide refusé, port absent. Squelettes : non pris en charge. Aucun pilote déclaré vérifié | Réussi |
| 6 — Interface | 19 écrans sans erreur ; menu ; caissier refusé ; clé jamais réaffichée ; création par formulaire (inactif) ; secret glissé dans la configuration refusé ; simulation en production refusée ; squelette non activable ; test de connexion ; suppression refusée si utilisé ; règles et taux sans source refusés ; production refusée sans pays configuré ; import OpenAPI ; certificat hors dossier public ; webhook : signature et jeton exigés, numéro jamais recopié ; relance depuis l'écran | Réussi |
| 7 — Ventes et documents | Vente certifiée ; ticket et A4 marqués « NON CERTIFIÉ – TEST » avec NIF et numéro ; ESC/POS ; annulation refusée ; avoir certifié référençant la facture ; panne → vente enregistrée, ticket « FACTURE NON CERTIFIÉE » sans numéro, puis certifiée par la file ; blocage d'impression (navigateur et serveur) ; vente annulée avant envoi jamais transmise ; écouteur en erreur sans effet sur la vente ; module inactif → documents inchangés | Réussi |
| 8 — Sécurité | XSS échappé ; CSRF exigé ; gérant sans accès aux secrets ni à la configuration ; traversée de chemin ; masquage Basic/Bearer/JSON/formulaire ; certification intacte après relance ; secret illisible avec une autre APP_KEY ; rapport et clôture du dispositif (simulation), clôture tracée | Réussi |

Contrôle visuel (navigateur sans interface) : tableau de bord, connecteurs, file d'attente, ticket certifié en simulation.

## Ce qui N'A PAS été testé
- **Aucun MCF réel ni aucune API officielle** (SECeF Niger, e-MECeF Bénin, FNE Côte d'Ivoire, etc.) : aucune spécification officielle n'a été fournie.
  Les pilotes génériques ont été testés uniquement contre une **fausse API écrite pour les tests**, sans lien avec un service officiel.
- **Port série / USB sur matériel réel** et ports COM sous Windows : seuls la validation des paramètres et le cas « port absent » sont testés.
- **Établissement de connexion mTLS** avec un vrai serveur : seuls l'import et le contrôle des certificats sont testés.
- Navigateurs et imprimantes thermiques réels : le ticket ESC/POS est vérifié sur les octets produits, pas sur papier.
- Charge, montée en volume et environnement Windows/WampServer pour ce module.
- Aucun test d'intrusion n'a été mené sur un système tiers.

## Conclusion
Le moteur, la file d'attente, la sécurité des secrets et l'intégration aux ventes fonctionnent selon les tests ci-dessus.
**Le module n'est utilisable en production fiscale qu'après** : configuration du pays d'après les textes officiels, obtention de la
spécification du dispositif ou de l'API, et validation du connecteur sur l'environnement de test officiel.
