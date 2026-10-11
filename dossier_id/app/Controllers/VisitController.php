<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Models\Visit;
use App\Services\Access;
use App\Services\ValidationException;

final class VisitController extends Controller
{
    public function index(): string
    {
        Auth::require('persons.view');
        Auth::require('visits.view');
        $f = $this->filters(['date_from', 'date_to', 'service', 'reference', 'reason', 'location', 'closure_status']);
        [$rows, $pager] = Visit::search($f);
        if (array_filter($f)) {
            Audit::log('visit.search', null, null, ['criteres' => array_keys(array_filter($f)), 'resultats' => $pager->total]);
        }
        return $this->view('visits/index', ['title' => 'Historique des passages', 'rows' => $rows, 'pager' => $pager, 'f' => $f]);
    }

    public function create(): string
    {
        $person = Access::personOrFail($this->id('person_id'), 'visits.manage');
        $this->assertActive($person);
        return $this->form($person, [
            'visit_date' => date('Y-m-d\TH:i'),
            'service' => Auth::user()['service'] ?? '',
            'responsible_agent' => Auth::user()['full_name'] ?? '',
        ]);
    }

    public function store(): string
    {
        $person = Access::personOrFail($this->id('person_id'), 'visits.manage');
        $this->assertActive($person);
        try {
            $data = Visit::validate($_POST);
        } catch (ValidationException $e) {
            return $this->form($person, $_POST, $e->errors);
        }
        $id = Visit::create((int) $person['id'], $data, $this->userId());
        Audit::log('visit.create', 'person', (int) $person['id'], ['visit_id' => $id]);
        $this->back('persons.show', ['id' => $person['id'], 'tab' => 'visits'], 'success', 'Passage enregistré.');
    }

    public function edit(): string
    {
        [$visit, $person] = $this->load();
        $visit['visit_date'] = str_replace(' ', 'T', substr((string) $visit['visit_date'], 0, 16));
        return $this->form($person, $visit, [], (int) $visit['id']);
    }

    public function update(): string
    {
        [$visit, $person] = $this->load();
        try {
            $data = Visit::validate($_POST);
        } catch (ValidationException $e) {
            return $this->form($person, $_POST, $e->errors, (int) $visit['id']);
        }
        $reason = $this->input('change_reason');
        if (mb_strlen($reason) < 5) {
            return $this->form($person, $_POST, ['change_reason' => 'Indiquez le motif de la modification.'], (int) $visit['id']);
        }
        $changes = Visit::update((int) $visit['id'], $data, $this->userId());
        Audit::log('visit.update', 'person', (int) $person['id'], ['visit_id' => (int) $visit['id'], 'champs' => $changes, 'motif' => $reason]);
        $this->back('persons.show', ['id' => $person['id'], 'tab' => 'visits'], 'success', 'Passage mis à jour.');
    }

    private function load(): array
    {
        Auth::require('visits.manage');
        $visit = Database::one('SELECT * FROM visits WHERE id = :id', ['id' => $this->id()]);
        if (!$visit) {
            throw new HttpException(404, 'Passage introuvable.');
        }
        $person = Access::personOrFail((int) $visit['person_id'], 'visits.manage');
        $this->assertActive($person);
        return [$visit, $person];
    }

    private function assertActive(array $person): void
    {
        if ($person['status'] !== 'actif') {
            throw new HttpException(403, 'La fiche est archivée : aucune modification possible.');
        }
    }

    private function form(array $person, array $v, array $errors = [], ?int $id = null): string
    {
        return $this->view('visits/form', [
            'title' => $id ? 'Modifier un passage' : 'Enregistrer un passage',
            'person' => $person, 'v' => $v, 'errors' => $errors, 'id' => $id,
        ]);
    }
}
