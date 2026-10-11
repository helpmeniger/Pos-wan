<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\HttpException;
use App\Models\Person;
use App\Models\Procedure;
use App\Models\Visit;
use App\Services\Access;
use App\Services\DuplicateService;
use App\Services\ValidationException;
use App\Support\Normalizer;

test('Fiches : validation des champs obligatoires et des formats', function () {
    $e = assert_throws(ValidationException::class, fn () => Person::validate(['last_name' => '', 'first_name' => 'X<script>']));
    assert_true(isset($e->errors['last_name'], $e->errors['first_name'], $e->errors['registration_date']));
    $e = assert_throws(ValidationException::class, fn () => Person::validate(person_data(['birth_date' => '2999-01-01'])));
    assert_true(isset($e->errors['birth_date']), 'date future refusée');
    $e = assert_throws(ValidationException::class, fn () => Person::validate(person_data(['admin_id_number' => 'X1', 'admin_id_type' => ''])));
    assert_true(isset($e->errors['admin_id_type']));
    $ok = Person::validate(person_data(['last_name' => "N'Diaye-Ba", 'first_name' => 'Éloïse']));
    assert_same("N'DIAYE-BA", $ok['last_name']);
});

test('Fiches : création avec numéro unique et mise à jour tracée', function () {
    $agent = make_user('p.agent', 'enregistrement');
    act_as($agent);
    $id = Person::create(Person::validate(person_data(['last_name' => 'Unique', 'first_name' => 'Fiche'])), $agent);
    $p = Person::find($id);
    assert_same(sprintf('DID-2026-%06d', $id), $p['file_number']);
    assert_same('unique', $p['last_name_norm']);
    $changes = Person::update($id, Person::validate(person_data(['last_name' => 'Unique', 'first_name' => 'Fiche', 'profession' => 'Enseignant'])), $agent);
    assert_same(['profession'], $changes);
    assert_same([], Person::update($id, Person::validate(person_data(['last_name' => 'Unique', 'first_name' => 'Fiche', 'profession' => 'Enseignant'])), $agent));
});

test('Fiches : contrôle d\'accès (restreinte, archivée)', function () {
    $agent = make_user('acc.agent', 'enregistrement');
    $consult = make_user('acc.consult', 'consultation');
    $ctrl = make_user('acc.ctrl', 'controleur');
    act_as($agent);
    $restricted = Person::create(person_data(['last_name' => 'Restreinte', 'access_level' => 'restreint']), $agent);
    $archived = Person::create(person_data(['last_name' => 'Archivee']), $agent);
    Database::update('persons', ['status' => 'archive'], 'id = :id', ['id' => $archived]);

    assert_throws(HttpException::class, fn () => Access::personOrFail($restricted));
    act_as($consult);
    assert_same($restricted, (int) Access::personOrFail($restricted)['id']);
    assert_same($archived, (int) Access::personOrFail($archived)['id']);
    act_as($ctrl);
    assert_throws(HttpException::class, fn () => Access::personOrFail($archived));
    assert_throws(HttpException::class, fn () => Access::personOrFail(999999));
});

test('Recherche multicritère : nom, orthographe approchante, numéro, identifiant, dates, statut', function () {
    $agent = make_user('s.agent', 'enregistrement');
    $consult = make_user('s.consult', 'consultation');
    act_as($agent);
    $a = Person::create(person_data(['last_name' => 'Ouédraogo', 'first_name' => 'Moussa', 'admin_id_type' => 'CNI', 'admin_id_number' => 'TST-778899', 'birth_date' => '1980-05-05']), $agent);
    $b = Person::create(person_data(['last_name' => 'Ouedraogo', 'first_name' => 'Musa', 'birth_date' => '1981-06-06']), $agent);
    Procedure::create($a, Procedure::validate(['procedure_number' => 'PRC-SEARCH-1', 'facts_nature' => 'Test', 'investigating_service' => 'Svc', 'status' => 'acquittee',
        'court_reference' => 'T-1', 'decision_text' => 'Relaxe', 'decision_date' => '2026-01-10']), $agent);
    act_as($consult);
    [$r] = Person::search(['q' => 'ouedraogo moussa']);
    assert_same([$a], array_map('intval', array_column($r, 'id')), 'accents ignorés');
    [$r] = Person::search(['q' => 'musa', 'approx' => '1']);
    assert_true(count(array_intersect([$a, $b], array_map('intval', array_column($r, 'id')))) === 2, 'Moussa / Musa');
    $num = Person::find($b)['file_number'];
    [$r] = Person::search(['file_number' => strtolower($num)]);
    assert_same($b, (int) $r[0]['id']);
    [$r] = Person::search(['admin_id' => 'tst 778899']);
    assert_same($a, (int) $r[0]['id']);
    [$r] = Person::search(['q' => 'ouedraogo', 'birth_from' => '1981-01-01']);
    assert_same([$b], array_map('intval', array_column($r, 'id')));
    [$r] = Person::search(['procedure_status' => 'acquittee']);
    assert_true(in_array($a, array_map('intval', array_column($r, 'id')), true));
    [$r] = Person::search(['procedure_number' => 'PRC-SEARCH']);
    assert_same([$a], array_map('intval', array_column($r, 'id')));
    [$r] = Person::search(['q' => "%' OR 1=1 --"]);
    assert_same([], $r, 'injection neutralisée');
});

test('Recherche : pagination', function () {
    $agent = make_user('pg.agent', 'enregistrement');
    act_as($agent);
    for ($i = 0; $i < 25; $i++) {
        Person::create(person_data(['last_name' => 'Paginer', 'first_name' => 'N' . chr(65 + $i)]), $agent);
    }
    $_GET['page'] = '2';
    [$r, $pager] = Person::search(['q' => 'paginer'], 10);
    unset($_GET['page']);
    assert_same(25, $pager->total);
    assert_same(3, $pager->pages);
    assert_same(10, count($r));
    assert_contains('page=3', $pager->links('persons.index', ['q' => 'paginer']));
});

test('Passages : historique par fiche et recherche par période', function () {
    $agent = make_user('v.agent', 'enregistrement');
    act_as($agent);
    $pid = Person::create(person_data(['last_name' => 'Passage']), $agent);
    Visit::create($pid, Visit::validate(['visit_date' => '2026-03-01T10:00', 'location' => 'Bureau', 'service' => 'Accueil-TST',
        'reason' => 'Convocation', 'reference' => 'R-TST-1', 'responsible_agent' => 'Agent X']), $agent);
    Visit::create($pid, Visit::validate(['visit_date' => '2026-04-15T09:30', 'location' => 'Bureau', 'service' => 'Accueil-TST',
        'reason' => 'Retrait', 'reference' => 'R-TST-2', 'responsible_agent' => 'Agent X', 'closure_status' => 'cloture']), $agent);
    assert_same(2, count(Visit::forPerson($pid)));
    [$r] = Visit::search(['date_from' => '2026-04-01', 'date_to' => '2026-04-30', 'service' => 'Accueil-TST']);
    assert_same(['R-TST-2'], array_column($r, 'reference'));
    assert_throws(ValidationException::class, fn () => Visit::validate(['visit_date' => 'n\'importe quoi']));
});

test('Procédures : « condamnée » exige une décision documentée', function () {
    $e = assert_throws(ValidationException::class, fn () => Procedure::validate([
        'procedure_number' => 'P1', 'facts_nature' => 'X', 'investigating_service' => 'S', 'status' => 'condamnee']));
    foreach (['court_reference', 'decision_text', 'decision_date', 'justification_ref'] as $f) {
        assert_true(isset($e->errors[$f]), "champ $f exigé");
    }
    $e = assert_throws(ValidationException::class, fn () => Procedure::validate([
        'procedure_number' => 'P1', 'facts_nature' => 'X', 'investigating_service' => 'S', 'status' => 'poursuivie']));
    assert_true(isset($e->errors['court_reference']));
    $e = assert_throws(ValidationException::class, fn () => Procedure::validate([
        'procedure_number' => 'P1', 'facts_nature' => 'X', 'investigating_service' => 'S', 'status' => 'mise_en_cause', 'decision_final' => 'definitive']));
    assert_true(isset($e->errors['decision_final']), 'définitif réservé aux décisions');
    $ok = Procedure::validate(['procedure_number' => 'P1', 'facts_nature' => 'X', 'investigating_service' => 'S', 'status' => 'condamnee',
        'court_reference' => 'TRIB-1', 'decision_text' => 'Jugement', 'decision_date' => '2026-02-01', 'justification_ref' => 'DOC-1']);
    assert_same('condamnee', $ok['status']);
});

test('Procédures : changement de statut justifié et historisé (plusieurs procédures)', function () {
    $agent = make_user('pr.agent', 'enregistrement');
    act_as($agent);
    $pid = Person::create(person_data(['last_name' => 'Procedure']), $agent);
    $base = ['procedure_number' => 'P-HIST', 'facts_nature' => 'Faits allégués', 'investigating_service' => 'Svc', 'status' => 'mise_en_cause'];
    $id = Procedure::create($pid, Procedure::validate($base), $agent);
    Procedure::create($pid, Procedure::validate(['procedure_number' => 'P-AUTRE', 'status' => 'entendue'] + $base), $agent);
    $e = assert_throws(ValidationException::class, fn () => Procedure::validate(['status' => 'poursuivie', 'court_reference' => 'PQ-1'] + $base, 'mise_en_cause'));
    assert_true(isset($e->errors['status_justification']));
    $data = Procedure::validate(['status' => 'poursuivie', 'court_reference' => 'PQ-1', 'status_justification' => 'Réquisitoire n° 1'] + $base, 'mise_en_cause');
    Procedure::update($id, $data, $agent);
    $h = Procedure::history($id);
    assert_same(2, count($h));
    assert_same('mise_en_cause', $h[1]['old_status']);
    assert_same('poursuivie', $h[1]['new_status']);
    assert_same(2, count(Procedure::forPerson($pid)));
});

test('Doublons : normalisation et clé phonétique', function () {
    assert_same('ouedraogo', Normalizer::name('  OUÉDRAOGO '));
    assert_same(Normalizer::phonetic('Moussa'), Normalizer::phonetic('Musa'));
    assert_same(Normalizer::phonetic('Oumarou'), Normalizer::phonetic('Umaru'));
    assert_same(Normalizer::phonetic('Mohammed'), Normalizer::phonetic('Mohamed'));
    assert_true(Normalizer::phonetic('Diallo') !== Normalizer::phonetic('Traore'));
    assert_same('AB123456', Normalizer::adminId('ab-123 456'));
});

test('Doublons : détection à partir des informations administratives', function () {
    $agent = make_user('d.agent', 'enregistrement');
    act_as($agent);
    $a = Person::create(person_data(['last_name' => 'Doublonné', 'first_name' => 'Aïcha', 'birth_date' => '1975-03-03', 'admin_id_type' => 'CNI', 'admin_id_number' => 'DUP-1']), $agent);
    $cand = DuplicateService::candidatesFor(person_data(['last_name' => 'Doublonne', 'first_name' => 'Aicha', 'birth_date' => '1975-03-03']));
    assert_same($a, (int) $cand[0]['person']['id']);
    assert_contains('Même date de naissance', implode(',', $cand[0]['reasons']));
    $cand = DuplicateService::candidatesFor(person_data(['last_name' => 'Autre', 'first_name' => 'Nom', 'admin_id_number' => 'dup 1', 'birth_date' => null]));
    assert_same('élevé', $cand[0]['level'], 'même identifiant');
    $cand = DuplicateService::candidatesFor(person_data(['last_name' => 'Aicha', 'first_name' => 'Doublonne', 'birth_date' => '1975-03-03']));
    assert_contains('inversés', implode(',', $cand[0]['reasons']));
    assert_same([], DuplicateService::candidatesFor(person_data(['last_name' => 'Doublonne', 'first_name' => 'Aicha', 'birth_date' => '2001-12-12', 'birth_place' => 'Autre'])),
        'date de naissance différente : pas de doublon');
    // Analyse globale puis exclusion après décision
    $b = Person::create(person_data(['last_name' => 'Doublone', 'first_name' => 'Aicha', 'birth_date' => '1975-03-03']), $agent);
    $pairs = DuplicateService::scan('1=1', '1=1', 5000);
    $found = array_filter($pairs, fn ($p) => (int) $p['a']['id'] === $a && (int) $p['b']['id'] === $b);
    assert_true(count($found) === 1, 'paire détectée par l\'analyse');
    Database::insert('duplicate_reviews', ['person_a_id' => $a, 'person_b_id' => $b, 'decision' => 'distinctes', 'comment' => 'Vérifié', 'reviewed_by' => $agent, 'reviewed_at' => date('Y-m-d H:i:s')]);
    $pairs = DuplicateService::scan('1=1', '1=1', 5000);
    assert_same(0, count(array_filter($pairs, fn ($p) => (int) $p['a']['id'] === $a && (int) $p['b']['id'] === $b)), 'paire examinée exclue');
});
