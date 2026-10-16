<?php
declare(strict_types=1);

/**
 * Données de DÉMONSTRATION FICTIVES (produits et ventes marqués is_demo = 1).
 * Aucune observation de caméra n'est inventée : les détections, sorties et alertes ne
 * proviennent que du service IA (caméra réelle, ou vidéo de test analysée en mode test).
 *
 * Option --synthetic=<dossier> : importe les photos du jeu SYNTHÉTIQUE généré par
 * ai-service/scripts/make_synthetic_dataset.py comme photos de 3 produits fictifs, afin de
 * tester la préparation de la reconnaissance et le comptage vidéo sans vrais articles.
 * Option --purge : supprime les données de démonstration.
 */
require __DIR__ . '/cli.php';

use App\Core\Database;
use App\Services\ImageService;
use App\Services\SaleService;
use App\Services\StockService;

$opts = getopt('', ['synthetic::', 'purge', 'store::']);
$storeId = (int) ($opts['store'] ?? Database::value('SELECT id FROM stores ORDER BY id LIMIT 1'));
App\Core\Auth::actAs((int) Database::value("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.code = 'admin' LIMIT 1") ?: null);

if (isset($opts['purge'])) {
    $ids = array_column(Database::fetchAll('SELECT id FROM products WHERE is_demo = 1 AND store_id = :s', ['s' => $storeId]), 'id');
    Database::transaction(static function () use ($ids, $storeId) {
        Database::execute('DELETE si FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.is_demo = 1 AND s.store_id = :s', ['s' => $storeId]);
        Database::execute('DELETE FROM inventory_movements WHERE sale_id IN (SELECT id FROM sales WHERE is_demo = 1 AND store_id = :s)', ['s' => $storeId]);
        Database::execute('DELETE FROM sales WHERE is_demo = 1 AND store_id = :s', ['s' => $storeId]);
        foreach ($ids as $id) {
            Database::execute('DELETE FROM inventory_movements WHERE product_id = :p', ['p' => $id]);
            Database::execute('UPDATE products SET deleted_at = NOW(), is_active = 0, barcode = NULL WHERE id = :p', ['p' => $id]);
        }
    });
    out(count($ids) . ' produit(s) de démonstration archivé(s), ventes de démonstration supprimées.');
    exit(0);
}

$cat = static fn (string $n) => (int) Database::value('SELECT id FROM product_categories WHERE name = :n', ['n' => $n]) ?: null;
$demo = [
    ['DÉMO — Riz parfumé 5 kg (fictif)', 'Riz et céréales', 'MarqueDémo', 'DEMO-0001', 3500, 4250, 40, 'sac'],
    ['DÉMO — Huile alimentaire 1 L (fictif)', 'Huile', 'MarqueDémo', 'DEMO-0002', 950, 1200, 60, 'bouteille'],
    ['DÉMO — Sucre en morceaux 1 kg (fictif)', 'Sucre', 'MarqueDémo', 'DEMO-0003', 650, 800, 50, 'paquet'],
    ['DÉMO — Lait en poudre 400 g (fictif)', 'Lait', 'MarqueDémo', 'DEMO-0004', 2100, 2600, 25, 'boîte'],
    ['DÉMO — Savon de ménage (fictif)', 'Savons', 'MarqueDémo', 'DEMO-0005', 200, 300, 120, 'pièce'],
];
$created = [];
foreach ($demo as [$name, $c, $brand, $barcode, $pa, $pv, $stock, $unit]) {
    $id = Database::value('SELECT id FROM products WHERE store_id = :s AND barcode = :b', ['s' => $storeId, 'b' => $barcode]);
    if (!$id) {
        $id = Database::insert('products', ['uuid' => uuid4(), 'store_id' => $storeId, 'category_id' => $cat($c), 'name' => $name, 'brand' => $brand,
            'barcode' => $barcode, 'purchase_price' => $pa, 'sale_price' => $pv, 'stock_alert_threshold' => 5, 'unit' => $unit, 'is_demo' => 1]);
        StockService::record($storeId, (int) $id, 'reception', $stock, ['comment' => 'Stock initial de démonstration (fictif)']);
    }
    $created[] = (int) $id;
}
out(count($created) . ' produit(s) de démonstration prêts.');

// Ventes fictives sur les 3 derniers jours (marquées « démo »)
mt_srand(7);
$n = 0;
for ($d = 3; $d >= 1; $d--) {
    for ($k = 0; $k < 6; $k++) {
        $items = [];
        foreach (array_rand(array_flip($created), 2) as $pid) {
            $items[] = ['product_id' => $pid, 'quantity' => mt_rand(1, 3)];
        }
        $ts = strtotime("-{$d} days 08:00") + mt_rand(0, 11 * 3600);
        SaleService::create($storeId, $items, ['source' => 'caisse_interne', 'sold_at' => date('Y-m-d H:i:s', $ts), 'is_demo' => 1]);
        $n++;
    }
}
out("{$n} vente(s) de démonstration créée(s).");

if (!empty($opts['synthetic'])) {
    $dir = rtrim((string) $opts['synthetic'], '/\\');
    $syn = [];
    foreach (['A' => 'rouge à rayures jaunes', 'B' => 'bleu à cercles blancs', 'C' => 'vert à damier noir'] as $code => $desc) {
        $barcode = 'SYNTH-' . $code;
        $pid = Database::value('SELECT id FROM products WHERE store_id = :s AND barcode = :b', ['s' => $storeId, 'b' => $barcode]);
        if (!$pid) {
            $pid = Database::insert('products', ['uuid' => uuid4(), 'store_id' => $storeId, 'category_id' => $cat('Autres marchandises'),
                'name' => "SYNTHÉTIQUE — Produit {$code} ({$desc})", 'brand' => 'Test', 'barcode' => $barcode, 'sale_price' => 100, 'unit' => 'pièce', 'is_demo' => 1]);
        }
        $added = 0;
        foreach (glob("{$dir}/products/{$code}/*.jpg") ?: [] as $f) {
            $s = ImageService::storeBinary((string) file_get_contents($f), 'p' . $pid);
            if (Database::value('SELECT 1 FROM product_images WHERE product_id = :p AND sha256 = :h', ['p' => $pid, 'h' => $s['sha256']])) {
                @unlink(STORAGE_PATH . '/product_images/' . $s['path']);
                continue;
            }
            Database::insert('product_images', ['product_id' => $pid, 'file_path' => $s['path'], 'sha256' => $s['sha256'], 'width' => $s['width'], 'height' => $s['height'],
                'source' => 'import', 'annotation' => 'Image synthétique (test logiciel)', 'quality_score' => $s['quality']['score']]);
            $added++;
        }
        Database::execute("UPDATE products SET recognition_status = 'prete' WHERE id = :id AND recognition_status = 'aucune'", ['id' => $pid]);
        $syn[] = "Produit {$code} : {$added} photo(s)";
    }
    out('Produits synthétiques : ' . implode(', ', $syn));
}
out('Terminé. Toutes ces données portent la mention DÉMO/SYNTHÉTIQUE et peuvent être retirées avec --purge.');
