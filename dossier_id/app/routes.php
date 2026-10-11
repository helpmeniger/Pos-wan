<?php
declare(strict_types=1);

use App\Controllers as C;

/**
 * Table des routes : nom => [contrôleur, méthode, verbe HTTP, publique ?]
 * Toutes les routes POST sont soumises à la vérification CSRF par le routeur.
 * Les permissions sont vérifiées dans chaque action (côté serveur).
 */
return [
    'auth.login'          => [C\AuthController::class, 'loginForm', 'GET', true],
    'auth.login.submit'   => [C\AuthController::class, 'login', 'POST', true],
    'auth.logout'         => [C\AuthController::class, 'logout', 'POST', false],
    'auth.password'       => [C\AuthController::class, 'passwordForm', 'GET', false],
    'auth.password.save'  => [C\AuthController::class, 'passwordSave', 'POST', false],

    'dashboard'           => [C\DashboardController::class, 'index', 'GET', false],

    'persons.index'       => [C\PersonController::class, 'index', 'GET', false],
    'persons.export'      => [C\PersonController::class, 'export', 'GET', false],
    'persons.create'      => [C\PersonController::class, 'create', 'GET', false],
    'persons.store'       => [C\PersonController::class, 'store', 'POST', false],
    'persons.show'        => [C\PersonController::class, 'show', 'GET', false],
    'persons.print'       => [C\PersonController::class, 'printView', 'GET', false],
    'persons.edit'        => [C\PersonController::class, 'edit', 'GET', false],
    'persons.update'      => [C\PersonController::class, 'update', 'POST', false],
    'persons.archive'     => [C\PersonController::class, 'archive', 'POST', false],
    'persons.restore'     => [C\PersonController::class, 'restore', 'POST', false],

    'photos.store'        => [C\PhotoController::class, 'store', 'POST', false],
    'photos.show'         => [C\PhotoController::class, 'show', 'GET', false],
    'photos.retire'       => [C\PhotoController::class, 'retire', 'POST', false],
    'photos.primary'      => [C\PhotoController::class, 'primary', 'POST', false],

    'visits.index'        => [C\VisitController::class, 'index', 'GET', false],
    'visits.create'       => [C\VisitController::class, 'create', 'GET', false],
    'visits.store'        => [C\VisitController::class, 'store', 'POST', false],
    'visits.edit'         => [C\VisitController::class, 'edit', 'GET', false],
    'visits.update'       => [C\VisitController::class, 'update', 'POST', false],

    'procedures.index'    => [C\ProcedureController::class, 'index', 'GET', false],
    'procedures.create'   => [C\ProcedureController::class, 'create', 'GET', false],
    'procedures.store'    => [C\ProcedureController::class, 'store', 'POST', false],
    'procedures.edit'     => [C\ProcedureController::class, 'edit', 'GET', false],
    'procedures.update'   => [C\ProcedureController::class, 'update', 'POST', false],

    'documents.store'     => [C\DocumentController::class, 'store', 'POST', false],
    'documents.download'  => [C\DocumentController::class, 'download', 'GET', false],

    'duplicates.index'    => [C\DuplicateController::class, 'index', 'GET', false],
    'duplicates.compare'  => [C\DuplicateController::class, 'compare', 'GET', false],
    'duplicates.review'   => [C\DuplicateController::class, 'review', 'POST', false],

    'users.index'         => [C\UserController::class, 'index', 'GET', false],
    'users.create'        => [C\UserController::class, 'create', 'GET', false],
    'users.store'         => [C\UserController::class, 'store', 'POST', false],
    'users.edit'          => [C\UserController::class, 'edit', 'GET', false],
    'users.update'        => [C\UserController::class, 'update', 'POST', false],
    'users.reset'         => [C\UserController::class, 'resetPassword', 'POST', false],
    'users.toggle'        => [C\UserController::class, 'toggle', 'POST', false],

    'audit.index'         => [C\AuditController::class, 'index', 'GET', false],
    'audit.export'        => [C\AuditController::class, 'export', 'GET', false],
    'audit.verify'        => [C\AuditController::class, 'verify', 'POST', false],

    'backups.index'       => [C\BackupController::class, 'index', 'GET', false],
    'backups.create'      => [C\BackupController::class, 'create', 'POST', false],
    'backups.verify'      => [C\BackupController::class, 'verify', 'POST', false],
    'backups.restore'     => [C\BackupController::class, 'restore', 'POST', false],
    'backups.download'    => [C\BackupController::class, 'download', 'GET', false],

    'settings.index'      => [C\SettingsController::class, 'index', 'GET', false],
    'settings.update'     => [C\SettingsController::class, 'update', 'POST', false],
    'settings.permissions'=> [C\SettingsController::class, 'permissions', 'POST', false],

    'help.index'          => [C\HelpController::class, 'index', 'GET', false],
];
