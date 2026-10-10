<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Données de DÉMONSTRATION (clairement identifiées : description « [DÉMO] »,
 * paramètre demo_data = 1 affiché dans l'interface). À ne pas utiliser en production :
 * l'écran Paramètres permet de les archiver.
 */
final class DemoSeeder
{
    public static function run(string $type): void
    {
        if ($type === 'restaurant') {
            self::restaurant();
            return;
        }
        DB::transaction(function () use ($type) {
            setting_set('demo_data', '1');
            $wh = (int) DB::value('SELECT id FROM warehouses ORDER BY id LIMIT 1');
            $unit = fn(string $s) => (int) DB::value('SELECT id FROM units WHERE short_name = ?', [$s]);
            $sup = DB::insert('suppliers', ['name' => '[DÉMO] Grossiste Sahel Distribution', 'phone' => '+227 20 00 00 00', 'address' => 'Grand Marché, Niamey']);
            DB::insert('customers', ['name' => '[DÉMO] Client comptoir Moussa', 'phone' => '+227 90 00 00 01', 'credit_limit' => 50000]);
            DB::insert('customers', ['name' => '[DÉMO] Boutique Amina', 'phone' => '+227 96 00 00 02', 'credit_limit' => 200000]);

            $catalog = match ($type) {
                'pharmacie' => [
                    'Antalgiques' => ['#e74c3c', 'bi-capsule', [
                        ['Paracétamol 500 mg', 'pce', 300, 250, ['ingredient' => 'Paracétamol', 'dosage' => '500 mg', 'form' => 'Comprimé', 'pres' => 'Boîte de 16'], 0],
                        ['Ibuprofène 400 mg', 'pce', 800, 600, ['ingredient' => 'Ibuprofène', 'dosage' => '400 mg', 'form' => 'Comprimé', 'pres' => 'Boîte de 20'], 0],
                    ]],
                    'Anti-infectieux' => ['#8e44ad', 'bi-shield-plus', [
                        ['Amoxicilline 500 mg', 'pce', 1500, 1100, ['ingredient' => 'Amoxicilline', 'dosage' => '500 mg', 'form' => 'Gélule', 'pres' => 'Boîte de 12'], 1],
                        ['Artéméther/Luméfantrine 20/120', 'pce', 2500, 1800, ['ingredient' => 'Artéméther + Luméfantrine', 'dosage' => '20/120 mg', 'form' => 'Comprimé', 'pres' => 'Boîte de 24'], 1],
                    ]],
                    'Hygiène & soins' => ['#16a085', 'bi-droplet', [
                        ['Sels de réhydratation orale', 'pce', 200, 120, ['ingredient' => 'SRO', 'dosage' => '20,5 g', 'form' => 'Poudre', 'pres' => 'Sachet'], 0],
                        ['Vitamine C 500 mg', 'pce', 1000, 700, ['ingredient' => 'Acide ascorbique', 'dosage' => '500 mg', 'form' => 'Comprimé', 'pres' => 'Tube de 10'], 0],
                    ]],
                ],
                'boutique', 'cosmetique', 'informatique', 'pieces' => [
                    'Vêtements' => ['#2980b9', 'bi-person-standing-dress', [
                        ['T-shirt coton', 'pce', 3500, 2000, null, 0],
                        ['Jean homme', 'pce', 9000, 6000, null, 0],
                    ]],
                    'Chaussures' => ['#d35400', 'bi-bag', [
                        ['Sandales cuir', 'pce', 6000, 3500, null, 0],
                        ['Baskets', 'pce', 15000, 10000, null, 0],
                    ]],
                    'Accessoires' => ['#27ae60', 'bi-watch', [
                        ['Ceinture', 'pce', 2500, 1200, null, 0],
                        ['Casquette', 'pce', 2000, 1000, null, 0],
                    ]],
                ],
                default => [
                    'Alimentation' => ['#e67e22', 'bi-basket', [
                        ['Sac de riz 25 kg', 'sac', 13000, 10000, null, 0],
                        ['Huile végétale 5 L', 'pce', 6500, 5200, null, 0],
                        ['Sucre en poudre 1 kg', 'pce', 800, 650, null, 0],
                        ['Spaghetti 500 g', 'pce', 400, 300, null, 0],
                        ['Lait en poudre 400 g', 'pce', 2200, 1750, null, 0],
                        ['Tomate concentrée 70 g', 'pce', 150, 100, null, 0],
                    ]],
                    'Boissons' => ['#2980b9', 'bi-cup-straw', [
                        ['Eau minérale 1,5 L', 'pce', 400, 280, null, 0],
                        ['Jus de bissap 1 L', 'pce', 700, 450, null, 0],
                        ['Soda 33 cl', 'pce', 500, 350, null, 0],
                    ]],
                    'Hygiène' => ['#16a085', 'bi-droplet', [
                        ['Savon de ménage', 'pce', 250, 175, null, 0],
                        ['Dentifrice 75 ml', 'pce', 900, 650, null, 0],
                    ]],
                    'Vrac' => ['#8e44ad', 'bi-speedometer', [
                        ['Niébé (au kg)', 'kg', 900, 650, null, 0],
                        ['Oignons (au kg)', 'kg', 600, 400, null, 0],
                    ]],
                ],
            };
            $lots = feature('lots');
            $i = 0;
            $order = 0;
            foreach ($catalog as $cat => [$color, $icon, $products]) {
                $catId = DB::insert('categories', ['name' => $cat, 'color' => $color, 'icon' => $icon, 'sort_order' => $order++]);
                foreach ($products as [$name, $u, $price, $cost, $pharma, $rx]) {
                    $i++;
                    $isWeight = in_array($u, ['kg'], true);
                    $trackLots = $lots && !$isWeight && in_array($cat, ['Alimentation', 'Boissons', 'Antalgiques', 'Anti-infectieux', 'Hygiène & soins'], true) ? 1 : 0;
                    $pid = DB::insert('products', [
                        'reference' => sprintf('ART%05d', $i), 'barcode' => '200' . str_pad((string) $i, 9, '0', STR_PAD_LEFT) . self::ean13Check('200' . str_pad((string) $i, 9, '0', STR_PAD_LEFT)),
                        'name' => $name, 'category_id' => $catId, 'unit_id' => $unit($u), 'supplier_id' => $sup,
                        'description' => '[DÉMO] Article de démonstration', 'sale_price' => $price,
                        'wholesale_price' => feature('wholesale') ? round($price * 0.92) : null, 'wholesale_min_qty' => feature('wholesale') ? 10 : null,
                        'min_stock' => 10, 'track_lots' => $trackLots, 'sold_by_weight' => $isWeight ? 1 : 0,
                        'is_medicine' => $pharma ? 1 : 0, 'active_ingredient' => $pharma['ingredient'] ?? null, 'dosage' => $pharma['dosage'] ?? null,
                        'pharma_form' => $pharma['form'] ?? null, 'presentation' => $pharma['pres'] ?? null, 'requires_prescription' => $rx,
                    ]);
                    $qty = $isWeight ? 150 : ($i % 5 === 0 ? 6 : 100);
                    if ($trackLots) {
                        StockService::receive($pid, $wh, $qty / 2, (float) $cost, 'initial', 'demo', null, 'Stock initial [DÉMO]', 'L' . date('y') . 'A' . $i, date('Y-m-d', strtotime('+45 days')));
                        StockService::receive($pid, $wh, $qty / 2, (float) $cost, 'initial', 'demo', null, 'Stock initial [DÉMO]', 'L' . date('y') . 'B' . $i, date('Y-m-d', strtotime('+400 days')));
                    } else {
                        StockService::receive($pid, $wh, $qty, (float) $cost, 'initial', 'demo', null, 'Stock initial [DÉMO]');
                    }
                    if (feature('packaging') && $name === 'Eau minérale 1,5 L') {
                        DB::insert('product_packagings', ['product_id' => $pid, 'name' => 'Pack de 6', 'factor' => 6, 'sale_price' => 2200]);
                    }
                    if (feature('packaging') && $name === 'Soda 33 cl') {
                        DB::insert('product_packagings', ['product_id' => $pid, 'name' => 'Carton de 24', 'factor' => 24, 'sale_price' => 11000]);
                    }
                }
            }
        });
    }

    /** Démo restaurant : salles, tables, carte, ingrédients et fiches techniques. */
    private static function restaurant(): void
    {
        DB::transaction(function () {
            setting_set('demo_data', '1');
            setting_set('restaurant_print_kitchen', '1');
            $wh = (int) DB::value('SELECT id FROM warehouses ORDER BY id LIMIT 1');
            $unit = fn(string $s) => (int) DB::value('SELECT id FROM units WHERE short_name = ?', [$s]);
            $sup = DB::insert('suppliers', ['name' => '[DÉMO] Marché de Katako', 'phone' => '+227 20 00 00 10', 'address' => 'Katako, Niamey']);
            DB::insert('customers', ['name' => '[DÉMO] Client fidèle Ibrahim', 'phone' => '+227 90 00 00 03', 'credit_limit' => 30000]);
            $salle = DB::insert('restaurant_areas', ['name' => 'Salle principale', 'sort_order' => 1]);
            $terr = DB::insert('restaurant_areas', ['name' => 'Terrasse', 'sort_order' => 2]);
            for ($i = 1; $i <= 8; $i++) {
                DB::insert('restaurant_tables', ['area_id' => $salle, 'name' => 'T' . $i, 'seats' => $i % 3 === 0 ? 6 : 4, 'shape' => $i % 4 === 0 ? 'round' : 'square', 'sort_order' => $i]);
            }
            for ($i = 9; $i <= 12; $i++) {
                DB::insert('restaurant_tables', ['area_id' => $terr, 'name' => 'T' . $i, 'seats' => 4, 'shape' => 'round', 'sort_order' => $i]);
            }
            // Ingrédients (non vendables), stock initial au coût indiqué
            $ing = [];
            foreach ([['Riz', 'kg', 700, 50], ['Poulet entier', 'pce', 3500, 30], ['Poisson capitaine', 'kg', 4500, 20], ['Viande de bœuf', 'kg', 4000, 25], ['Huile', 'L', 1300, 30],
                      ['Oignon', 'kg', 500, 30], ['Pâte d\'arachide', 'kg', 1500, 10], ['Tomate', 'kg', 600, 20], ['Mil (farine)', 'kg', 450, 30], ['Feuilles de bissap', 'kg', 1200, 10],
                      ['Gingembre', 'kg', 1500, 8], ['Sucre', 'kg', 650, 30], ['Pomme de terre', 'kg', 700, 30]] as $n => [$name, $u, $cost, $q]) {
                $id = DB::insert('products', ['reference' => sprintf('ING%03d', $n + 1), 'name' => $name, 'unit_id' => $unit($u), 'supplier_id' => $sup, 'description' => '[DÉMO] Ingrédient',
                    'sale_price' => 0, 'min_stock' => 5, 'sellable' => 0]);
                StockService::receive($id, $wh, (float) $q, (float) $cost, 'initial', 'demo', null, 'Stock initial [DÉMO]');
                $ing[$name] = $id;
            }
            $cats = [
                'Plats' => ['#e67e22', 'bi-egg-fried', 'cuisine', [
                    ['Riz sauce arachide', 2000, ['Riz' => 0.25, 'Pâte d\'arachide' => 0.08, 'Viande de bœuf' => 0.1, 'Oignon' => 0.05, 'Huile' => 0.03]],
                    ['Poulet braisé + frites', 4500, ['Poulet entier' => 0.5, 'Pomme de terre' => 0.3, 'Huile' => 0.1, 'Oignon' => 0.05]],
                    ['Poisson braisé (capitaine)', 5000, ['Poisson capitaine' => 0.6, 'Oignon' => 0.05, 'Tomate' => 0.1]],
                    ['Riz gras (jollof)', 1500, ['Riz' => 0.25, 'Tomate' => 0.1, 'Huile' => 0.04, 'Oignon' => 0.05]],
                    ['Dambou', 1000, ['Mil (farine)' => 0.2, 'Huile' => 0.02, 'Oignon' => 0.03]],
                ]],
                'Grillades' => ['#c0392b', 'bi-fire', 'grill', [
                    ['Brochettes (tchintchinga) x5', 1500, ['Viande de bœuf' => 0.25, 'Oignon' => 0.03]],
                    ['Kilichi (portion)', 2000, ['Viande de bœuf' => 0.3]],
                ]],
                'Boissons maison' => ['#8e44ad', 'bi-cup-straw', 'bar', [
                    ['Jus de bissap', 500, ['Feuilles de bissap' => 0.02, 'Sucre' => 0.05]],
                    ['Jus de gingembre', 500, ['Gingembre' => 0.03, 'Sucre' => 0.05]],
                    ['Fura da nono', 700, ['Mil (farine)' => 0.08, 'Sucre' => 0.03]],
                ]],
                'Boissons' => ['#2980b9', 'bi-cup', null, [
                    ['Eau minérale 50 cl', 300, 'stock:100:150'],
                    ['Soda 33 cl', 600, 'stock:100:350'],
                ]],
            ];
            $i = 0;
            $order = 0;
            foreach ($cats as $cat => [$color, $icon, $station, $items]) {
                $catId = DB::insert('categories', ['name' => $cat, 'color' => $color, 'icon' => $icon, 'kitchen_station' => $station, 'sort_order' => $order++]);
                foreach ($items as [$name, $price, $recipe]) {
                    $i++;
                    $stocked = is_string($recipe);
                    $pid = DB::insert('products', ['reference' => sprintf('MEN%03d', $i), 'name' => $name, 'category_id' => $catId, 'unit_id' => $unit('pce'), 'description' => '[DÉMO] Carte',
                        'sale_price' => $price, 'track_stock' => $stocked ? 1 : 0, 'min_stock' => $stocked ? 12 : 0]);
                    if ($stocked) {
                        [, $q, $cost] = explode(':', $recipe);
                        StockService::receive($pid, $wh, (float) $q, (float) $cost, 'initial', 'demo', null, 'Stock initial [DÉMO]');
                    } else {
                        foreach ($recipe as $ingName => $q) {
                            DB::insert('product_recipes', ['product_id' => $pid, 'ingredient_id' => $ing[$ingName], 'quantity' => $q]);
                        }
                        DB::run('UPDATE products SET cost_price = ? WHERE id = ?', [ProductService::recipeCost($pid), $pid]);
                    }
                }
            }
        });
    }

    public static function ean13Check(string $twelve): string
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $twelve[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        return (string) ((10 - $sum % 10) % 10);
    }
}
