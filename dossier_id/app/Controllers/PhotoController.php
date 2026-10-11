<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\Access;
use App\Services\FileStorage;
use App\Services\UploadService;
use App\Services\ValidationException;
use App\Support\Validator;

final class PhotoController extends Controller
{
    public function store(): never
    {
        $person = Access::personOrFail($this->id('person_id'), 'photos.manage');
        $pid = (int) $person['id'];
        if ($person['status'] !== 'actif') {
            $this->back('persons.show', ['id' => $pid], 'danger', 'Impossible d\'ajouter une photographie à une fiche archivée.');
        }
        try {
            $meta = Validator::make($_POST)
                ->text('authorization_ref', 'Référence de l\'autorisation', true, 120)
                ->date('taken_at', 'Date de prise de vue', false, true)
                ->validated();
            if (empty($_POST['consent_confirmed'])) {
                throw new ValidationException('Confirmez que la photographie est fournie avec autorisation.');
            }
            $up = UploadService::fromUpload($_FILES['photo'] ?? null);
            $img = UploadService::processPhoto($up['path']);
        } catch (ValidationException $e) {
            $msg = $e->errors ? implode(' ', $e->errors) : $e->getMessage();
            $this->back('persons.show', ['id' => $pid, 'tab' => 'photos'], 'danger', $msg);
        }
        $dup = Database::value(
            "SELECT id FROM photos WHERE person_id = :p AND sha256_original = :h AND status = 'active'",
            ['p' => $pid, 'h' => $img['sha256']]
        );
        if ($dup) {
            $this->back('persons.show', ['id' => $pid, 'tab' => 'photos'], 'warning', 'Cette photographie est déjà enregistrée sur la fiche.');
        }
        $name = FileStorage::put('photos', $img['data']);
        $hasPrimary = (int) Database::value("SELECT COUNT(*) FROM photos WHERE person_id = :p AND status = 'active' AND is_primary = 1", ['p' => $pid]);
        $photoId = Database::insert('photos', [
            'person_id' => $pid, 'storage_name' => $name, 'mime_type' => $img['mime'],
            'width' => $img['width'], 'height' => $img['height'], 'size_bytes' => strlen($img['data']),
            'sha256_original' => $img['sha256'], 'authorization_ref' => $meta['authorization_ref'],
            'taken_at' => $meta['taken_at'], 'is_primary' => $hasPrimary ? 0 : 1,
            'uploaded_by' => $this->userId(), 'uploaded_at' => date('Y-m-d H:i:s'),
        ]);
        Database::update('persons', ['updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $this->userId()], 'id = :id', ['id' => $pid]);
        Audit::log('photo.upload', 'person', $pid, ['photo_id' => $photoId, 'dimensions' => "{$img['width']}x{$img['height']}"]);
        $this->back('persons.show', ['id' => $pid, 'tab' => 'photos'], 'success', 'Photographie enregistrée (métadonnées supprimées, stockage chiffré).');
    }

    public function show(): Response
    {
        Auth::require('photos.view');
        $photo = Database::one('SELECT * FROM photos WHERE id = :id', ['id' => $this->id()]);
        if (!$photo) {
            throw new HttpException(404, 'Photographie introuvable.');
        }
        Access::personOrFail((int) $photo['person_id']);
        if ($photo['status'] !== 'active' && !Auth::can('photos.manage')) {
            throw new HttpException(403, 'Cette photographie a été retirée.');
        }
        $data = FileStorage::get('photos', $photo['storage_name']);
        Audit::log('photo.view', 'person', (int) $photo['person_id'], ['photo_id' => (int) $photo['id']]);
        return Response::file($data, 'image/jpeg', 'photo_' . $photo['id'] . '.jpg', true);
    }

    public function retire(): never
    {
        $photo = $this->photoForManage();
        $reason = $this->input('reason');
        $pid = (int) $photo['person_id'];
        if (mb_strlen($reason) < 5) {
            $this->back('persons.show', ['id' => $pid, 'tab' => 'photos'], 'danger', 'Le motif du retrait est obligatoire.');
        }
        Database::transaction(function () use ($photo, $reason, $pid) {
            Database::update('photos', [
                'status' => 'retiree', 'is_primary' => 0, 'retired_reason' => mb_substr($reason, 0, 255),
                'retired_at' => date('Y-m-d H:i:s'), 'retired_by' => $this->userId(),
            ], 'id = :id', ['id' => (int) $photo['id']]);
            if ((int) $photo['is_primary']) {
                $next = Database::value("SELECT id FROM photos WHERE person_id = :p AND status = 'active' ORDER BY uploaded_at DESC LIMIT 1", ['p' => $pid]);
                if ($next) {
                    Database::update('photos', ['is_primary' => 1], 'id = :id', ['id' => (int) $next]);
                }
            }
        });
        Audit::log('photo.retire', 'person', $pid, ['photo_id' => (int) $photo['id'], 'motif' => $reason]);
        $this->back('persons.show', ['id' => $pid, 'tab' => 'photos'], 'success', 'Photographie retirée de la fiche (conservée pour traçabilité).');
    }

    public function primary(): never
    {
        $photo = $this->photoForManage();
        $pid = (int) $photo['person_id'];
        if ($photo['status'] !== 'active') {
            throw new HttpException(422, 'Photographie retirée.');
        }
        Database::transaction(function () use ($photo, $pid) {
            Database::run('UPDATE photos SET is_primary = 0 WHERE person_id = :p', ['p' => $pid]);
            Database::update('photos', ['is_primary' => 1], 'id = :id', ['id' => (int) $photo['id']]);
        });
        Audit::log('photo.primary', 'person', $pid, ['photo_id' => (int) $photo['id']]);
        $this->back('persons.show', ['id' => $pid, 'tab' => 'photos'], 'success', 'Photographie de référence mise à jour.');
    }

    private function photoForManage(): array
    {
        Auth::require('photos.manage');
        $photo = Database::one('SELECT * FROM photos WHERE id = :id', ['id' => $this->id()]);
        if (!$photo) {
            throw new HttpException(404, 'Photographie introuvable.');
        }
        $person = Access::personOrFail((int) $photo['person_id']);
        if ($person['status'] !== 'actif') {
            throw new HttpException(403, 'Fiche archivée.');
        }
        return $photo;
    }
}
