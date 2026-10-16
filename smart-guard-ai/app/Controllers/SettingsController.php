<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Settings;

final class SettingsController extends Controller
{
    public function index(Request $r): void
    {
        $this->view('settings/index', ['title' => 'Paramètres', 'settings' => Settings::all()]);
    }

    public function update(Request $r): void
    {
        $values = (array) $r->input('s', []);
        $changed = [];
        foreach (Settings::all() as $row) {
            $k = $row['setting_key'];
            if (!array_key_exists($k, $values)) {
                if ($row['value_type'] === 'bool') {
                    $values[$k] = '0';
                } else {
                    continue;
                }
            }
            $v = trim((string) $values[$k]);
            $ok = match ($row['value_type']) {
                'int' => filter_var($v, FILTER_VALIDATE_INT) !== false && (int) $v >= 0,
                'float' => is_numeric($v) && (float) $v >= 0,
                'bool' => in_array($v, ['0', '1'], true),
                'json' => json_decode($v) !== null,
                default => mb_strlen($v) <= 1000,
            };
            if (str_ends_with($k, '_level') && !in_array($v, ['information', 'a_verifier', 'prioritaire'], true)) {
                $ok = false;
            }
            if (!$ok) {
                $this->back('error', "Valeur invalide pour « {$row['description']} ».", 'settings');
            }
            if ($v !== $row['setting_value']) {
                Settings::set($k, $v, Auth::id());
                $changed[$k] = $v;
            }
        }
        Audit::log('parametres_modifies', 'settings', null, $changed);
        $this->back('success', count($changed) . ' paramètre(s) modifié(s).', 'settings');
    }
}
