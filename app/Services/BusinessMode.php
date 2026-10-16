<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Addons;
use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Mode de fonctionnement choisi par l'administrateur (Paramètres → Mode de fonctionnement).
 * La caisse principale (/pos), l'écran d'accueil et les menus s'adaptent au mode ; changer de
 * mode ne supprime aucune donnée (les modules restent installés, leurs données conservées).
 */
final class BusinessMode
{
    /** code => [libellé, description, modules complémentaires requis] */
    public const MODES = [
        'commerce' => ['Commerce général', 'Caisse commerciale classique (articles, stock, clients).', []],
        'restaurant' => ['Restaurant', 'Plan de salle, commandes, cuisine ; caisse pour les ventes directes.', []],
        'pharmacie' => ['Pharmacie', 'Caisse de vente de médicaments : lots, péremptions, ordonnances, alertes.', []],
        'clinique' => ['Clinique', 'Caisse des prestations médicales (consultations, soins, analyses) liée aux dossiers patients.', ['clinique']],
        'clinique_pharmacie' => ['Clinique + Pharmacie', 'Prestations médicales et vente de médicaments, opérations séparées et reliées par les ordonnances.', ['clinique']],
        'pressing' => ['Pressing', 'Caisse des dépôts de vêtements, retraits et paiements, sans vente commerciale.', ['pressing']],
    ];

    /**
     * Menus masqués selon le mode (préfixes d'adresse) : on n'affiche pas les activités qui ne
     * concernent pas l'établissement. Option « Afficher aussi les menus des autres activités ».
     */
    private const HIDDEN = [
        'commerce' => ['/clinique', '/pressing'],
        'restaurant' => ['/clinique', '/pressing'],
        'pharmacie' => ['/clinique', '/pressing', '/restaurant', '/kitchen'],
        'clinique' => ['/pressing', '/restaurant', '/kitchen', '/quotes'],
        'clinique_pharmacie' => ['/pressing', '/restaurant', '/kitchen'],
        'pressing' => ['/clinique', '/restaurant', '/kitchen', '/quotes', '/reports/prescriptions'],
    ];

    public static function current(): string
    {
        $m = (string) setting('business_mode', '');
        if (isset(self::MODES[$m])) {
            return $m;
        }
        // Installations antérieures : mode déduit du type d'activité
        return match ((string) setting('business_type', '')) {
            'restaurant' => 'restaurant',
            'pharmacie' => 'pharmacie',
            default => 'commerce',
        };
    }

    public static function is(string ...$modes): bool
    {
        return in_array(self::current(), $modes, true);
    }

    public static function label(?string $mode = null): string
    {
        return self::MODES[$mode ?? self::current()][0];
    }

    /** Modules requis par un mode qui ne sont pas installés et actifs. */
    public static function missingAddons(string $mode): array
    {
        $active = array_keys(Addons::active());
        return array_values(array_diff(self::MODES[$mode][2] ?? [], $active));
    }

    public static function set(string $mode, bool $showAllMenus = false): void
    {
        if (!isset(self::MODES[$mode])) {
            throw new BusinessException('Mode de fonctionnement inconnu.');
        }
        if ($missing = self::missingAddons($mode)) {
            throw new BusinessException('Le mode « ' . self::MODES[$mode][0] . ' » nécessite le module « ' . implode(', ', $missing) . ' » : installez-le ou activez-le d\'abord (Configuration → Modules complémentaires).');
        }
        $before = self::current();
        setting_set('business_mode', $mode);
        setting_set('mode_show_all_menus', $showAllMenus ? '1' : '0');
        // Fonctionnalités indispensables au mode (jamais désactivées ici : aucune perte de réglage)
        $needs = match ($mode) {
            'pharmacie', 'clinique_pharmacie' => ['pharmacy', 'lots'],
            'restaurant' => ['restaurant'],
            default => [],
        };
        foreach ($needs as $f) {
            setting_set('feature_' . $f, '1');
        }
        Audit::log('settings.business_mode', null, null, ['from' => $before, 'to' => $mode]);
    }

    /** Caisse principale du mode courant. */
    public static function posUrl(): string
    {
        return match (self::current()) {
            'clinique' => '/clinique/caisse',
            'pressing' => '/pressing/caisse',
            default => '/pos',
        };
    }

    /** Le menu doit-il masquer cette adresse dans le mode courant ? */
    public static function hides(string $url): bool
    {
        if (setting('mode_show_all_menus', '0') === '1') {
            return false;
        }
        foreach (self::HIDDEN[self::current()] ?? [] as $prefix) {
            if ($url === $prefix || str_starts_with($url, $prefix . '/') || str_starts_with($url, $prefix . '?')) {
                return true;
            }
        }
        return false;
    }

    /** Alertes de pharmacie affichées dans la caisse (péremptions, ruptures). */
    public static function pharmacyAlerts(int $warehouseId): array
    {
        $days = (int) setting('expiry_alert_days', '60');
        return [
            'expired' => (int) DB::value('SELECT COUNT(*) FROM stock_lots WHERE warehouse_id = ? AND quantity > 0 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()', [$warehouseId]),
            'soon' => (int) DB::value('SELECT COUNT(*) FROM stock_lots WHERE warehouse_id = ? AND quantity > 0 AND expiry_date IS NOT NULL AND expiry_date >= CURDATE() AND expiry_date <= CURDATE() + INTERVAL ? DAY', [$warehouseId, $days]),
            'out' => (int) DB::value("SELECT COUNT(*) FROM products p LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.warehouse_id = ? WHERE p.status = 'active' AND p.track_stock = 1 AND p.is_medicine = 1 AND COALESCE(sl.quantity,0) <= 0", [$warehouseId]),
            'days' => $days,
        ];
    }
}
