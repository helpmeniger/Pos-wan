<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Request;
use App\Core\Upload;
use App\Services\Catalog;
use App\Services\InstallService;

final class SettingsController extends Controller
{
    /** Paramètres modifiables : clé => [libellé, type, options] */
    private const FIELDS = [
        'company_name' => ['Nom commercial', 'text'],
        'company_address' => ['Adresse', 'text'],
        'company_phone' => ['Téléphone', 'text'],
        'company_email' => ['E-mail', 'text'],
        'company_nif' => ['NIF', 'text'],
        'company_rccm' => ['RCCM', 'text'],
        'ticket_footer' => ['Pied de ticket', 'textarea'],
        'invoice_footer' => ['Pied de facture (mentions légales)', 'textarea'],
        'ticket_width' => ['Largeur du ticket', 'select', ['80' => '80 mm', '58' => '58 mm']],
        'pos_auto_print' => ['Impression automatique du ticket après vente', 'bool'],
        'restaurant_print_kitchen' => ['Restaurant : impression automatique des bons cuisine', 'bool'],
        'max_discount_percent' => ['Seuil de remise sans autorisation (%)', 'number'],
        'default_tax_rate' => ['Taux de taxe par défaut des nouveaux articles (%)', 'number'],
        'allow_negative_stock' => ['Autoriser la vente en stock négatif (déconseillé)', 'bool'],
        'require_cash_session' => ['Exiger une caisse ouverte pour vendre', 'bool'],
        'cancel_max_hours' => ['Délai maximal d\'annulation d\'une vente (heures)', 'number'],
        'return_max_days' => ['Délai maximal de retour (jours)', 'number'],
        'credit_default_days' => ['Échéance par défaut des crédits clients (jours)', 'number'],
        'expiry_alert_days' => ['Alerte de péremption (jours avant)', 'number'],
        'loyalty_amount_per_point' => ['Fidélité : 1 point par tranche de (F)', 'number'],
        'pin_login_enabled' => ['Connexion rapide par code PIN', 'bool'],
        'rfid_enabled' => ['Connexion par badge RFID (lecteur USB clavier requis)', 'bool'],
        'backup_keep' => ['Nombre de sauvegardes conservées', 'number'],
    ];

    public function index(): void
    {
        $shortcuts = json_decode((string) setting('pos_shortcuts', ''), true) ?: PosController::defaultShortcuts();
        view('settings/index', ['title' => 'Paramètres', 'fields' => self::FIELDS, 'types' => Catalog::BUSINESS_TYPES, 'features' => Catalog::FEATURES, 'shortcuts' => $shortcuts,
            'methods' => ['cash' => 'Espèces', 'card' => 'Carte bancaire', 'mobile_money' => 'Mobile money (Airtel Money, Moov Money, Zamani Cash…)', 'transfer' => 'Virement', 'cheque' => 'Chèque']]);
    }

    public function update(): void
    {
        $changes = [];
        foreach (self::FIELDS as $key => [$label, $type]) {
            $opts = self::FIELDS[$key][2] ?? null;
            if ($type === 'bool') {
                $val = Request::input($key) === '1' ? '1' : '0';
            } else {
                $val = Request::str($key);
                if ($type === 'number') {
                    if ($val === '' || !is_numeric(str_replace(',', '.', $val)) || (float) str_replace(',', '.', $val) < 0) {
                        $this->fail("« $label » doit être un nombre positif.", '/settings');
                    }
                    $val = (string) (float) str_replace(',', '.', $val);
                } elseif ($type === 'select' && !isset($opts[$val])) {
                    continue;
                }
                $val = mb_substr($val, 0, 1000);
            }
            if (setting($key) !== $val) {
                $changes[$key] = $val;
                setting_set($key, $val);
            }
        }
        if ((float) setting('max_discount_percent') > 100) {
            setting_set('max_discount_percent', '100');
        }
        $methods = array_values(array_intersect(['cash', 'card', 'mobile_money', 'transfer', 'cheque'], (array) Request::input('payment_methods', [])));
        setting_set('payment_methods', implode(',', $methods ?: ['cash']));
        // Raccourcis clavier de la caisse (touches F1-F12 ou lettre)
        $sc = [];
        foreach (array_keys(PosController::defaultShortcuts()) as $k) {
            $v = Request::str('sc_' . $k);
            if (preg_match('/^(F([1-9]|1[0-2])|[A-Za-z])$/', $v)) {
                $sc[$k] = $v;
            }
        }
        if ($sc) {
            setting_set('pos_shortcuts', json_encode($sc + PosController::defaultShortcuts()));
        }
        Audit::log('settings.update', null, null, $changes ?: null);
        flash('success', 'Paramètres enregistrés.');
        redirect('/settings');
    }

    public function business(): void
    {
        $type = Request::str('business_type');
        if (!isset(Catalog::BUSINESS_TYPES[$type])) {
            $this->fail('Type d\'activité invalide.', '/settings');
        }
        if (Request::input('apply_defaults') === '1') {
            InstallService::applyBusinessType($type);
        } else {
            setting_set('business_type', $type);
            foreach (array_keys(Catalog::FEATURES) as $f) {
                setting_set('feature_' . $f, in_array($f, (array) Request::input('features', []), true) ? '1' : '0');
            }
        }
        Audit::log('settings.business', null, null, ['type' => $type, 'features' => (array) Request::input('features', [])]);
        flash('success', 'Activité et modules mis à jour.');
        redirect('/settings');
    }

    public function logo(): void
    {
        $path = $this->attempt(fn() => Upload::image($_FILES['logo'] ?? [], 'company'), '/settings');
        Upload::delete(setting('company_logo'));
        setting_set('company_logo', $path);
        Audit::log('settings.logo');
        flash('success', 'Logo mis à jour.');
        redirect('/settings');
    }

    /** Archive les données de démonstration (articles, clients, fournisseurs préfixés [DÉMO]). */
    public function archiveDemo(): void
    {
        DB::transaction(function () {
            DB::run("UPDATE products SET status = 'archived' WHERE description LIKE '[DÉMO]%'");
            DB::run("UPDATE customers SET active = 0 WHERE name LIKE '[DÉMO]%' AND balance = 0");
            DB::run("UPDATE suppliers SET active = 0 WHERE name LIKE '[DÉMO]%' AND balance = 0");
            setting_set('demo_data', '0');
        });
        Audit::log('settings.demo_archived');
        flash('success', 'Données de démonstration archivées. Pensez aussi à ajuster leur stock à zéro si nécessaire.');
        redirect('/settings');
    }

    // ------------------------------------------------------------------ boutiques / entrepôts / caisses
    public function stores(): void
    {
        view('settings/stores', [
            'title' => 'Boutiques, entrepôts et caisses',
            'stores' => DB::all('SELECT * FROM stores ORDER BY id'),
            'warehouses' => DB::all('SELECT w.*, s.name AS store FROM warehouses w LEFT JOIN stores s ON s.id = w.store_id ORDER BY w.id'),
            'registers' => DB::all('SELECT r.*, s.name AS store, w.name AS warehouse, (SELECT COUNT(*) FROM cash_sessions WHERE register_id = r.id AND status = \'open\') AS open FROM cash_registers r JOIN stores s ON s.id = r.store_id JOIN warehouses w ON w.id = r.warehouse_id ORDER BY r.id'),
        ]);
    }

    public function storeStore(): void
    {
        $name = Request::str('name');
        $code = strtoupper(Request::str('code'));
        if ($name === '' || !preg_match('/^[A-Z0-9_-]{1,20}$/', $code) || DB::value('SELECT 1 FROM stores WHERE code = ?', [$code])) {
            $this->fail('Nom ou code de boutique invalide / déjà utilisé.', '/stores');
        }
        $id = DB::insert('stores', ['name' => mb_substr($name, 0, 150), 'code' => $code, 'address' => Request::str('address') ?: null, 'phone' => Request::str('phone') ?: null]);
        Audit::log('store.create', 'store', $id);
        flash('success', 'Boutique créée. Ajoutez-lui un entrepôt et une caisse.');
        redirect('/stores');
    }

    public function warehouseStore(): void
    {
        $name = Request::str('name');
        $code = strtoupper(Request::str('code'));
        if ($name === '' || !preg_match('/^[A-Z0-9_-]{1,20}$/', $code) || DB::value('SELECT 1 FROM warehouses WHERE code = ?', [$code])) {
            $this->fail('Nom ou code d\'entrepôt invalide / déjà utilisé.', '/stores');
        }
        $id = DB::insert('warehouses', ['name' => mb_substr($name, 0, 150), 'code' => $code, 'store_id' => Request::int('store_id') ?: null, 'is_sales_point' => Request::input('is_sales_point') === '1' ? 1 : 0]);
        Audit::log('warehouse.create', 'warehouse', $id);
        flash('success', 'Entrepôt créé.');
        redirect('/stores');
    }

    public function registerStore(): void
    {
        $name = Request::str('name');
        $storeId = Request::int('store_id');
        $whId = Request::int('warehouse_id');
        if ($name === '' || !DB::value('SELECT 1 FROM stores WHERE id = ?', [$storeId]) || !DB::value('SELECT 1 FROM warehouses WHERE id = ?', [$whId])) {
            $this->fail('Caisse invalide.', '/stores');
        }
        $id = DB::insert('cash_registers', ['name' => mb_substr($name, 0, 100), 'store_id' => $storeId, 'warehouse_id' => $whId]);
        Audit::log('register.create', 'cash_register', $id);
        flash('success', 'Caisse créée. Affectez-la à un utilisateur (Utilisateurs).');
        redirect('/stores');
    }

    // ------------------------------------------------------------------ journal d'audit
    public function audit(): void
    {
        [$from, $to] = date_range('-7 days');
        $where = 'a.created_at BETWEEN ? AND ?';
        $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
        if (($act = trim((string) Request::query('action', ''))) !== '') {
            $where .= ' AND a.action LIKE ?';
            $params[] = $act . '%';
        }
        if ($uid = (int) Request::query('user', 0)) {
            $where .= ' AND a.user_id = ?';
            $params[] = $uid;
        }
        $p = paginate("FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $where", $params, 'a.*, u.full_name', 'a.id DESC', 50);
        view('settings/audit', ['title' => 'Journal d\'audit', 'p' => $p, 'from' => $from, 'to' => $to, 'users' => DB::all('SELECT id, full_name FROM users ORDER BY full_name'),
            'failed' => DB::all('SELECT ip, username, COUNT(*) AS n, MAX(created_at) AS last FROM login_attempts WHERE success = 0 AND created_at > NOW() - INTERVAL 7 DAY GROUP BY ip, username ORDER BY n DESC LIMIT 10')]);
    }
}
