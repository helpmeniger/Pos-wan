<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Models\Procedure;
use App\Services\Access;
use App\Services\ValidationException;

final class ProcedureController extends Controller
{
    public function index(): string
    {
        Auth::require('persons.view');
        Auth::require('procedures.view');
        $f = $this->filters(['number', 'status', 'service', 'date_from', 'date_to']);
        [$rows, $pager] = Procedure::search($f);
        if (array_filter($f)) {
            Audit::log('procedure.search', null, null, ['criteres' => array_keys(array_filter($f)), 'resultats' => $pager->total]);
        }
        return $this->view('procedures/index', ['title' => 'Procédures', 'rows' => $rows, 'pager' => $pager, 'f' => $f]);
    }

    public function create(): string
    {
        $person = Access::personOrFail($this->id('person_id'), 'procedures.manage');
        $this->assertActive($person);
        return $this->form($person, ['status' => 'entendue', 'decision_final' => 'inconnu']);
    }

    public function store(): string
    {
        $person = Access::personOrFail($this->id('person_id'), 'procedures.manage');
        $this->assertActive($person);
        try {
            $data = Procedure::validate($_POST);
        } catch (ValidationException $e) {
            return $this->form($person, $_POST, $e->errors);
        }
        $id = Procedure::create((int) $person['id'], $data, $this->userId());
        Audit::log('procedure.create', 'person', (int) $person['id'], ['procedure_id' => $id, 'statut' => $data['status']]);
        $this->back('persons.show', ['id' => $person['id'], 'tab' => 'procedures'], 'success', 'Procédure enregistrée.');
    }

    public function edit(): string
    {
        [$proc, $person] = $this->load();
        return $this->form($person, $proc, [], (int) $proc['id']);
    }

    public function update(): string
    {
        [$proc, $person] = $this->load();
        try {
            $data = Procedure::validate($_POST, $proc['status']);
        } catch (ValidationException $e) {
            return $this->form($person, $_POST, $e->errors, (int) $proc['id'], $proc['status']);
        }
        $reason = $this->input('change_reason');
        if (mb_strlen($reason) < 5) {
            return $this->form($person, $_POST, ['change_reason' => 'Indiquez le motif de la modification.'], (int) $proc['id'], $proc['status']);
        }
        $changes = Procedure::update((int) $proc['id'], $data, $this->userId());
        Audit::log('procedure.update', 'person', (int) $person['id'], [
            'procedure_id' => (int) $proc['id'], 'champs' => $changes, 'motif' => $reason,
            'statut' => $proc['status'] !== $data['status'] ? $proc['status'] . ' → ' . $data['status'] : null,
        ]);
        $this->back('persons.show', ['id' => $person['id'], 'tab' => 'procedures'], 'success', 'Procédure mise à jour.');
    }

    private function load(): array
    {
        Auth::require('procedures.manage');
        $proc = Database::one('SELECT * FROM procedures WHERE id = :id', ['id' => $this->id()]);
        if (!$proc) {
            throw new HttpException(404, 'Procédure introuvable.');
        }
        $person = Access::personOrFail((int) $proc['person_id'], 'procedures.manage');
        $this->assertActive($person);
        return [$proc, $person];
    }

    private function assertActive(array $person): void
    {
        if ($person['status'] !== 'actif') {
            throw new HttpException(403, 'La fiche est archivée : aucune modification possible.');
        }
    }

    private function form(array $person, array $pr, array $errors = [], ?int $id = null, ?string $previous = null): string
    {
        return $this->view('procedures/form', [
            'title' => $id ? 'Modifier une procédure' : 'Enregistrer une procédure',
            'person' => $person, 'pr' => $pr, 'errors' => $errors, 'id' => $id,
            'previous_status' => $previous ?? ($id ? ($pr['status'] ?? null) : null),
        ]);
    }
}
