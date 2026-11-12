# Ajouter un MCF ou une API fiscale

## Règle d'or
Ne jamais déclarer un dispositif ou une API compatible sans avoir obtenu sa **spécification officielle** et testé le pilote sur son
**environnement de test officiel** (ou sur le matériel réel fourni par le fabricant ou l'administration).

## Étape 1 — Réunir la documentation
- Spécification technique (protocole, format des trames ou des requêtes, codes d'erreur), version et date.
- Accès à un environnement de test (adresse, identifiants de test) ou un dispositif de test.
- Règles du pays : groupes de taxe, mentions, format du QR, numérotation, conservation.

## Étape 2 — Essayer d'abord un pilote générique
- API REST/JSON → pilote `generic_rest` : décrire `endpoints`, `auth`, `request_template`, `response_map`, `success`.
- Service SOAP → pilote `generic_soap` : enveloppes XML en gabarit + XPath.
- Dispositif réseau ou série qui parle JSON par ligne → `generic_tcp` / `generic_serial`.
Si le protocole s'y prête, aucun code n'est nécessaire : seulement une configuration à valider en TEST.

## Étape 3 — Écrire un pilote dédié (trames propriétaires, signatures spécifiques…)
1. Créer `addons/fiscal/src/Drivers/<Pays>/<Nom>Driver.php`, classe dans `Addons\Fiscal\Drivers\<Pays>`, qui étend `AbstractDriver`.
2. Implémenter `info()` (avec `'verified' => false` tant que les essais officiels ne sont pas concluants) et `capabilities()`.
3. Implémenter les fonctions réellement disponibles ; les autres restent « non prises en charge » (jamais de faux succès).
4. `certifyInvoice()` renvoie `DriverResult::CERTIFIED` **uniquement** sur réponse positive du dispositif, avec `fiscal_number`,
   et selon le cas `device_id`, `signature`, `qr_payload`, `counters`. Le moteur refuse toute réponse incomplète.
5. `getInvoiceStatus($cle)` : indispensable pour éviter une double certification après une réponse perdue.
6. Renvoyer `request` / `response` brutes dans `DriverResult` : le moteur les masque et les conserve comme preuve.
7. Déclarer le pilote dans `DriverRegistry::DRIVERS`.
8. Ajouter des tests dans `tests/run.php` (réponse positive, refus, panne, délai, réponse incomplète, doublon).
9. Passer `verified` à `true` seulement après validation sur l'environnement officiel, en citant la version de la spécification.

## Squelettes livrés
`Niger/SecefDriver`, `Benin/BeninDriver`, `Togo/TogoDriver` : **aucune implémentation**, aucune compatibilité.
Ils servent de point de départ une fois la spécification officielle obtenue.
