<?php
declare(strict_types=1);

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Settings;

test('Authentification : connexion réussie, session initialisée et journalisée', function () {
    $id = make_user('auth.ok', 'consultation');
    act_as(null);
    $res = Auth::attempt('AUTH.OK ', TEST_PASSWORD);
    assert_true($res['ok'], 'connexion attendue');
    assert_same($id, $_SESSION['user_id']);
    assert_true(Auth::check());
    assert_same('auth.login', Database::value('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1'));
    assert_not_contains(TEST_PASSWORD, (string) Database::value('SELECT GROUP_CONCAT(details) FROM audit_log'), 'mot de passe dans le journal');
});

test('Authentification : mots de passe hachés (Argon2id ou bcrypt), jamais en clair', function () {
    $id = make_user('auth.hash', 'consultation');
    $hash = (string) Database::value('SELECT password_hash FROM users WHERE id = :id', ['id' => $id]);
    assert_true(str_starts_with($hash, '$argon2id$') || str_starts_with($hash, '$2y$'), 'format de hachage');
    assert_not_contains(TEST_PASSWORD, $hash);
});

test('Authentification : message générique pour compte inconnu ou mauvais mot de passe', function () {
    make_user('auth.generic', 'consultation');
    act_as(null);
    $a = Auth::attempt('auth.generic', 'mauvais');
    $b = Auth::attempt('inexistant.xyz', 'mauvais');
    assert_false($a['ok']);
    assert_same($a['error'], $b['error'], 'les messages doivent être identiques');
});

test('Authentification : verrouillage après N échecs', function () {
    $id = make_user('auth.lock', 'consultation');
    act_as(null);
    $max = Settings::int('login_max_attempts');
    for ($i = 0; $i < $max; $i++) {
        Auth::attempt('auth.lock', 'mauvais-' . $i);
    }
    assert_true(Database::value('SELECT locked_until FROM users WHERE id = :id', ['id' => $id]) !== null, 'compte verrouillé');
    $res = Auth::attempt('auth.lock', TEST_PASSWORD);
    assert_false($res['ok'], 'connexion refusée même avec le bon mot de passe');
    assert_contains('verrouillé', $res['error']);
    assert_true((int) Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.locked' AND entity_id = :id", ['id' => $id]) === 1);
    Database::run('DELETE FROM login_attempts');
});

test('Authentification : compte désactivé refusé', function () {
    make_user('auth.off', 'consultation', ['is_active' => 0]);
    act_as(null);
    $res = Auth::attempt('auth.off', TEST_PASSWORD);
    assert_false($res['ok']);
    assert_contains('désactivé', $res['error']);
});

test('Authentification : désactivation automatique après inactivité prolongée', function () {
    $old = date('Y-m-d H:i:s', strtotime('-200 days'));
    $id = make_user('auth.idle', 'consultation', ['last_login_at' => $old, 'updated_at' => $old, 'created_at' => $old]);
    act_as(null);
    $res = Auth::attempt('auth.idle', TEST_PASSWORD);
    assert_false($res['ok']);
    assert_same(0, (int) Database::value('SELECT is_active FROM users WHERE id = :id', ['id' => $id]));
});

test('Authentification : comptes de démonstration interdits en production', function () {
    make_user('auth.demo', 'consultation', ['is_demo' => 1]);
    act_as(null);
    Config::set('app.env', 'production');
    try {
        $res = Auth::attempt('auth.demo', TEST_PASSWORD);
    } finally {
        Config::set('app.env', 'demo');
    }
    assert_false($res['ok']);
});

test('Sessions : expiration après inactivité et durée maximale', function () {
    $id = make_user('auth.sess', 'consultation');
    act_as($id);
    assert_true(Auth::check());
    $_SESSION['last_activity'] = time() - Settings::int('session_idle_minutes') * 60 - 5;
    assert_false(Auth::check(), 'session expirée attendue');
    act_as($id);
    $_SESSION['login_at'] = time() - Settings::int('session_max_hours') * 3600 - 5;
    assert_false(Auth::check(), 'durée maximale dépassée');
    assert_true((int) Database::value("SELECT COUNT(*) FROM audit_log WHERE action IN ('session.expired','session.max_duration')") >= 2);
});

test('Sessions : un compte désactivé perd immédiatement l\'accès', function () {
    $id = make_user('auth.revoke', 'consultation');
    act_as($id);
    assert_true(Auth::check());
    Database::update('users', ['is_active' => 0], 'id = :id', ['id' => $id]);
    Auth::reset();
    assert_false(Auth::check());
});

test('Mots de passe : politique de complexité', function () {
    assert_true(Auth::passwordPolicyErrors('court1A!') !== []);
    assert_true(Auth::passwordPolicyErrors('toutenminuscules') !== []);
    assert_true(Auth::passwordPolicyErrors('Agent.Demo-2026!', 'agent.demo') !== [], 'contient l\'identifiant');
    assert_same([], Auth::passwordPolicyErrors('Correct-Cheval-42', 'x.y'));
});

test('Permissions : moindre privilège par rôle', function () {
    $roles = ['admin' => make_user('perm.admin', 'admin'), 'enregistrement' => make_user('perm.agent', 'enregistrement'),
              'consultation' => make_user('perm.consult', 'consultation'), 'controleur' => make_user('perm.ctrl', 'controleur')];
    $expect = [
        'admin'          => ['users.manage' => true, 'persons.view' => false, 'audit.view' => false, 'backups.manage' => true],
        'enregistrement' => ['persons.create' => true, 'persons.view_restricted' => false, 'users.manage' => false, 'audit.view' => false],
        'consultation'   => ['persons.view' => true, 'persons.edit' => false, 'persons.create' => false, 'photos.manage' => false],
        'controleur'     => ['audit.view' => true, 'persons.view' => false, 'users.manage' => false],
    ];
    foreach ($expect as $role => $perms) {
        act_as($roles[$role]);
        foreach ($perms as $perm => $allowed) {
            assert_same($allowed, Auth::can($perm), "$role / $perm :");
        }
    }
    act_as($roles['consultation']);
    assert_throws(HttpException::class, fn () => Auth::require('persons.create'));
    assert_same('access.denied', Database::value('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1'));
    $total = (int) Database::value('SELECT COUNT(*) FROM permissions');
    foreach ($roles as $role => $uid) {
        act_as($uid);
        assert_true(count(Auth::permissions()) < $total, "le rôle $role ne doit pas tout avoir");
    }
});
