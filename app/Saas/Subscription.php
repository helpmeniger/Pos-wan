<?php
declare(strict_types=1);

namespace App\Saas;

use App\Core\BusinessException;
use App\Core\DB;

/**
 * Abonnement d'un espace client : état (essai, actif, expiré, suspendu), offre et limites.
 * Expiré = lecture seule (comme la licence classique : aucune donnée bloquée ni effacée).
 */
final class Subscription
{
    public const METHODS = ['mobile_money' => 'Mobile money', 'virement' => 'Virement bancaire', 'especes' => 'Espèces', 'cheque' => 'Chèque', 'autre' => 'Autre'];
    public const STATES = ['trial' => 'Essai', 'active' => 'Actif', 'expired' => 'Expiré (lecture seule)', 'suspended' => 'Suspendu'];

    public static function badge(string $state): string
    {
        $labels = self::STATES + ['waiting_db' => 'En attente d\'une base', 'provisioning' => 'En création'];
        return '<span class="st st-' . e($state) . '">' . e($labels[$state] ?? $state) . '</span>';
    }

    /** @return array{state:string, until:?string, days_left:int} */
    public static function state(array $t, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        if (!empty($t['suspended_at'])) {
            return ['state' => 'suspended', 'until' => $t['paid_until'] ?: $t['trial_ends'], 'days_left' => 0];
        }
        if (!empty($t['paid_until']) && $t['paid_until'] >= $today) {
            return ['state' => 'active', 'until' => $t['paid_until'], 'days_left' => self::days($today, $t['paid_until'])];
        }
        if (!empty($t['trial_ends']) && $t['trial_ends'] >= $today) {
            return ['state' => 'trial', 'until' => $t['trial_ends'], 'days_left' => self::days($today, $t['trial_ends'])];
        }
        return ['state' => 'expired', 'until' => $t['paid_until'] ?: $t['trial_ends'], 'days_left' => 0];
    }

    private static function days(string $from, string $to): int
    {
        return (int) ((strtotime($to) - strtotime($from)) / 86400) + 1; // le jour d'échéance compte
    }

    private static array $plans = [];

    public static function plan(array $t): array
    {
        return self::$plans[(int) $t['plan_id']] ??= (Central::one('SELECT * FROM saas_plans WHERE id = ?', [$t['plan_id']]) ?? []);
    }

    public static function resetCache(): void
    {
        self::$plans = [];
    }

    /** Statut au format de LicenceService::status() (bandeaux, lecture seule). */
    public static function licenceStatus(): array
    {
        $t = Saas::tenant();
        $s = self::state($t);
        return [
            'state' => match ($s['state']) { 'active' => 'licensed', 'trial' => 'trial', default => 'expired' },
            'saas' => $s['state'], 'client' => $t['company'], 'expires' => $s['until'], 'days_left' => $s['days_left'],
            'install_id' => 'SAAS-' . $t['slug'], 'number' => null, 'error' => null,
        ];
    }

    // ------------------------------------------------------------ limites de l'offre

    public static function usage(): array
    {
        return [
            'users' => (int) DB::value('SELECT COUNT(*) FROM users WHERE active = 1'),
            'stores' => (int) DB::value('SELECT COUNT(*) FROM stores WHERE active = 1'),
            'registers' => (int) DB::value('SELECT COUNT(*) FROM cash_registers WHERE active = 1'),
        ];
    }

    /** Message si l'ajout d'un élément dépasse l'offre, sinon null (hors SaaS : toujours null). */
    public static function limitError(string $kind): ?string
    {
        if (!Saas::isTenant()) {
            return null;
        }
        $plan = self::plan(Saas::tenant());
        $max = (int) ($plan['max_' . $kind] ?? 0);
        $used = self::usage()[$kind] ?? 0;
        if ($used < $max) {
            return null;
        }
        $label = ['users' => 'utilisateurs actifs', 'stores' => 'boutiques', 'registers' => 'caisses'][$kind];
        return 'Votre offre « ' . ($plan['name'] ?? '?') . ' » permet ' . $max . ' ' . $label . ' (' . $used . ' déjà utilisés). Passez à une offre supérieure dans « Mon abonnement ».';
    }

    public static function assertLimit(string $kind): void
    {
        if ($err = self::limitError($kind)) {
            throw new BusinessException($err);
        }
    }

    /** Module complémentaire autorisé pour cet espace (offre + exclusions du service). */
    public static function addonAllowed(string $code, ?array $plan = null): bool
    {
        if (!Saas::isTenant()) {
            return true;
        }
        if (in_array($code, (array) config('saas.excluded_addons'), true)) {
            return false;
        }
        $plan ??= self::plan(Saas::tenant());
        $list = trim((string) ($plan['addons'] ?? ''));
        return $list === '*' || in_array($code, array_map('trim', explode(',', $list)), true);
    }
}
