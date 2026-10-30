<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AuthService;
use App\Services\Auth\PermissionService;
use App\Services\Files\FileStorageService;

/**
 * Fichiers privés servis uniquement à leur propriétaire ou au personnel habilité
 * (consultation des pièces d'identité journalisée dans le journal d'audit).
 */
final class FileController
{
    public function __construct(
        private readonly Database $db,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly FileStorageService $files,
        private readonly AuditLogger $audit,
    ) {
    }

    public function show(Request $request): Response
    {
        $userId = (int) $this->auth->id();
        $file = $this->db->fetch('SELECT id, owner_user_id, purpose FROM stored_files WHERE uuid = ? AND deleted_at IS NULL', [(string) $request->param('uuid')]);
        if ($file === null) {
            throw new HttpException(404);
        }
        $isOwner = (int) $file['owner_user_id'] === $userId;
        $isStaff = in_array($file['purpose'], ['IDENTITY', 'BUSINESS_DOC', 'QUALIFICATION'], true)
            && $this->permissions->allows($userId, 'users.view_identity_docs');
        if (!$isOwner && !$isStaff) {
            throw new HttpException(404);
        }
        $resolved = $this->files->resolve((int) $file['id']) ?? throw new HttpException(404);
        if (!$isOwner) {
            $this->audit->record($userId, 'identity_doc.viewed', 'stored_file', (int) $file['id'], null, null, 'Consultation pour vérification', $request);
        }
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $resolved['name']) ?: 'document';
        return (new Response((string) file_get_contents($resolved['path']), 200, ['Content-Type' => $resolved['mime']]))
            ->withHeader('Content-Disposition', 'inline; filename="' . $name . '"')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
