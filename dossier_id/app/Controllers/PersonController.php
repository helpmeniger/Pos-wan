<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Models\Person;
use App\Models\Procedure;
use App\Models\Visit;
use App\Services\Access;
use App\Services\DuplicateService;
use App\Services\ValidationException;
use App\Support\Labels;

final class PersonController extends Controller
{
    private const FILTERS = ['q', 'approx', 'file_number', 'admin_id', 'birth_from', 'birth_to', 'reg_from', 'reg_to',
                             'status', 'procedure_status', 'procedure_number', 'sort'];

    public function index(): string
    {
        Auth::require('persons.view');
        $f = $this->filters(self::FILTERS);
        if (in_array($f['status'], ['archive', 'tous'], true) && !Auth::can('persons.view_archived')) {
            $f['status'] = 'actif';
        }
        [$rows, $pager] = Person::search($f);
        $searched = array_filter($f, fn ($v, $k) => $v !== '' && !in_array($k, ['sort', 'status'], true), ARRAY_FILTER_USE_BOTH);
        if ($searched) {
            Audit::log('person.search', null, null, ['criteres' => array_keys($searched), 'resultats' => $pager->total]);
        }
        return $this->view('persons/index', ['title' => 'Fiches individuelles', 'rows' => $rows, 'pager' => $pager, 'f' => $f]);
    }

    public function export(): Response
    {
        Auth::require('persons.view');
        Auth::require('export.csv');
        $f = $this->filters(self::FILTERS);
        if (in_array($f['status'], ['archive', 'tous'], true) && !Auth::can('persons.view_archived')) {
            $f['status'] = 'actif';
        }
        [$rows, $pager] = Person::search($f, 20, true);
        Audit::log('person.export', null, null, ['lignes' => $pager->total]);
        $out = array_map(fn ($p) => [
            $p['file_number'], $p['last_name'], $p['first_name'], $p['other_names'], fmt_date($p['birth_date']),
            $p['nationality'], fmt_date($p['registration_date']), $p['registering_service'],
            Labels::PERSON_STATUS[$p['status']], Labels::ACCESS_LEVEL[$p['access_level']], fmt_date($p['updated_at'], true),
        ], $rows);
        return Response::csv(
            ['N° dossier', 'Nom', 'Prénom', 'Autres noms', 'Naissance', 'Nationalité', 'Enregistrement', 'Service', 'Statut', 'Accès', 'Mise à jour'],
            $out,
            'fiches_' . date('Ymd_His') . '.csv'
        );
    }

    public function create(): string
    {
        Auth::require('persons.create');
        return $this->form(['registration_date' => date('Y-m-d'), 'registering_service' => Auth::user()['service'] ?? '']);
    }

    private function form(array $p, array $errors = [], array $duplicates = [], ?int $id = null): string
    {
        return $this->view('persons/form', [
            'title' => $id ? 'Rectifier la fiche' : 'Nouvelle fiche',
            'p' => $p, 'errors' => $errors, 'duplicates' => $duplicates, 'id' => $id,
        ]);
    }

    public function store(): string
    {
        Auth::require('persons.create');
        try {
            $data = Person::validate($_POST);
        } catch (ValidationException $e) {
            return $this->form($_POST, $e->errors);
        }
        // Contrôle des doublons avant création (sauf confirmation explicite de l'agent).
        $dups = DuplicateService::candidatesFor($data, null);
        if ($dups && $this->input('confirm_distinct') !== '1') {
            $visible = array_values(array_filter($dups, fn ($d) => Access::canViewPerson($d['person'])));
            $hidden = count($dups) - count($visible);
            return $this->form($_POST, [], ['visible' => $visible, 'hidden' => $hidden]);
        }
        $justif = $this->input('distinct_justification');
        if ($dups && mb_strlen($justif) < 5) {
            $visible = array_values(array_filter($dups, fn ($d) => Access::canViewPerson($d['person'])));
            return $this->form($_POST, ['distinct_justification' => 'Justifiez la création malgré les doublons potentiels.'],
                ['visible' => $visible, 'hidden' => count($dups) - count($visible)]);
        }
        $id = Person::create($data, $this->userId());
        Audit::log('person.create', 'person', $id, [
            'doublons_signales' => count($dups),
            'justification' => $dups ? mb_substr($justif, 0, 300) : null,
        ]);
        $this->back('persons.show', ['id' => $id], 'success', 'Fiche créée avec succès.');
    }

    public function show(): string
    {
        $person = Access::personOrFail($this->id());
        $id = (int) $person['id'];
        Audit::log('person.view', 'person', $id);
        $data = ['title' => $person['last_name'] . ' ' . $person['first_name'], 'p' => $person];
        $data['photos'] = Auth::can('photos.view') ? Database::all(
            "SELECT * FROM photos WHERE person_id = :p AND status = 'active' ORDER BY is_primary DESC, uploaded_at DESC",
            ['p' => $id]
        ) : [];
        $data['visits'] = Auth::can('visits.view') ? Visit::forPerson($id) : [];
        $data['procedures'] = [];
        if (Auth::can('procedures.view')) {
            foreach (Procedure::forPerson($id) as $pr) {
                $pr['history'] = Procedure::history((int) $pr['id']);
                $data['procedures'][] = $pr;
            }
        }
        $data['documents'] = Auth::can('documents.view') ? Database::all(
            'SELECT d.*, u.full_name AS uploader FROM documents d LEFT JOIN users u ON u.id = d.uploaded_by
             WHERE d.person_id = :p ORDER BY d.uploaded_at DESC',
            ['p' => $id]
        ) : [];
        $data['creator'] = Database::value('SELECT full_name FROM users WHERE id = :id', ['id' => (int) $person['created_by']]);
        $data['updater'] = Database::value('SELECT full_name FROM users WHERE id = :id', ['id' => (int) $person['updated_by']]);
        $data['access_log'] = Auth::can('audit.view') ? Database::all(
            "SELECT created_at, username, action FROM audit_log WHERE entity_type = 'person' AND entity_id = :id ORDER BY id DESC LIMIT 15",
            ['id' => $id]
        ) : [];
        $data['duplicates'] = Auth::can('duplicates.review') && $person['status'] === 'actif'
            ? array_values(array_filter(
                DuplicateService::candidatesFor($person, $id, Access::personSqlFilter('p')),
                fn ($d) => Access::canViewPerson($d['person'])
            )) : [];
        $data['all_photos_count'] = (int) Database::value('SELECT COUNT(*) FROM photos WHERE person_id = :p', ['p' => $id]);
        return $this->view('persons/show', $data);
    }

    public function printView(): string
    {
        $person = Access::personOrFail($this->id());
        $id = (int) $person['id'];
        Audit::log('person.print', 'person', $id);
        return \App\Core\View::render('persons/print', [
            'title' => 'Fiche ' . $person['file_number'],
            'p' => $person,
            'photo' => Auth::can('photos.view') ? Person::primaryPhoto($id) : null,
            'visits' => Auth::can('visits.view') ? Visit::forPerson($id) : [],
            'procedures' => Auth::can('procedures.view') ? Procedure::forPerson($id) : [],
        ], 'layout/print');
    }

    public function edit(): string
    {
        $person = Access::personOrFail($this->id(), 'persons.edit');
        return $this->form($person, [], [], (int) $person['id']);
    }

    public function update(): string
    {
        $person = Access::personOrFail($this->id(), 'persons.edit');
        $id = (int) $person['id'];
        if ($person['status'] === 'archive') {
            throw new HttpException(403, 'Une fiche archivée ne peut pas être modifiée. Désarchivez-la d\'abord.');
        }
        try {
            $data = Person::validate($_POST);
        } catch (ValidationException $e) {
            return $this->form($_POST + ['file_number' => $person['file_number']], $e->errors, [], $id);
        }
        $reason = $this->input('change_reason');
        if (mb_strlen($reason) < 5) {
            return $this->form($_POST + ['file_number' => $person['file_number']],
                ['change_reason' => 'Indiquez le motif de la rectification (5 caractères minimum).'], [], $id);
        }
        if ($data['access_level'] !== $person['access_level'] && !Auth::can('persons.view_restricted')) {
            // Un agent ne peut pas restreindre une fiche qu'il ne pourrait plus consulter.
            $data['access_level'] = $person['access_level'];
        }
        $changes = Person::update($id, $data, $this->userId());
        Audit::log('person.update', 'person', $id, ['champs' => $changes, 'motif' => $reason]);
        $this->back('persons.show', ['id' => $id], 'success', $changes ? 'Fiche rectifiée (' . count($changes) . ' champ(s)).' : 'Aucune modification.');
    }

    public function archive(): never
    {
        $person = Access::personOrFail($this->id(), 'persons.archive');
        $reason = $this->input('reason');
        if (mb_strlen($reason) < 5) {
            $this->back('persons.show', ['id' => $person['id']], 'danger', 'Le motif d\'archivage est obligatoire.');
        }
        Database::update('persons', [
            'status' => 'archive', 'archived_at' => date('Y-m-d H:i:s'), 'archived_by' => $this->userId(),
            'archive_reason' => mb_substr($reason, 0, 255), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $this->userId(),
        ], 'id = :id', ['id' => (int) $person['id']]);
        Audit::log('person.archive', 'person', (int) $person['id'], ['motif' => $reason]);
        $route = Auth::can('persons.view_archived') ? 'persons.show' : 'persons.index';
        $this->back($route, $route === 'persons.show' ? ['id' => $person['id']] : [], 'success',
            'Fiche archivée : elle n\'apparaît plus dans les consultations courantes.');
    }

    public function restore(): never
    {
        Auth::require('persons.archive');
        Auth::require('persons.view_archived');
        $person = Access::personOrFail($this->id());
        $reason = $this->input('reason');
        if (mb_strlen($reason) < 5) {
            $this->back('persons.show', ['id' => $person['id']], 'danger', 'Le motif est obligatoire.');
        }
        Database::update('persons', [
            'status' => 'actif', 'archived_at' => null, 'archived_by' => null, 'archive_reason' => null,
            'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $this->userId(),
        ], 'id = :id', ['id' => (int) $person['id']]);
        Audit::log('person.restore', 'person', (int) $person['id'], ['motif' => $reason]);
        $this->back('persons.show', ['id' => $person['id']], 'success', 'Fiche désarchivée.');
    }
}
