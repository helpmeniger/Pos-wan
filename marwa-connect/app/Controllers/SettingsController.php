<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\OnlineOrderService;
use App\Services\SettingsService;

/**
 * Paramètres généraux. Chaque onglet déclare ses champs (clé, libellé, type, groupe).
 */
final class SettingsController extends Controller
{
    public const TABS = [
        'company' => ['Entreprise', 'building', [
            ['company.name', "Nom de l'entreprise", 'string'], ['company.slogan', 'Slogan', 'string'], ['company.phone', 'Téléphone', 'string'],
            ['company.email', 'E-mail', 'string'], ['company.address', 'Adresse', 'string'],
        ]],
        'appearance' => ['Apparence', 'palette', [
            ['appearance.primary_color', 'Couleur principale', 'color'], ['appearance.nav_color', 'Couleur de navigation', 'color'],
            ['appearance.default_theme', 'Thème par défaut', 'select:light=Clair,dark=Sombre,auto=Automatique'],
        ]],
        'regional' => ['Région', 'globe', [
            ['regional.currency', 'Devise', 'string'], ['regional.locale', 'Langue', 'select:fr=Français'], ['regional.timezone', 'Fuseau horaire', 'select:Africa/Niamey=Africa/Niamey (UTC+1)'],
        ]],
        'tickets' => ['Tickets', 'ticket-perforated', [
            ['tickets.default_prefix', 'Préfixe par défaut', 'string'], ['tickets.username_length', 'Longueur des identifiants', 'int'],
            ['tickets.password_length', 'Longueur des mots de passe', 'int'],
            ['tickets.charset', 'Caractères', 'select:alphanumeric_upper=Majuscules + chiffres,alphanumeric=Minuscules + chiffres,numeric=Chiffres,alpha=Lettres'],
            ['tickets.low_stock_threshold', 'Seuil de stock faible', 'int'], ['tickets.wifi_ssid', 'Nom du réseau Wi-Fi par défaut', 'string'],
            ['tickets.login_instructions', 'Instructions de connexion imprimées', 'text'],
        ]],
        'sales' => ['Ventes', 'cash-coin', [
            ['sales.require_open_cash_session', 'Exiger une caisse ouverte pour vendre', 'bool'], ['sales.max_discount_percent', 'Remise maximale (%)', 'int'],
            ['sales.receipt_footer', 'Pied de reçu', 'string'],
        ]],
        'payments' => ['Paiements', 'credit-card', []],
        'mikrotik' => ['MikroTik', 'router', [
            ['mikrotik.sync_interval_minutes', 'Intervalle de synchronisation par défaut (min)', 'int'], ['mikrotik.timeout_seconds', 'Délai de connexion API (s)', 'int'],
            ['mikrotik.offline_after_failures', 'Échecs avant « hors ligne »', 'int'],
        ]],
        'security' => ['Sécurité', 'shield-lock', [
            ['security.session_idle_minutes', "Déconnexion après inactivité (min) — voir aussi .env", 'int'], ['security.audit_retention_days', 'Conservation des journaux de connexion (jours)', 'int'],
        ]],
        'backup' => ['Sauvegardes', 'database-check', [
            ['backup.auto_daily', 'Sauvegarde quotidienne automatique (planificateur)', 'bool'], ['backup.keep_last', 'Nombre de sauvegardes conservées', 'int'],
        ]],
        'ai' => ['Optimus IA', 'stars', [
            ['ai.external_enabled', 'Activer un modèle externe (Claude) pour les questions libres', 'bool'],
            ['ai.api_key', 'Clé API Anthropic', 'secret'], ['ai.model', 'Modèle (vide = claude-opus-5-5)', 'string'],
        ]],
    ];

    public function index(Request $request): Response
    {
        $this->authorize('settings.view');
        $tab = isset(self::TABS[$request->str('tab')]) ? $request->str('tab') : 'company';
        $values = [];
        foreach (Database::fetchAll('SELECT `key`, `value`, `type` FROM settings') as $r) {
            $values[$r['key']] = $r['type'] === 'encrypted' ? ($r['value'] ? '__set__' : '') : $r['value'];
        }
        $methods = $tab === 'payments' ? Database::fetchAll('SELECT * FROM payment_methods ORDER BY sort_order') : [];
        foreach ($methods as &$m) {
            $m['has_secret'] = !empty(OnlineOrderService::methodConfig($m)['webhook_secret']);
        }
        return $this->view('settings/index', ['tab' => $tab, 'values' => $values, 'methods' => $methods,
            'sdk' => class_exists(\Anthropic\Client::class)]);
    }

    public function update(Request $request, string $tab): Response
    {
        $this->authorize('settings.manage');
        if (!isset(self::TABS[$tab])) {
            return $this->redirect('/settings');
        }
        $changed = [];
        foreach (self::TABS[$tab][2] as [$key, $label, $type]) {
            $field = str_replace('.', '__', $key);
            $raw = $request->input($field);
            if ($type === 'bool') {
                SettingsService::set($key, (bool) $raw, 'bool', null, Auth::id());
            } elseif ($type === 'int') {
                SettingsService::set($key, max(0, (int) $raw), 'int', null, Auth::id());
            } elseif ($type === 'secret') {
                if ((string) $raw !== '') {
                    SettingsService::set($key, (string) $raw, 'encrypted', null, Auth::id());
                } elseif ($request->input($field . '__clear')) {
                    SettingsService::set($key, '', 'encrypted', null, Auth::id());
                }
            } elseif ($type === 'color') {
                if (preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $raw)) {
                    SettingsService::set($key, $raw, 'string', null, Auth::id());
                }
            } else {
                SettingsService::set($key, mb_substr(trim((string) $raw), 0, 2000), $type === 'text' ? 'text' : 'string', null, Auth::id());
            }
            $changed[] = $key;
        }
        if ($tab === 'company') {
            $this->handleLogo($request);
        }
        AuditService::log('settings.update', 'settings', null, 'Paramètres « ' . self::TABS[$tab][0] . ' » modifiés', null, ['keys' => $changed]);
        Session::flash('success', 'Paramètres enregistrés.');
        return $this->redirect('/settings?tab=' . $tab);
    }

    private function handleLogo(Request $request): void
    {
        if ($request->input('remove_logo')) {
            SettingsService::set('company.logo', '', 'string');
            return;
        }
        $f = $request->file('logo');
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return;
        }
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 1024 * 1024) {
            Session::flash('warning', 'Logo refusé (1 Mo maximum).');
            return;
        }
        $info = @getimagesize($f['tmp_name']);
        $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
        if (!$ext) {
            Session::flash('warning', 'Logo refusé : formats acceptés PNG, JPG ou WEBP.');
            return;
        }
        $name = 'logo-' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (move_uploaded_file($f['tmp_name'], BASE_PATH . '/public/assets/uploads/' . $name)) {
            SettingsService::set('company.logo', 'uploads/' . $name, 'string');
        }
    }

    public function updatePayment(Request $request, string $id): Response
    {
        $this->authorize('payments.manage', 'settings.manage');
        $m = Database::fetch('SELECT * FROM payment_methods WHERE id = :id', ['id' => (int) $id]);
        $this->notFoundUnless($m);
        $config = OnlineOrderService::methodConfig($m);
        if ($request->str('webhook_secret') !== '') {
            $config['webhook_secret'] = $request->str('webhook_secret');
        }
        Database::update('payment_methods', [
            'name' => mb_substr($request->str('name') ?: $m['name'], 0, 100),
            'is_active' => $request->input('is_active') ? 1 : 0,
            'available_pos' => $request->input('available_pos') ? 1 : 0,
            'available_online' => $request->input('available_online') ? 1 : 0,
            'merchant_number' => $request->str('merchant_number') ?: null,
            'instructions' => mb_substr($request->str('instructions'), 0, 500) ?: null,
            'config_enc' => $config ? Crypto::encrypt((string) json_encode($config)) : null,
        ], 'id = :id', ['id' => $m['id']]);
        AuditService::log('payment_method.update', 'payment_method', (int) $m['id'], "Moyen de paiement {$m['name']} modifié");
        Session::flash('success', 'Moyen de paiement enregistré.');
        return $this->redirect('/settings?tab=payments');
    }
}
