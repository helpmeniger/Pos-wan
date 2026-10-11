<?php
declare(strict_types=1);

namespace App\Support;

final class Labels
{
    /** Statuts procéduraux : libellé, classe de badge, explication. */
    public const PROCEDURE_STATUS = [
        'entendue'      => ['Personne entendue', 'st-entendue', 'Entendue dans le cadre d\'une procédure (témoin, victime ou autre). Aucune mise en cause.'],
        'mise_en_cause' => ['Mise en cause', 'st-mise-en-cause', 'Faits allégués, non établis. La personne est présumée innocente.'],
        'poursuivie'    => ['Poursuivie', 'st-poursuivie', 'Poursuites engagées par le parquet. Aucune décision de culpabilité. Présomption d\'innocence.'],
        'condamnee'     => ['Condamnée (décision documentée)', 'st-condamnee', 'Décision judiciaire de condamnation référencée et justifiée par un document.'],
        'acquittee'     => ['Acquittée / relaxée', 'st-acquittee', 'Décision judiciaire d\'acquittement ou de relaxe.'],
        'cloturee'      => ['Dossier clôturé', 'st-cloturee', 'Procédure close (classement, non-lieu ou autre clôture) sans condamnation.'],
    ];

    public const DECISION_FINAL = [
        'inconnu'        => 'Caractère définitif non établi',
        'non_definitive' => 'Décision non définitive (recours possible ou en cours)',
        'definitive'     => 'Décision définitive',
    ];

    public const ACCESS_LEVEL = [
        'standard'  => 'Standard',
        'restreint' => 'Accès restreint',
    ];

    public const PERSON_STATUS = [
        'actif'   => 'Active',
        'archive' => 'Archivée',
    ];

    public const VISIT_CLOSURE = [
        'ouvert'  => 'En cours',
        'cloture' => 'Clôturé',
    ];

    public const SEX = ['M' => 'Masculin', 'F' => 'Féminin'];

    public static function procedureStatus(string $code): string
    {
        return self::PROCEDURE_STATUS[$code][0] ?? $code;
    }

    /** Libellés lisibles pour le journal d'audit. */
    public const AUDIT_ACTIONS = [
        'auth.login' => 'Connexion', 'auth.logout' => 'Déconnexion', 'auth.failure' => 'Échec de connexion',
        'auth.locked' => 'Verrouillage du compte', 'auth.blocked_ip' => 'Poste bloqué (tentatives)',
        'auth.password_changed' => 'Changement de mot de passe',
        'session.expired' => 'Session expirée', 'session.max_duration' => 'Durée de session maximale', 'session.revoked' => 'Session révoquée',
        'access.denied' => 'Accès refusé', 'csrf.invalid' => 'Jeton CSRF invalide',
        'person.create' => 'Création de fiche', 'person.update' => 'Rectification de fiche', 'person.view' => 'Consultation de fiche',
        'person.archive' => 'Archivage de fiche', 'person.restore' => 'Désarchivage de fiche', 'person.search' => 'Recherche de fiches',
        'person.export' => 'Export CSV des fiches', 'person.print' => 'Impression de fiche',
        'photo.upload' => 'Import de photographie', 'photo.view' => 'Affichage de photographie',
        'photo.retire' => 'Retrait de photographie', 'photo.primary' => 'Photographie principale',
        'visit.create' => 'Enregistrement de passage', 'visit.update' => 'Modification de passage', 'visit.search' => 'Recherche de passages',
        'procedure.create' => 'Enregistrement de procédure', 'procedure.update' => 'Modification de procédure', 'procedure.search' => 'Recherche de procédures',
        'document.upload' => 'Import de document', 'document.download' => 'Téléchargement de document',
        'duplicate.scan' => 'Analyse des doublons', 'duplicate.compare' => 'Comparaison visuelle', 'duplicate.review' => 'Décision sur doublon',
        'user.create' => 'Création de compte', 'user.update' => 'Modification de compte', 'user.reset_password' => 'Réinitialisation de mot de passe',
        'user.enable' => 'Activation de compte', 'user.disable' => 'Désactivation de compte', 'user.disabled_inactivity' => 'Désactivation pour inactivité',
        'settings.update' => 'Modification des paramètres', 'permissions.update' => 'Modification des permissions',
        'backup.create' => 'Création de sauvegarde', 'backup.restore' => 'Restauration de sauvegarde', 'backup.download' => 'Téléchargement de sauvegarde',
        'audit.view' => 'Consultation du journal', 'audit.export' => 'Export du journal', 'audit.verify' => 'Vérification de l\'intégrité',
        'install' => 'Installation', 'demo.seed' => 'Chargement des données fictives',
    ];
}
