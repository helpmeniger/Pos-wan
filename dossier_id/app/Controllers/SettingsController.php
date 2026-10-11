<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;

final class SettingsController extends Controller
{
    public function index(): string
    {
        Auth::require('settings.manage');
        $roles = Database::all('SELECT * FROM roles ORDER BY id');
        $perms = Database::all('SELECT * FROM permissions ORDER BY id');
        $matrix = [];
        foreach (Database::all('SELECT role_id, permission_id FROM role_permissions') as $rp) {
            $matrix[$rp['role_id']][$rp['permission_id']] = true;
        }
        return $this->view('settings/index', [
            'title' => 'Paramètres', 'settings' => Settings::all(), 'roles' => $roles, 'perms' => $perms, 'matrix' => $matrix,
            'env' => [
                'php' => PHP_VERSION,
                'db' => (string) Database::value('SELECT VERSION()'),
                'argon2' => defined('PASSWORD_ARGON2ID'),
                'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            ],
        ]);
    }

    public function update(): never
    {
        Auth::require('settings.manage');
        $changed = [];
        $name = mb_substr(trim((string) ($_POST['institution_name'] ?? '')), 0, 150);
        if ($name !== '' && $name !== Settings::get('institution_name')) {
            Settings::set('institution_name', $name, Auth::id());
            $changed[] = 'institution_name';
        }
        foreach (Settings::BOUNDS as $key => [$min, $max]) {
            $v = $_POST[$key] ?? null;
            if (!is_string($v) || !ctype_digit($v) || (int) $v < $min || (int) $v > $max) {
                $this->back('settings.index', [], 'danger', "Valeur invalide pour « $key » (attendu entre $min et $max).");
            }
            if ($v !== Settings::get($key)) {
                Settings::set($key, $v, Auth::id());
                $changed[] = $key;
            }
        }
        Audit::log('settings.update', null, null, ['parametres' => $changed]);
        $this->back('settings.index', [], 'success', 'Paramètres enregistrés.');
    }

    public function permissions(): never
    {
        Auth::require('settings.manage');
        $roles = array_column(Database::all('SELECT id, code FROM roles'), 'code', 'id');
        $perms = array_column(Database::all('SELECT id, code FROM permissions'), 'code', 'id');
        $posted = is_array($_POST['perm'] ?? null) ? $_POST['perm'] : [];
        $new = [];
        foreach ($posted as $rid => $pids) {
            if (!isset($roles[$rid]) || !is_array($pids)) {
                continue;
            }
            foreach (array_keys($pids) as $pid) {
                if (isset($perms[$pid])) {
                    $new[(int) $rid][(int) $pid] = true;
                }
            }
        }
        // Garde-fous : le rôle administrateur conserve la gestion des comptes et des paramètres,
        // et le rôle de contrôle conserve l'accès au journal d'audit.
        $adminId = array_search('admin', $roles, true);
        $ctrlId = array_search('controleur', $roles, true);
        foreach (['users.manage', 'settings.manage'] as $code) {
            $new[$adminId][array_search($code, $perms, true)] = true;
        }
        $new[$ctrlId][array_search('audit.view', $perms, true)] = true;

        $old = [];
        foreach (Database::all('SELECT role_id, permission_id FROM role_permissions') as $rp) {
            $old[(int) $rp['role_id']][(int) $rp['permission_id']] = true;
        }
        $diff = [];
        foreach ($roles as $rid => $rcode) {
            foreach ($perms as $pid => $pcode) {
                $was = isset($old[$rid][$pid]);
                $is = isset($new[$rid][$pid]);
                if ($was !== $is) {
                    $diff[] = ($is ? '+' : '-') . "$rcode:$pcode";
                }
            }
        }
        if (!$diff) {
            $this->back('settings.index', ['tab' => 'permissions'], 'info', 'Aucune modification des permissions.');
        }
        Database::transaction(function () use ($new) {
            Database::run('DELETE FROM role_permissions');
            foreach ($new as $rid => $pids) {
                foreach (array_keys($pids) as $pid) {
                    Database::insert('role_permissions', ['role_id' => $rid, 'permission_id' => $pid]);
                }
            }
        });
        Audit::log('permissions.update', null, null, ['modifications' => $diff]);
        $this->back('settings.index', ['tab' => 'permissions'], 'success', 'Matrice des permissions mise à jour (' . count($diff) . ' modification(s)). Le responsable de contrôle en est informé via le tableau de bord.');
    }
}
