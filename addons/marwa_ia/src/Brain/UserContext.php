<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * MARWA USER CONTEXT & PERMISSION ENGINE.
 * Construit côté serveur, à chaque requête, à partir de l'utilisateur connecté (jamais du texte de la question).
 * Toutes les requêtes de données appliquent ce périmètre : l'assistant n'utilise jamais d'autres droits que ceux de l'utilisateur.
 *
 * Règles :
 *  - ventes : avec « sales.view_all », toutes les ventes du périmètre ; sinon uniquement les ventes de l'utilisateur ;
 *  - périmètre géographique : global pour l'administrateur (« settings.manage ») ou un compte sans boutique ;
 *    sinon limité à la boutique de l'utilisateur (ses magasins = entrepôts de cette boutique) ;
 *  - montants financiers (coûts, marges, bénéfice) : « reports.financial » ; prix d'achat : « products.cost_view » ou « reports.financial ».
 */
final class UserContext
{
    public readonly array $user;
    public readonly array $perms;
    public readonly bool $global;
    public readonly ?int $storeId;
    public readonly ?int $registerId;
    public readonly string $role;
    private static ?self $current = null;

    private function __construct(array $u, ?array $perms = null)
    {
        $this->user = $u;
        $this->perms = $perms ?? Auth::permissions();
        $this->role = (string) (DB::value('SELECT name FROM roles WHERE id = ?', [$u['role_id']]) ?? '');
        $this->storeId = $u['store_id'] !== null ? (int) $u['store_id'] : null;
        $this->registerId = $u['register_id'] !== null ? (int) $u['register_id'] : null;
        $this->global = $this->storeId === null || isset($this->perms['settings.manage']);
    }

    public static function current(): self
    {
        $u = Auth::user();
        if (!$u) {
            throw new BusinessException('Utilisateur non connecté.');
        }
        if (self::$current === null || (int) self::$current->user['id'] !== (int) $u['id']) {
            self::$current = new self($u);
        }
        return self::$current;
    }

    public static function reset(): void
    {
        self::$current = null;
    }

    /**
     * Contexte de droits d'un autre compte, construit sans toucher à la session (banc de tests : rejouer une question
     * « comme un caissier »). Réservé à l'évaluation : ne donne accès à rien de plus que le compte en question.
     */
    public static function forUser(array $u): self
    {
        $code = (string) DB::value('SELECT code FROM roles WHERE id = ?', [$u['role_id']]);
        $perms = $code === 'admin' ? array_fill_keys(array_column(DB::all('SELECT code FROM permissions'), 'code'), true) : Auth::permissionsForRole((int) $u['role_id']);
        return new self($u, $perms);
    }

    public function id(): int
    {
        return (int) $this->user['id'];
    }

    public function firstName(): string
    {
        return explode(' ', trim((string) $this->user['full_name']))[0] ?: (string) $this->user['username'];
    }

    public function can(string $perm): bool
    {
        return isset($this->perms[$perm]);
    }

    /** Toutes les permissions demandées (séparées par « | » = l'une ou l'autre). */
    public function allows(array $required): bool
    {
        foreach ($required as $p) {
            $ok = false;
            foreach (explode('|', $p) as $alt) {
                $ok = $ok || $this->can($alt);
            }
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    public function seesAllSales(): bool
    {
        return $this->can('sales.view_all');
    }

    public function seesCosts(): bool
    {
        return $this->can('products.cost_view') || $this->can('reports.financial');
    }

    /** Boutiques accessibles (null = toutes). */
    public function storeIds(): ?array
    {
        return $this->global ? null : [$this->storeId];
    }

    /** Magasins (entrepôts) accessibles (null = tous). */
    public function warehouseIds(): ?array
    {
        if ($this->global) {
            return null;
        }
        return array_map('intval', array_column(DB::all('SELECT id FROM warehouses WHERE store_id = ?', [$this->storeId]), 'id')) ?: [0];
    }

    /** Condition SQL sur les ventes (alias de la table sales). */
    public function salesWhere(string $a = 's'): array
    {
        $w = '';
        $p = [];
        if (!$this->seesAllSales()) {
            $w .= " AND $a.user_id = ?";
            $p[] = $this->id();
        }
        if (!$this->global) {
            $w .= " AND $a.store_id = ?";
            $p[] = $this->storeId;
        }
        return [$w, $p];
    }

    /** Condition SQL sur un entrepôt (colonne donnée). */
    public function warehouseWhere(string $col): array
    {
        $ids = $this->warehouseIds();
        if ($ids === null) {
            return ['', []];
        }
        return [' AND ' . $col . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    /** Condition SQL sur une boutique (colonne donnée ; les lignes sans boutique restent visibles si $nullable). */
    public function storeWhere(string $col, bool $nullable = false): array
    {
        if ($this->global) {
            return ['', []];
        }
        return [' AND (' . $col . ' = ?' . ($nullable ? ' OR ' . $col . ' IS NULL' : '') . ')', [$this->storeId]];
    }

    /** Résumé lisible du profil, pour « qui suis-je ? » et l'en-tête de l'assistant. */
    public function describe(): array
    {
        $store = $this->storeId ? DB::value('SELECT name FROM stores WHERE id = ?', [$this->storeId]) : null;
        $reg = $this->registerId ? DB::value('SELECT name FROM cash_registers WHERE id = ?', [$this->registerId]) : null;
        return ['name' => $this->user['full_name'], 'username' => $this->user['username'], 'role' => $this->role, 'store' => $store, 'register' => $reg,
            'scope' => $this->global ? 'toutes les boutiques' : 'la boutique ' . $store, 'sales' => $this->seesAllSales() ? 'toutes les ventes du périmètre' : 'vos propres ventes',
            'permissions' => count($this->perms)];
    }
}
