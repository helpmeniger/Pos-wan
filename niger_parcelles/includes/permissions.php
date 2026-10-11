<?php
/**
 * Matrice des permissions par rôle. Toute action est contrôlée côté serveur
 * via can() / require_permission() : masquer un bouton ne suffit jamais.
 */
declare(strict_types=1);

const PERMISSIONS = [
    'parcel.view'          => 'Consulter les parcelles',
    'parcel.create'        => 'Créer des parcelles',
    'parcel.edit'          => 'Modifier toute parcelle',
    'parcel.edit_own'      => 'Modifier ses propres parcelles non validées',
    'parcel.submit'        => 'Soumettre à vérification',
    'parcel.verify'        => 'Contrôler / signaler des anomalies',
    'parcel.validate'      => 'Valider définitivement un dossier',
    'parcel.archive'       => 'Archiver une parcelle',
    'owner.view_personal'  => 'Voir les données personnelles des propriétaires',
    'owner.manage'         => 'Créer / modifier les propriétaires et les liens',
    'owner.mutation'       => 'Enregistrer des mutations',
    'document.view'        => 'Consulter / télécharger les documents',
    'document.upload'      => 'Ajouter des documents',
    'document.verify'      => 'Vérifier des documents',
    'import.run'           => 'Importer des données géographiques',
    'export.run'           => 'Exporter des données',
    'report.view'          => 'Consulter les rapports',
    'overlap.resolve'      => 'Statuer sur les chevauchements',
    'user.manage'          => 'Gérer les utilisateurs',
    'settings.manage'      => 'Gérer les paramètres et le découpage administratif',
    'audit.view'           => 'Consulter le journal d\'audit',
    'backup.manage'        => 'Sauvegarder / restaurer',
];

const ROLE_PERMISSIONS = [
    'admin_general' => ['*'],
    'admin_foncier' => [
        'parcel.view', 'parcel.create', 'parcel.edit', 'parcel.edit_own', 'parcel.submit', 'parcel.verify',
        'parcel.validate', 'parcel.archive', 'owner.view_personal', 'owner.manage', 'owner.mutation',
        'document.view', 'document.upload', 'document.verify', 'import.run', 'export.run', 'report.view',
        'overlap.resolve',
    ],
    'agent_terrain' => [
        'parcel.view', 'parcel.create', 'parcel.edit_own', 'parcel.submit', 'owner.view_personal',
        'owner.manage', 'document.view', 'document.upload',
    ],
    'verificateur' => [
        'parcel.view', 'parcel.verify', 'parcel.validate', 'owner.view_personal', 'document.view', 'document.verify',
        'report.view', 'export.run', 'overlap.resolve',
    ],
    'consultation' => ['parcel.view', 'report.view'],
];

function role_has_permission(?string $roleCode, string $permission): bool
{
    if ($roleCode === null) {
        return false;
    }
    $perms = ROLE_PERMISSIONS[$roleCode] ?? [];
    return in_array('*', $perms, true) || in_array($permission, $perms, true);
}

function can(string $permission): bool
{
    $u = current_user();
    return $u !== null && role_has_permission($u['role_code'], $permission);
}

function require_permission(string $permission): void
{
    if (!can($permission)) {
        audit_log('acces_refuse', null, null, ['permission' => $permission, 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
        if (defined('NP_API')) {
            json_error('Accès refusé : vous ne disposez pas de l\'autorisation nécessaire.', 403);
        }
        http_response_code(403);
        $pageTitle = 'Accès refusé';
        require APP_ROOT . '/includes/header.php';
        echo '<div class="alert alert-danger mt-3"><i class="bi bi-shield-lock"></i> Accès refusé : vous ne disposez pas de l\'autorisation nécessaire pour cette page.</div>';
        require APP_ROOT . '/includes/footer.php';
        exit;
    }
}

/**
 * Peut-on modifier cette parcelle ? Les agents ne modifient que leurs propres
 * parcelles tant qu'elles ne sont pas validées.
 */
function can_edit_parcel(array $parcel): bool
{
    if ((int) ($parcel['is_archived'] ?? 0) === 1) {
        return false;
    }
    if (can('parcel.edit')) {
        return true;
    }
    $u = current_user();
    return $u !== null && can('parcel.edit_own')
        && (int) $parcel['created_by'] === (int) $u['id']
        && in_array($parcel['verification_status'], ['brouillon', 'anomalie', 'rejetee'], true);
}

/**
 * Transitions de statut autorisées selon les permissions.
 * Retourne la liste des statuts cibles possibles pour cette parcelle.
 */
function allowed_status_transitions(array $parcel): array
{
    $s = $parcel['verification_status'];
    $out = [];
    if (in_array($s, ['brouillon', 'anomalie', 'rejetee'], true) && (can_edit_parcel($parcel) && can('parcel.submit'))) {
        $out[] = 'soumise';
    }
    if (can('parcel.verify')) {
        if (in_array($s, ['soumise', 'verifiee', 'anomalie'], true)) {
            $out[] = 'en_verification';
        }
        if (in_array($s, ['soumise', 'en_verification', 'verifiee'], true)) {
            $out[] = 'anomalie';
        }
    }
    if (can('parcel.validate') && in_array($s, ['soumise', 'en_verification'], true)) {
        $out[] = 'verifiee';
        $out[] = 'rejetee';
    }
    if (can('parcel.edit') && $s !== 'brouillon') {
        $out[] = 'brouillon';
    }
    return array_values(array_unique($out));
}
