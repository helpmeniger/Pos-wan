<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Validator;

/** Création / modification des fiches articles (contrôles d'unicité, historique des prix). */
final class ProductService
{
    /**
     * Fiche technique : remplace la recette d'un article.
     * @param array<int, float> $lines [ingredient_id => quantité par unité vendue]
     */
    public static function saveRecipe(int $productId, array $lines): void
    {
        DB::transaction(function () use ($productId, $lines) {
            DB::run('DELETE FROM product_recipes WHERE product_id = ?', [$productId]);
            foreach ($lines as $ing => $q) {
                $ing = (int) $ing;
                $q = (float) $q;
                if ($q <= 0) {
                    continue;
                }
                if ($ing === $productId) {
                    throw new BusinessException('Un article ne peut pas être son propre ingrédient.');
                }
                if (DB::value('SELECT 1 FROM product_recipes WHERE product_id = ? LIMIT 1', [$ing])) {
                    throw new BusinessException('Un ingrédient ne peut pas avoir lui-même une fiche technique.');
                }
                if (!DB::value("SELECT 1 FROM products WHERE id = ? AND status = 'active'", [$ing])) {
                    throw new BusinessException('Ingrédient introuvable.');
                }
                DB::insert('product_recipes', ['product_id' => $productId, 'ingredient_id' => $ing, 'quantity' => $q]);
            }
            Audit::log('product.recipe', 'product', $productId, ['lines' => count($lines)]);
        });
    }

    /** Coût théorique d'une fiche technique (au CMP des ingrédients). */
    public static function recipeCost(int $productId): float
    {
        return (float) DB::value('SELECT COALESCE(SUM(r.quantity * p.cost_price),0) FROM product_recipes r JOIN products p ON p.id = r.ingredient_id WHERE r.product_id = ?', [$productId]);
    }

    public const RULES = [
        'name' => 'required|max:200',
        'reference' => 'nullable|max:50|regex:/^[A-Za-z0-9._\-\/]+$/',
        'barcode' => 'nullable|max:64|regex:/^[A-Za-z0-9\-]+$/',
        'category_id' => 'nullable|int',
        'brand_id' => 'nullable|int',
        'unit_id' => 'nullable|int',
        'supplier_id' => 'nullable|int',
        'parent_id' => 'nullable|int',
        'description' => 'nullable|max:2000',
        'variant_size' => 'nullable|max:50',
        'variant_color' => 'nullable|max:50',
        'sale_price' => 'required|numeric|min:0|max:1000000000',
        'cost_price' => 'nullable|numeric|min:0|max:1000000000',
        'wholesale_price' => 'nullable|numeric|min:0|max:1000000000',
        'wholesale_min_qty' => 'nullable|numeric|min:0|max:1000000',
        'promo_price' => 'nullable|numeric|min:0|max:1000000000',
        'promo_start' => 'nullable|date',
        'promo_end' => 'nullable|date',
        'tax_rate' => 'nullable|numeric|min:0|max:100',
        'min_stock' => 'nullable|numeric|min:0|max:100000000',
        'track_stock' => 'bool',
        'sellable' => 'bool',
        'track_lots' => 'bool',
        'sold_by_weight' => 'bool',
        'is_medicine' => 'bool',
        'active_ingredient' => 'nullable|max:200',
        'dosage' => 'nullable|max:100',
        'pharma_form' => 'nullable|max:100',
        'presentation' => 'nullable|max:150',
        'requires_prescription' => 'bool',
        'is_controlled' => 'bool',
    ];

    public const LABELS = [
        'name' => 'Nom', 'reference' => 'Référence', 'barcode' => 'Code-barres', 'sale_price' => 'Prix de vente', 'cost_price' => 'Prix d\'achat',
        'wholesale_price' => 'Prix de gros', 'promo_price' => 'Prix promotionnel', 'min_stock' => 'Seuil d\'alerte', 'tax_rate' => 'Taux de taxe',
    ];

    /** Nettoie et valide la saisie ; retourne les colonnes prêtes à enregistrer. */
    public static function validate(array $input, ?int $id = null): array
    {
        foreach (['track_stock', 'track_lots', 'sold_by_weight', 'is_medicine', 'requires_prescription', 'is_controlled'] as $b) {
            $input[$b] = !empty($input[$b]) ? 1 : 0;
        }
        // « vendable » coché par défaut (absent = formulaire sans la case, ex. import)
        $input['sellable'] = !array_key_exists('sellable', $input) || !empty($input['sellable']) ? 1 : 0;
        $v = Validator::make($input, self::RULES, self::LABELS);
        if ($v->fails()) {
            throw new BusinessException($v->firstError());
        }
        $d = $v->data;
        $d['reference'] = $d['reference'] ?: ($id ? DB::value('SELECT reference FROM products WHERE id = ?', [$id]) : Barcode::newReference());
        if ($d['barcode'] !== null && $d['barcode'] !== '') {
            $dup = DB::one('SELECT id, name FROM products WHERE barcode = ? AND id <> ?', [$d['barcode'], $id ?? 0]);
            $dupPack = DB::one('SELECT pp.id, p.name FROM product_packagings pp JOIN products p ON p.id = pp.product_id WHERE pp.barcode = ?', [$d['barcode']]);
            if ($dup || $dupPack) {
                throw new BusinessException('Ce code-barres est déjà utilisé par « ' . ($dup['name'] ?? $dupPack['name']) . ' ».');
            }
        } else {
            $d['barcode'] = null;
        }
        $dupRef = DB::one('SELECT name FROM products WHERE reference = ? AND id <> ?', [$d['reference'], $id ?? 0]);
        if ($dupRef) {
            throw new BusinessException('Cette référence est déjà utilisée par « ' . $dupRef['name'] . ' ».');
        }
        if ($d['promo_start'] && $d['promo_end'] && $d['promo_start'] > $d['promo_end']) {
            throw new BusinessException('La date de fin de promotion doit suivre la date de début.');
        }
        if ($d['parent_id'] && $id && (int) $d['parent_id'] === $id) {
            throw new BusinessException('Un article ne peut pas être sa propre variante.');
        }
        foreach (['category_id' => 'categories', 'brand_id' => 'brands', 'unit_id' => 'units', 'supplier_id' => 'suppliers', 'parent_id' => 'products'] as $col => $table) {
            if ($d[$col] && !DB::value("SELECT 1 FROM `$table` WHERE id = ?", [$d[$col]])) {
                throw new BusinessException('Valeur de liste invalide (' . $col . ').');
            }
        }
        if (!feature('pharmacy')) {
            $d['requires_prescription'] = 0;
            $d['is_controlled'] = 0;
        }
        $d['tax_rate'] = $d['tax_rate'] ?? 0;
        $d['min_stock'] = $d['min_stock'] ?? 0;
        return $d;
    }

    public static function create(array $d, float $initialQty = 0, ?int $warehouseId = null, ?string $lot = null, ?string $expiry = null): int
    {
        return DB::transaction(function () use ($d, $initialQty, $warehouseId, $lot, $expiry) {
            $cost = (float) ($d['cost_price'] ?? 0);
            $d['cost_price'] = 0; // le CMP est établi par la première entrée en stock
            $d['created_by'] = Auth::id();
            $id = DB::insert('products', $d);
            if ($initialQty > 0) {
                if (!$warehouseId) {
                    throw new BusinessException('Choisissez l\'entrepôt du stock initial.');
                }
                StockService::receive($id, $warehouseId, $initialQty, $cost, 'initial', 'product', $id, 'Stock initial', $lot, $expiry);
            } else {
                DB::run('UPDATE products SET cost_price = ? WHERE id = ?', [$cost, $id]);
            }
            Audit::log('product.create', 'product', $id, ['name' => $d['name'], 'initial_qty' => $initialQty]);
            return $id;
        });
    }

    public static function update(int $id, array $d, bool $canEditPrices, bool $canEditCost): void
    {
        DB::transaction(function () use ($id, $d, $canEditPrices, $canEditCost) {
            $old = DB::one('SELECT * FROM products WHERE id = ? FOR UPDATE', [$id]);
            if (!$old) {
                throw new BusinessException('Article introuvable.');
            }
            $priceFields = ['sale_price', 'wholesale_price', 'promo_price'];
            if (!$canEditPrices) {
                foreach ($priceFields as $f) {
                    $d[$f] = $old[$f];
                }
            }
            if (!$canEditCost || $d['cost_price'] === null) {
                $d['cost_price'] = $old['cost_price'];
            }
            // Passage au suivi par lot : uniquement si aucun stock (sinon les lots seraient incohérents)
            if ((int) $d['track_lots'] !== (int) $old['track_lots'] && StockService::totalQty($id) != 0.0) {
                throw new BusinessException('Le suivi par lot ne peut être modifié que lorsque le stock de l\'article est à zéro.');
            }
            foreach (array_merge($priceFields, ['cost_price']) as $f) {
                $o = $old[$f] === null ? null : (float) $old[$f];
                $n = $d[$f] === null ? null : (float) $d[$f];
                if ($o !== $n) {
                    DB::insert('price_history', ['product_id' => $id, 'field' => $f, 'old_value' => $o, 'new_value' => $n, 'source' => 'manual', 'user_id' => Auth::id()]);
                }
            }
            DB::update('products', $d, 'id = ?', [$id]);
            $changes = [];
            foreach ($d as $k => $v) {
                if ((string) $old[$k] !== (string) $v) {
                    $changes[$k] = [$old[$k], $v];
                }
            }
            Audit::log('product.update', 'product', $id, $changes ?: null);
        });
    }

    /** Modification groupée des prix de vente (catégorie entière). */
    public static function bulkPrice(?int $categoryId, string $mode, float $value, int $rounding): int
    {
        return DB::transaction(function () use ($categoryId, $mode, $value, $rounding) {
            $rows = DB::all("SELECT id, sale_price FROM products WHERE status = 'active'" . ($categoryId ? ' AND category_id = ?' : '') . ' FOR UPDATE', $categoryId ? [$categoryId] : []);
            $n = 0;
            foreach ($rows as $r) {
                $old = (float) $r['sale_price'];
                $new = $mode === 'percent' ? $old * (1 + $value / 100) : $old + $value;
                if ($rounding > 1) {
                    $new = ceil($new / $rounding) * $rounding;
                }
                $new = max(0, round($new, 2));
                if (abs($new - $old) >= 0.01) {
                    DB::run('UPDATE products SET sale_price = ? WHERE id = ?', [$new, $r['id']]);
                    DB::insert('price_history', ['product_id' => $r['id'], 'field' => 'sale_price', 'old_value' => $old, 'new_value' => $new, 'source' => 'bulk', 'user_id' => Auth::id()]);
                    $n++;
                }
            }
            Audit::log('product.bulk_price', null, null, ['category' => $categoryId, 'mode' => $mode, 'value' => $value, 'count' => $n]);
            return $n;
        });
    }
}
