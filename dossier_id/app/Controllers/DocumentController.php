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

final class DocumentController extends Controller
{
    public function store(): never
    {
        $person = Access::personOrFail($this->id('person_id'), 'documents.manage');
        $pid = (int) $person['id'];
        if ($person['status'] !== 'actif') {
            $this->back('persons.show', ['id' => $pid], 'danger', 'Fiche archivée : ajout impossible.');
        }
        try {
            $meta = Validator::make($_POST)
                ->text('title', 'Intitulé du document', true, 200)
                ->text('doc_reference', 'Référence', false, 120)
                ->int('visit_id', 'Passage', false, 1)
                ->int('procedure_id', 'Procédure', false, 1)
                ->validated();
            // Le passage / la procédure liés doivent appartenir à la même fiche.
            if ($meta['visit_id'] && !Database::value('SELECT 1 FROM visits WHERE id = :v AND person_id = :p', ['v' => $meta['visit_id'], 'p' => $pid])) {
                throw new ValidationException('Passage lié invalide.');
            }
            if ($meta['procedure_id'] && !Database::value('SELECT 1 FROM procedures WHERE id = :v AND person_id = :p', ['v' => $meta['procedure_id'], 'p' => $pid])) {
                throw new ValidationException('Procédure liée invalide.');
            }
            $up = UploadService::fromUpload($_FILES['document'] ?? null);
            $doc = UploadService::processDocument($up['path']);
        } catch (ValidationException $e) {
            $msg = $e->errors ? implode(' ', $e->errors) : $e->getMessage();
            $this->back('persons.show', ['id' => $pid, 'tab' => 'documents'], 'danger', $msg);
        }
        $name = FileStorage::put('documents', $doc['data']);
        $ext = $doc['mime'] === 'application/pdf' ? 'pdf' : 'jpg';
        $original = preg_replace('/\.[A-Za-z0-9]+$/', '', UploadService::safeName($up['name'])) . '.' . $ext;
        $docId = Database::insert('documents', [
            'person_id' => $pid, 'visit_id' => $meta['visit_id'], 'procedure_id' => $meta['procedure_id'],
            'title' => $meta['title'], 'doc_reference' => $meta['doc_reference'], 'original_name' => $original,
            'storage_name' => $name, 'mime_type' => $doc['mime'], 'size_bytes' => $doc['size'], 'sha256' => $doc['sha256'],
            'uploaded_by' => $this->userId(), 'uploaded_at' => date('Y-m-d H:i:s'),
        ]);
        Database::update('persons', ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $pid]);
        Audit::log('document.upload', 'person', $pid, ['document_id' => $docId, 'type' => $doc['mime']]);
        $this->back('persons.show', ['id' => $pid, 'tab' => 'documents'], 'success', 'Document enregistré (stockage chiffré).');
    }

    public function download(): Response
    {
        Auth::require('documents.view');
        $doc = Database::one('SELECT * FROM documents WHERE id = :id', ['id' => $this->id()]);
        if (!$doc) {
            throw new HttpException(404, 'Document introuvable.');
        }
        Access::personOrFail((int) $doc['person_id']);
        $data = FileStorage::get('documents', $doc['storage_name']);
        if (hash('sha256', $data) !== $doc['sha256']) {
            throw new \RuntimeException('Intégrité du document compromise (empreinte différente).');
        }
        Audit::log('document.download', 'person', (int) $doc['person_id'], ['document_id' => (int) $doc['id']]);
        return Response::file($data, $doc['mime_type'], $doc['original_name'], false);
    }
}
