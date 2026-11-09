<?php
declare(strict_types=1);

namespace Addons\Doublons;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Doublons d'articles : détection, comparaison, fusion.
 *
 * Compatible avec toutes les versions de POS Marwa qui ont les modules (1.4.0 et suivantes) :
 * les tables qui utilisent un article (ventes, stock, lots, achats, inventaires, recettes,
 * modules…) ne sont pas codées en dur, elles sont découvertes dans la base elle-même
 * (clés étrangères vers products.id et colonnes product_id / ingredient_id).
 *
 * Fusion = tout l'historique et le stock du doublon passent sur l'article conservé, puis le
 * doublon est supprimé (ou archivé si une référence inconnue l'en empêche). Rien n'est perdu :
 * chaque fusion est inscrite au journal du module, avec une copie complète du doublon.
 */
final class DoublonService
{
    // ------------------------------------------------------------------ outils base

    /** Colonnes de la base existantes (cache), pour s'adapter à chaque version. */
    private static array $cols = [];

    public static function hasTable(string $t): bool
    {
        return (bool) DB::value('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
    }

    public static function columns(string $t): array
    {
        if (!isset(self::$cols[$t])) {
            self::$cols[$t] = array_column(DB::all('SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]), 'c');
        }
        return self::$cols[$t];
    }

    private static function has(string $t, string $c): bool
    {
        return in_array($c, self::columns($t), true);
    }

    private static function q(string $id): string
    {
        return '`' . str_replace('`', '', $id) . '`';
    }

    /**
     * Toutes les colonnes qui désignent un article : [[table, colonne], …] (hors products).
     */
    public static function productRefs(): array
    {
        $refs = [];
        foreach (DB::all("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'products' AND REFERENCED_COLUMN_NAME = 'id'") as $r) {
            $refs[$r['t'] . '.' . $r['c']] = [$r['t'], $r['c']];
        }
        foreach (DB::all("SELECT c.TABLE_NAME t, c.COLUMN_NAME c FROM information_schema.COLUMNS c JOIN information_schema.TABLES tb
                ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME AND tb.TABLE_TYPE = 'BASE TABLE'
                WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME IN ('product_id', 'ingredient_id')") as $r) {
            $refs[$r['t'] . '.' . $r['c']] = [$r['t'], $r['c']];
        }
        unset($refs['products.parent_id']);
        return array_values(array_filter($refs, fn ($x) => $x[0] !== 'products' && $x[0] !== 'doublons_journal'));
    }

    /** Index uniques d'une table : [nom => [colonnes]] */
    private static function uniqueKeys(string $t): array
    {
        $out = [];
        foreach (DB::all('SELECT INDEX_NAME n, COLUMN_NAME c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$t]) as $r) {
            $out[$r['n']][] = $r['c'];
        }
        return $out;
    }

    /** Colonnes (d'autres tables) qui pointent vers $t.id */
    private static function refsTo(string $t): array
    {
        return array_map(fn ($r) => [$r['t'], $r['c']], DB::all('SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = \'id\'', [$t]));
    }

    // ------------------------------------------------------------------ détection

    /** Nom ramené à l'essentiel : minuscules, sans accents ni ponctuation, espaces réduits. */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae', '’' => "'", 'ß' => 'ss']);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /** Clé de comparaison : « Riz 25kg » = « riz 25 KG » = « Riz-25 kg ». */
    public static function key(string $name): string
    {
        return str_replace(' ', '', self::normalize($name));
    }

    /**
     * Groupes de doublons. Identiques : même nom (et même taille / couleur de variante).
     * Ressemblants : une ou deux lettres de différence, mêmes nombres (« 400 g » ≠ « 200 g »).
     * @return array{same: array<int, array>, similar: array<int, array>, scanned: int}
     */
    public static function scan(bool $withArchived = false, bool $similar = true): array
    {
        $variant = self::has('products', 'variant_size') ? ", COALESCE(variant_size, '') vs, COALESCE(variant_color, '') vc" : ", '' vs, '' vc";
        $rows = DB::all('SELECT id, name, status' . $variant . ' FROM products' . ($withArchived ? '' : " WHERE status = 'active'") . ' ORDER BY id');
        $groups = [];
        foreach ($rows as $r) {
            $k = self::key((string) $r['name']) . '|' . self::key((string) $r['vs']) . '|' . self::key((string) $r['vc']);
            $groups[$k][] = (int) $r['id'];
        }
        $same = array_values(array_filter($groups, fn ($g) => count($g) > 1));
        $sim = [];
        if ($similar) {
            // Paquets par début de nom : la comparaison reste rapide même avec des milliers d'articles
            $buckets = [];
            foreach ($groups as $k => $ids) {
                $name = explode('|', $k)[0];
                if (strlen($name) < 6) {
                    continue;
                }
                $buckets[substr($name, 0, 3)][] = [$k, $name, $ids[0]];
            }
            $seen = [];
            foreach ($buckets as $list) {
                $n = count($list);
                if ($n < 2 || $n > 600) {
                    continue;
                }
                for ($i = 0; $i < $n; $i++) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        [$ka, $a, $ia] = $list[$i]; [$kb, $b, $ib] = $list[$j];
                        if (explode('|', $ka)[1] !== explode('|', $kb)[1] || explode('|', $ka)[2] !== explode('|', $kb)[2]) {
                            continue;
                        }
                        preg_match_all('/\d+/', $a, $da); preg_match_all('/\d+/', $b, $db);
                        if ($da[0] !== $db[0]) {
                            continue; // tailles, poids, volumes différents : pas des doublons
                        }
                        $max = max(strlen($a), strlen($b)) >= 12 ? 2 : 1;
                        if (abs(strlen($a) - strlen($b)) <= $max && levenshtein($a, $b) <= $max) {
                            $pair = [$groups[$ka][0], $groups[$kb][0]];
                            sort($pair);
                            $seen[implode('-', $pair)] = $pair;
                        }
                    }
                }
            }
            $sim = array_values($seen);
        }
        return ['same' => $same, 'similar' => $sim, 'scanned' => count($rows)];
    }

    // ------------------------------------------------------------------ comparaison

    /** Fiche comparative d'un article : utilisation, stock, ventes, état. */
    public static function stats(int $id): array
    {
        $p = DB::one('SELECT * FROM products WHERE id = ?', [$id]) ?? throw new BusinessException('Article introuvable.');
        $s = ['id' => $id, 'name' => $p['name'], 'reference' => $p['reference'] ?? '', 'barcode' => $p['barcode'] ?? null, 'image' => $p['image'] ?? null,
            'status' => $p['status'] ?? 'active', 'sale_price' => (float) ($p['sale_price'] ?? 0), 'cost_price' => (float) ($p['cost_price'] ?? 0),
            'category' => null, 'created_at' => $p['created_at'] ?? null, 'sales' => 0, 'sold_qty' => 0.0, 'last_sale' => null, 'stock' => 0.0, 'moves' => 0, 'purchases' => 0, 'refs' => 0];
        if (!empty($p['category_id']) && self::hasTable('categories')) {
            $s['category'] = DB::value('SELECT name FROM categories WHERE id = ?', [$p['category_id']]);
        }
        if (self::hasTable('sale_items')) {
            $r = DB::one('SELECT COUNT(*) n, COALESCE(SUM(i.quantity), 0) q, MAX(s.created_at) last FROM sale_items i LEFT JOIN sales s ON s.id = i.sale_id WHERE i.product_id = ?', [$id]);
            $s['sales'] = (int) $r['n']; $s['sold_qty'] = (float) $r['q']; $s['last_sale'] = $r['last'];
        }
        if (self::hasTable('stock_levels')) {
            $s['stock'] = (float) DB::value('SELECT COALESCE(SUM(quantity), 0) FROM stock_levels WHERE product_id = ?', [$id]);
        }
        if (self::hasTable('stock_movements')) {
            $s['moves'] = (int) DB::value('SELECT COUNT(*) FROM stock_movements WHERE product_id = ?', [$id]);
        }
        foreach (['purchase_order_items', 'reception_items'] as $t) {
            if (self::hasTable($t) && self::has($t, 'product_id')) {
                $s['purchases'] += (int) DB::value('SELECT COUNT(*) FROM ' . self::q($t) . ' WHERE product_id = ?', [$id]);
            }
        }
        foreach (self::productRefs() as [$t, $c]) {
            $s['refs'] += (int) DB::value('SELECT COUNT(*) FROM ' . self::q($t) . ' WHERE ' . self::q($c) . ' = ?', [$id]);
        }
        return $s;
    }

    /**
     * Article à conserver : actif, le plus vendu, le plus de mouvements, le plus de stock,
     * avec code-barres, avec photo, puis le plus ancien. @return array{keep:int, why:string, list:array}
     */
    public static function compare(array $ids): array
    {
        $list = array_map([self::class, 'stats'], array_values(array_unique(array_map('intval', $ids))));
        usort($list, function ($a, $b) {
            return [($b['status'] === 'active') <=> ($a['status'] === 'active'), $b['sales'] <=> $a['sales'], $b['moves'] <=> $a['moves'], $b['stock'] <=> $a['stock'],
                ($b['barcode'] ? 1 : 0) <=> ($a['barcode'] ? 1 : 0), ($b['image'] ? 1 : 0) <=> ($a['image'] ? 1 : 0), $a['id'] <=> $b['id']] <=> [0, 0, 0, 0, 0, 0, 0];
        });
        [$w, $second] = [$list[0], $list[1] ?? null];
        $why = 'le plus ancien';
        if ($second) {
            if ($w['status'] === 'active' && $second['status'] !== 'active') $why = 'seul article actif';
            elseif ($w['sales'] > $second['sales']) $why = 'le plus vendu (' . $w['sales'] . ' ligne(s) de vente)';
            elseif ($w['moves'] > $second['moves']) $why = 'le plus utilisé en stock (' . $w['moves'] . ' mouvement(s))';
            elseif ($w['stock'] > $second['stock']) $why = 'le plus de stock';
            elseif ($w['barcode'] && !$second['barcode']) $why = 'il a un code-barres';
            elseif ($w['image'] && !$second['image']) $why = 'il a une photo';
        }
        return ['keep' => $w['id'], 'why' => $why, 'list' => $list];
    }

    // ------------------------------------------------------------------ fusion

    /**
     * Fusionne $dupIds dans $keepId. Transactionnel : tout ou rien pour chaque doublon.
     * @return array<int, array{id:int, name:string, deleted:bool, moved:int}>
     */
    public static function merge(int $keepId, array $dupIds): array
    {
        $dupIds = array_values(array_unique(array_filter(array_map('intval', $dupIds), fn ($d) => $d > 0 && $d !== $keepId)));
        if (!$dupIds) {
            throw new BusinessException('Aucun doublon à fusionner.');
        }
        $keep = DB::one('SELECT * FROM products WHERE id = ?', [$keepId]) ?? throw new BusinessException('Article à conserver introuvable.');
        $refs = self::productRefs();
        $out = [];
        foreach ($dupIds as $dupId) {
            $out[] = DB::transaction(function () use ($keepId, $dupId, $refs) {
                $keep = DB::one('SELECT * FROM products WHERE id = ? FOR UPDATE', [$keepId]);
                $dup = DB::one('SELECT * FROM products WHERE id = ? FOR UPDATE', [$dupId]);
                if (!$keep || !$dup) {
                    throw new BusinessException('Article introuvable (déjà fusionné ?).');
                }
                // Coût moyen pondéré par les stocks des deux fiches
                $qk = self::hasTable('stock_levels') ? (float) DB::value('SELECT COALESCE(SUM(quantity),0) FROM stock_levels WHERE product_id = ?', [$keepId]) : 0.0;
                $qd = self::hasTable('stock_levels') ? (float) DB::value('SELECT COALESCE(SUM(quantity),0) FROM stock_levels WHERE product_id = ?', [$dupId]) : 0.0;
                $moved = 0; $dropped = [];
                foreach ($refs as [$t, $c]) {
                    [$n, $d] = self::repoint($t, $c, $dupId, $keepId);
                    $moved += $n; $dropped = array_merge($dropped, $d);
                }
                DB::run('UPDATE products SET parent_id = ? WHERE parent_id = ? AND id <> ?', [$keepId, $dupId, $keepId]);
                // Champs vides de l'article conservé complétés par le doublon
                $fill = [];
                foreach (['barcode', 'image', 'description', 'category_id', 'brand_id', 'unit_id', 'supplier_id', 'wholesale_price', 'promo_price'] as $f) {
                    if (self::has('products', $f) && ($keep[$f] === null || $keep[$f] === '') && $dup[$f] !== null && $dup[$f] !== '') {
                        $fill[$f] = $dup[$f];
                    }
                }
                $qk = max(0.0, $qk); $qd = max(0.0, $qd);
                if ($qd > 0 && (float) $dup['cost_price'] > 0 && ($qk <= 0 || (float) $keep['cost_price'] > 0)) {
                    // Coût moyen pondéré : le stock fusionné garde la valeur qu'il avait sur chaque fiche
                    $fill['cost_price'] = round(($qk * (float) $keep['cost_price'] + $qd * (float) $dup['cost_price']) / ($qk + $qd), 2);
                } elseif ((float) $keep['cost_price'] <= 0 && (float) $dup['cost_price'] > 0) {
                    $fill['cost_price'] = (float) $dup['cost_price'];
                }
                if (isset($fill['barcode'])) {
                    DB::run('UPDATE products SET barcode = NULL WHERE id = ?', [$dupId]); // code-barres unique : libéré avant transfert
                }
                if ($fill) {
                    DB::update('products', $fill, 'id = ?', [$keepId]);
                }
                // Suppression du doublon ; archivage si une référence inconnue le retient encore
                $deleted = true;
                try {
                    DB::run('DELETE FROM products WHERE id = ?', [$dupId]);
                } catch (\Throwable $e) {
                    $deleted = false;
                    $arch = ['status' => 'archived', 'name' => mb_substr('[Doublon fusionné] ' . $dup['name'], 0, 200)];
                    if (self::has('products', 'barcode') && !isset($fill['barcode'])) {
                        $arch['barcode'] = null;
                    }
                    DB::update('products', $arch, 'id = ?', [$dupId]);
                }
                $details = ['copie_du_doublon' => $dup, 'lignes_transferees' => $moved, 'lignes_regroupees' => $dropped, 'champs_completes' => array_keys($fill), 'supprime' => $deleted];
                DB::insert('doublons_journal', ['kept_id' => $keepId, 'kept_name' => mb_substr((string) $keep['name'], 0, 200), 'removed_id' => $dupId,
                    'removed_name' => mb_substr((string) $dup['name'], 0, 200), 'removed_ref' => $dup['reference'] ?? null, 'removed_barcode' => $dup['barcode'] ?? null,
                    'moved' => $moved, 'deleted' => $deleted ? 1 : 0, 'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), 'user_id' => Auth::id()]);
                Audit::log('doublons.merge', 'product', $keepId, ['removed' => $dupId, 'name' => $dup['name'], 'moved' => $moved, 'deleted' => $deleted]);
                return ['id' => $dupId, 'name' => (string) $dup['name'], 'deleted' => $deleted, 'moved' => $moved];
            });
        }
        return $out;
    }

    /**
     * Fait pointer $t.$c du doublon vers l'article conservé. Si un index unique l'interdit
     * (ex. stock du même entrepôt, même lot), les quantités sont additionnées sur la ligne
     * existante, les références vers la ligne en trop sont reportées, puis elle est retirée.
     * @return array{0:int, 1:array} [lignes transférées, lignes regroupées (copie)]
     */
    private static function repoint(string $t, string $c, int $from, int $to): array
    {
        $T = self::q($t); $C = self::q($c);
        $uniques = array_filter(self::uniqueKeys($t), fn ($cols) => in_array($c, $cols, true));
        if (!$uniques) {
            return [DB::run("UPDATE $T SET $C = ? WHERE $C = ?", [$to, $from])->rowCount(), []];
        }
        $hasId = self::has($t, 'id');
        $sumCols = array_values(array_intersect(['quantity', 'reserved', 'qty', 'counted_qty', 'expected_qty'], self::columns($t)));
        $moved = 0; $dropped = [];
        foreach (DB::all("SELECT * FROM $T WHERE $C = ?", [$from]) as $row) {
            $twin = null;
            foreach ($uniques as $cols) {
                $where = []; $params = [];
                foreach ($cols as $col) {
                    $v = $col === $c ? $to : $row[$col];
                    if ($v === null) { $where = null; break; } // NULL n'entre pas en conflit
                    $where[] = self::q($col) . ' = ?'; $params[] = $v;
                }
                if ($where && ($twin = DB::one("SELECT * FROM $T WHERE " . implode(' AND ', $where) . ' LIMIT 1', $params))) {
                    break;
                }
            }
            $self = $hasId ? ['id = ?', [$row['id']]] : self::rowWhere($row, reset($uniques));
            if (!$twin) {
                DB::run("UPDATE $T SET $C = ? WHERE " . $self[0], array_merge([$to], $self[1]));
                $moved++;
                continue;
            }
            // Conflit : regroupement sur la ligne existante
            if ($sumCols && $t !== 'inventory_items') {
                $twinWhere = $hasId ? ['id = ?', [$twin['id']]] : self::rowWhere($twin, reset($uniques));
                $set = implode(', ', array_map(fn ($sc) => self::q($sc) . ' = COALESCE(' . self::q($sc) . ', 0) + ?', $sumCols));
                DB::run("UPDATE $T SET $set WHERE " . $twinWhere[0], array_merge(array_map(fn ($sc) => (float) ($row[$sc] ?? 0), $sumCols), $twinWhere[1]));
            }
            if ($hasId) {
                foreach (self::refsTo($t) as [$rt, $rc]) {
                    DB::run('UPDATE ' . self::q($rt) . ' SET ' . self::q($rc) . ' = ? WHERE ' . self::q($rc) . ' = ?', [$twin['id'], $row['id']]);
                }
            }
            DB::run("DELETE FROM $T WHERE " . $self[0], $self[1]);
            $dropped[] = ['table' => $t, 'ligne' => $row];
            $moved++;
        }
        return [$moved, $dropped];
    }

    private static function rowWhere(array $row, array $cols): array
    {
        $w = []; $p = [];
        foreach ($cols as $col) {
            $w[] = self::q($col) . ' = ?'; $p[] = $row[$col];
        }
        return [implode(' AND ', $w), $p];
    }

    /** Sauvegarde de sécurité avant fusion (signature différente selon les versions). */
    public static function backup(): ?string
    {
        if (!class_exists(\App\Services\BackupService::class)) {
            return null;
        }
        $m = new \ReflectionMethod(\App\Services\BackupService::class, 'create');
        $r = $m->getNumberOfParameters() >= 2 ? \App\Services\BackupService::create('avant_fusion_doublons', false) : \App\Services\BackupService::create('avant_fusion_doublons');
        return $r['sql'] ?? null;
    }
}
