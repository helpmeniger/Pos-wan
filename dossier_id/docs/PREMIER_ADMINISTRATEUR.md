# Création du premier compte administrateur

## 1. Création lors de l'installation

```bat
C:\wamp64\bin\php\php8.3.x\php.exe scripts\install.php
```

Si aucun compte n'existe, le script demande l'identifiant et le nom complet (ou les reçoit via
`--admin=identifiant --admin-name="Nom Prénom"`), puis affiche **une seule fois** un mot de passe
temporaire aléatoire de 16 caractères. Le script ne demande jamais de saisir un mot de passe en
clair dans la console (la saisie masquée n'est pas fiable sous Windows).

```
  [OK]   Compte administrateur « admin » créé.
  Mot de passe temporaire : (16 caractères aléatoires, exemple : Kq7-mT2!xR9#bW4z)
```

## 2. Première connexion

1. Ouvrir l'application et se connecter avec l'identifiant et le mot de passe temporaire.
2. Le changement de mot de passe est **imposé** : au moins 12 caractères (paramétrable),
   trois types de caractères parmi minuscules / majuscules / chiffres / symboles, sans l'identifiant.

## 3. Ce que peut faire l'administrateur

Selon le principe du moindre privilège, le rôle **Administrateur** permet de gérer les comptes,
les paramètres, la matrice des permissions et les sauvegardes, mais **n'ouvre pas l'accès au contenu
des dossiers**. Il ne peut ni modifier son propre rôle, ni désactiver son propre compte.

## 4. Créer les comptes des agents

Menu **Utilisateurs → Nouveau compte** : identifiant, nom complet, service, rôle.

| Rôle | Usage |
|---|---|
| Agent d'enregistrement | création / mise à jour des fiches, photographies, passages, procédures, documents, revue des doublons |
| Agent habilité à consulter | consultation en lecture seule, y compris fiches restreintes et archivées, export CSV |
| Responsable de contrôle | journal d'audit, vérification d'intégrité, statistiques |
| Administrateur | technique uniquement |

Un mot de passe temporaire est affiché une fois : transmettez-le par un moyen sûr (en main propre).
L'agent devra le changer à la première connexion.

## 5. Bonnes pratiques

- Un compte nominatif par personne ; jamais de compte partagé.
- Au moins **deux administrateurs** pour éviter le blocage (congés, départ).
- Un **responsable de contrôle distinct** de l'administrateur (séparation des fonctions).
- Désactiver immédiatement le compte d'un agent qui quitte le service ou en cas de compromission
  (Utilisateurs → Désactiver, motif obligatoire) : ses sessions sont révoquées à la requête suivante.
- Comptes inactifs : désactivation automatique après 90 jours (paramétrable) à la tentative de connexion ;
  la réactivation par un administrateur est journalisée.
- Compte verrouillé après 5 échecs (15 min, paramétrable) : « Réinitialiser le mot de passe /
  déverrouiller » sur la fiche du compte.

## 6. Administrateur perdu

Si plus aucun administrateur ne peut se connecter, sur le serveur :

```bat
C:\wamp64\bin\php\php8.3.x\php.exe -r "require 'app/bootstrap.php'; $p = App\Controllers\UserController::temporaryPassword(); App\Core\Database::update('users', ['password_hash' => App\Core\Auth::hashPassword($p), 'must_change_password' => 1, 'locked_until' => null, 'failed_attempts' => 0, 'is_active' => 1], 'username = :u', ['u' => 'admin']); App\Core\Audit::log('user.reset_password', null, null, ['origine' => 'console'], null, 'console'); echo $p, PHP_EOL;"
```

(remplacer `admin` par l'identifiant concerné ; l'opération est inscrite au journal d'audit).
