<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\Csv;
use App\Core\DB;
use App\Core\Xlsx;

/**
 * Entrées de stock rapides et import du stock depuis un fichier Excel (.xlsx) ou CSV.
 * Le stock n'est jamais écrit directement : chaque quantité passe par un mouvement daté,
 * signé (utilisateur connecté), motivé, rattaché à son origine (ajout rapide ou import n°).
 */
final class StockEntry
{
    /** En-têtes acceptés (minuscules, sans accents) → champ. */
    private const ALIASES = [
        'reference' => 'reference', 'ref' => 'reference', 'code' => 'reference', 'code article' => 'reference', 'reference article' => 'reference',
        'code_barres' => 'barcode', 'code barres' => 'barcode', 'code-barres' => 'barcode', 'codebarre' => 'barcode', 'code barre' => 'barcode', 'barcode' => 'barcode', 'ean' => 'barcode',
        'nom' => 'name', 'article' => 'name', 'designation' => 'name', 'libelle' => 'name', 'produit' => 'name',
        'entrepot' => 'warehouse', 'depot' => 'warehouse', 'magasin' => 'warehouse', 'code entrepot' => 'warehouse',
        'quantite' => 'qty', 'qte' => 'qty', 'qty' => 'qty', 'quantite a ajouter' => 'qty', 'ajout' => 'qty', 'stock compte' => 'qty', 'stock reel' => 'qty', 'nouveau stock' => 'qty',
        'prix_achat' => 'cost', 'prix achat' => 'cost', 'cout' => 'cost', 'cout unitaire' => 'cost', 'prix d achat' => 'cost',
        'lot' => 'lot', 'numero de lot' => 'lot', 'n lot' => 'lot',
        'peremption' => 'expiry', 'date de peremption' => 'expiry', 'date_peremption' => 'expiry', 'expiration' => 'expiry',
        'motif' => 'reason', 'commentaire' => 'reason', 'observation' => 'reason',
    ];
    public const MODES = ['add' => 'Ajouter les quantités au stock actuel', 'set' => 'Remplacer par le stock compté (l\'écart est calculé)'];

    // ------------------------------------------------------------------ ajout rapide

    /** Fiche d'un article pour l'ajout rapide : stock par entrepôt, lots, unité. */
    public static function productCard(int $productId): array
    {
        $p = DB::one('SELECT p.id, p.name, p.reference, p.barcode, p.track_stock, p.track_lots, p.cost_price, p.min_stock, u.short_name AS unit, COALESCE(u.allow_decimal,0) AS decimal_ok
            FROM products p LEFT JOIN units u ON u.id = p.unit_id WHERE p.id = ? AND p.status = \'active\'', [$productId]);
        if (!$p) {
            throw new BusinessException('Article introuvable ou archivé.');
        }
        $p['levels'] = DB::all('SELECT w.id, w.name, COALESCE(sl.quantity,0) AS quantity FROM warehouses w LEFT JOIN stock_levels sl ON sl.warehouse_id = w.id AND sl.product_id = ? WHERE w.active = 1 ORDER BY w.name', [$productId]);
        $p['total'] = array_sum(array_map(fn($l) => (float) $l['quantity'], $p['levels']));
        return $p;
    }

    /** @return array{before: float, after: float} */
    public static function quickAdd(int $productId, int $warehouseId, float $qty, ?float $unitCost, ?string $lot, ?string $expiry, string $reason = ''): array
    {
        $qty = round($qty, 3);
        if ($qty <= 0) {
            throw new BusinessException('La quantité à ajouter doit être supérieure à zéro.');
        }
        if ($qty > 1000000) {
            throw new BusinessException('Quantité anormalement élevée : vérifiez la saisie.');
        }
        $p = self::productCard($productId);
        if ((int) $p['track_stock'] !== 1) {
            throw new BusinessException('Cet article n\'est pas suivi en stock (service).');
        }
        if (!(int) $p['decimal_ok'] && abs($qty - round($qty)) > 0.0005) {
            throw new BusinessException('Cet article se compte à l\'unité : quantité entière attendue.');
        }
        if (!DB::value('SELECT 1 FROM warehouses WHERE id = ? AND active = 1', [$warehouseId])) {
            throw new BusinessException('Entrepôt invalide.');
        }
        if ((int) $p['track_lots'] === 1 && trim((string) $lot) === '') {
            throw new BusinessException('Article suivi par lot : indiquez le numéro de lot et la date de péremption.');
        }
        if ($expiry !== null && $expiry !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
            throw new BusinessException('Date de péremption invalide.');
        }
        if ($unitCost !== null && $unitCost < 0) {
            throw new BusinessException('Prix d\'achat invalide.');
        }
        $reason = trim($reason) !== '' ? mb_substr(trim($reason), 0, 200) : 'Ajout rapide';
        return DB::transaction(function () use ($productId, $warehouseId, $qty, $unitCost, $lot, $expiry, $reason, $p) {
            $before = StockService::level($productId, $warehouseId, true);
            // Prix d'achat saisi : coût moyen pondéré recalculé ; sinon le coût actuel est conservé
            StockService::receive($productId, $warehouseId, $qty, $unitCost ?? (float) $p['cost_price'], 'quick_entry', 'quick_entry', null, $reason,
                $lot !== null && trim($lot) !== '' ? trim($lot) : null, $expiry ?: null, $unitCost !== null ? 'average' : 'old');
            $after = StockService::level($productId, $warehouseId);
            Audit::log('stock.quick_entry', 'product', $productId, ['warehouse' => $warehouseId, 'qty' => $qty, 'before' => $before, 'after' => $after, 'cost' => $unitCost, 'lot' => $lot, 'reason' => $reason]);
            return ['before' => $before, 'after' => $after];
        });
    }

    /** Historique des ajouts rapides (qui, quand, quoi, où). */
    public static function quickHistory(array $f = [], int $limit = 200): array
    {
        $where = ["m.type = 'quick_entry'"];
        $p = [];
        if (!empty($f['user'])) {
            $where[] = 'm.user_id = ?';
            $p[] = (int) $f['user'];
        }
        if (!empty($f['from'])) {
            $where[] = 'm.created_at >= ?';
            $p[] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $where[] = 'm.created_at <= ?';
            $p[] = $f['to'] . ' 23:59:59';
        }
        return DB::all('SELECT m.*, p.name AS product, p.reference, w.name AS warehouse, u.full_name AS user_name, l.lot_number
            FROM stock_movements m JOIN products p ON p.id = m.product_id JOIN warehouses w ON w.id = m.warehouse_id
            LEFT JOIN users u ON u.id = m.user_id LEFT JOIN stock_lots l ON l.id = m.lot_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY m.id DESC LIMIT ' . max(1, min(1000, $limit)), $p);
    }

    // ------------------------------------------------------------------ import Excel / CSV

    private static function norm(string $h): string
    {
        $h = mb_strtolower(trim($h));
        $h = strtr($h, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'ç' => 'c', '°' => '', "'" => ' ', '’' => ' ']);
        return trim((string) preg_replace('/\s+/', ' ', str_replace(['(', ')', '.', ':'], ' ', $h)));
    }

    /** Lit le fichier (.xlsx ou .csv) : lignes [n° de ligne du fichier => champs]. */
    public static function readFile(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'xls') {
            throw new BusinessException('Ancien format Excel (.xls) : ouvrez-le dans Excel et choisissez « Enregistrer sous » → « Classeur Excel (.xlsx) » ou CSV.');
        }
        if ($ext === 'xlsx') {
            $grid = Xlsx::read($path);
        } elseif (in_array($ext, ['csv', 'txt'], true)) {
            $grid = [];
            $rows = Csv::read($path);
            if ($rows) {
                $grid[] = array_keys($rows[0]);
                foreach ($rows as $r) {
                    $grid[] = array_values($r);
                }
            }
        } else {
            throw new BusinessException('Format non accepté : fichier Excel (.xlsx) ou CSV.');
        }
        if (count($grid) < 2) {
            throw new BusinessException('Le fichier ne contient aucune ligne de données (la première ligne doit porter les titres des colonnes).');
        }
        // Ligne d'en-tête : la première qui contient une colonne de quantité
        $map = null;
        $start = 0;
        foreach (array_slice($grid, 0, 10, true) as $i => $cells) {
            $m = [];
            foreach ($cells as $c => $h) {
                if (isset(self::ALIASES[self::norm((string) $h)])) {
                    $m[self::ALIASES[self::norm((string) $h)]] ??= $c;
                }
            }
            if (isset($m['qty']) && (isset($m['reference']) || isset($m['barcode']))) {
                $map = $m;
                $start = $i;
                break;
            }
        }
        if ($map === null) {
            throw new BusinessException('Colonnes introuvables : le fichier doit avoir une colonne « reference » ou « code_barres » et une colonne « quantite ». Téléchargez le modèle.');
        }
        $out = [];
        foreach (array_slice($grid, $start + 1, null, true) as $i => $cells) {
            $row = [];
            foreach ($map as $field => $c) {
                $row[$field] = trim((string) ($cells[$c] ?? ''));
            }
            if (implode('', $row) === '') {
                continue;
            }
            $out[$i + 1] = $row; // numéro de ligne tel qu'affiché dans Excel
        }
        if (count($out) > 5000) {
            throw new BusinessException('5 000 lignes au plus par import : découpez le fichier.');
        }
        return $out;
    }

    private static function number(string $v): ?float
    {
        $v = str_replace(["\u{00A0}", "\u{202F}", ' '], '', $v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^-?\d+(,\d+)$/', $v)) {
            $v = str_replace(',', '.', $v);
        }
        return is_numeric($v) ? (float) $v : null;
    }

    private static function date(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
        }
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$#', $v, $m)) {
            $y = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
            return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
        }
        return null;
    }

    /**
     * Analyse sans rien modifier : article trouvé, entrepôt, stock actuel, variation, nouveau stock.
     * @return array<int, array> lignes analysées (status : ok | skip | error)
     */
    public static function analyze(array $rows, string $mode, int $defaultWh): array
    {
        if (!isset(self::MODES[$mode])) {
            throw new BusinessException('Mode d\'import invalide.');
        }
        $whs = DB::all('SELECT id, name, code FROM warehouses WHERE active = 1');
        $findWh = function (string $v) use ($whs, $defaultWh): ?int {
            if ($v === '') {
                return $defaultWh ?: null;
            }
            foreach ($whs as $w) {
                if (mb_strtolower($w['name']) === mb_strtolower($v) || mb_strtolower((string) $w['code']) === mb_strtolower($v) || (string) $w['id'] === $v) {
                    return (int) $w['id'];
                }
            }
            return null;
        };
        $seen = [];
        $out = [];
        foreach ($rows as $line => $r) {
            $id = ($r['reference'] ?? '') !== '' ? $r['reference'] : ($r['barcode'] ?? '');
            $a = ['line' => $line, 'identifier' => $id, 'name' => $r['name'] ?? '', 'product_id' => null, 'warehouse_id' => null, 'warehouse' => '',
                'before' => null, 'change' => null, 'after' => null, 'cost' => null, 'lot' => trim($r['lot'] ?? ''), 'expiry' => null, 'reason' => trim($r['reason'] ?? ''),
                'status' => 'ok', 'message' => ''];
            $q = self::number((string) ($r['qty'] ?? ''));
            if ($q === null && ($r['qty'] ?? '') === '') {
                $out[] = ['status' => 'skip', 'message' => 'Quantité vide : ligne ignorée'] + $a;
                continue;
            }
            $p = null;
            if (($r['reference'] ?? '') !== '') {
                $p = DB::one('SELECT p.*, COALESCE(u.allow_decimal,0) AS decimal_ok FROM products p LEFT JOIN units u ON u.id = p.unit_id WHERE p.reference = ? AND p.status = \'active\'', [$r['reference']]);
            }
            if (!$p && ($r['barcode'] ?? '') !== '') {
                $p = DB::one('SELECT p.*, COALESCE(u.allow_decimal,0) AS decimal_ok FROM products p LEFT JOIN units u ON u.id = p.unit_id WHERE p.barcode = ? AND p.status = \'active\'', [$r['barcode']]);
            }
            $wh = $findWh(trim($r['warehouse'] ?? ''));
            $err = match (true) {
                $id === '' => 'Référence et code-barres vides',
                !$p => 'Article introuvable (« ' . $id . ' »)',
                $q === null => 'Quantité illisible (« ' . ($r['qty'] ?? '') . ' »)',
                $q < 0 => 'Quantité négative refusée (utilisez le mode « stock compté » ou un ajustement)',
                $wh === null => 'Entrepôt inconnu (« ' . ($r['warehouse'] ?? '') . ' »)',
                (int) $p['track_stock'] !== 1 => 'Article non suivi en stock',
                !(int) $p['decimal_ok'] && abs($q - round($q)) > 0.0005 => 'Quantité décimale pour un article compté à l\'unité',
                default => '',
            };
            if ($err === '' && isset($seen[$p['id'] . '-' . $wh])) {
                $err = 'Article déjà présent ligne ' . $seen[$p['id'] . '-' . $wh] . ' pour cet entrepôt';
            }
            if ($err !== '') {
                $out[] = ['status' => 'error', 'message' => $err, 'product_id' => $p['id'] ?? null, 'name' => $p['name'] ?? $a['name']] + $a;
                continue;
            }
            $seen[$p['id'] . '-' . $wh] = $line;
            $before = StockService::level((int) $p['id'], $wh);
            $change = round($mode === 'add' ? $q : $q - $before, 3);
            $cost = self::number((string) ($r['cost'] ?? ''));
            $expiry = self::date((string) ($r['expiry'] ?? ''));
            $a = array_merge($a, ['product_id' => (int) $p['id'], 'name' => $p['name'], 'warehouse_id' => $wh, 'warehouse' => (string) (array_column($whs, 'name', 'id')[$wh] ?? ''),
                'before' => $before, 'change' => $change, 'after' => round($before + $change, 3), 'cost' => $cost !== null && $cost >= 0 ? $cost : null, 'expiry' => $expiry,
                'track_lots' => (int) $p['track_lots']]);
            if (abs($change) < 0.0005) {
                $a['status'] = 'skip';
                $a['message'] = 'Aucun changement';
            } elseif ($change > 0 && (int) $p['track_lots'] === 1 && $a['lot'] === '') {
                $a['status'] = 'error';
                $a['message'] = 'Article suivi par lot : colonne « lot » obligatoire';
            } elseif ($a['after'] < -0.0005) {
                $a['status'] = 'error';
                $a['message'] = 'Le stock deviendrait négatif';
            } elseif (($r['expiry'] ?? '') !== '' && $expiry === null) {
                $a['status'] = 'error';
                $a['message'] = 'Date de péremption illisible (« ' . $r['expiry'] . ' »)';
            }
            $out[] = $a;
        }
        return $out;
    }

    /**
     * Applique l'import : chaque ligne valide devient un mouvement « Import » rattaché à l'import n°.
     * Le stock est relu au moment de l'application (une vente faite entre-temps est prise en compte).
     */
    public static function apply(string $filePath, string $originalName, string $mode, int $defaultWh, string $note = ''): array
    {
        $rows = self::readFile($filePath, $originalName);
        $dir = STORAGE_PATH . '/imports';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new \RuntimeException('Dossier storage/imports inaccessible.');
        }
        $sha = hash_file('sha256', $filePath);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $stored = date('Ymd_His') . '_' . substr($sha, 0, 12) . '.' . $ext;
        if (!@copy($filePath, $dir . '/' . $stored)) {
            throw new \RuntimeException('Copie du fichier importé impossible (storage/imports).');
        }
        return DB::transaction(function () use ($rows, $mode, $defaultWh, $note, $originalName, $stored, $sha) {
            $lines = self::analyze($rows, $mode, $defaultWh);
            $importId = DB::insert('stock_imports', ['file_name' => mb_substr(basename($originalName), 0, 190), 'stored_file' => $stored, 'sha256' => $sha, 'mode' => $mode,
                'warehouse_id' => $defaultWh ?: null, 'rows_total' => count($lines), 'note' => mb_substr(trim($note), 0, 255) ?: null, 'user_id' => (int) Auth::id()]);
            $applied = 0;
            foreach ($lines as $l) {
                $status = $l['status'] === 'ok' ? 'applied' : ($l['status'] === 'skip' ? 'skipped' : 'error');
                $message = $l['message'];
                if ($l['status'] === 'ok') {
                    $reason = 'Import n° ' . $importId . ' (' . basename($originalName) . ', ligne ' . $l['line'] . ')' . ($l['reason'] !== '' ? ' : ' . $l['reason'] : '');
                    try {
                        if ($l['change'] > 0) {
                            StockService::receive($l['product_id'], $l['warehouse_id'], $l['change'], $l['cost'] ?? (float) DB::value('SELECT cost_price FROM products WHERE id = ?', [$l['product_id']]),
                                'import', 'stock_import', $importId, $reason, $l['lot'] !== '' ? $l['lot'] : null, $l['expiry'], $l['cost'] !== null ? 'average' : 'old');
                        } else {
                            StockService::issue($l['product_id'], $l['warehouse_id'], -$l['change'], 'import', 'stock_import', $importId, $reason);
                        }
                        $applied++;
                        $l['after'] = StockService::level($l['product_id'], $l['warehouse_id']);
                    } catch (BusinessException $e) {
                        $status = 'error';
                        $message = $e->getMessage();
                    }
                }
                DB::insert('stock_import_rows', ['import_id' => $importId, 'line_no' => $l['line'], 'identifier' => mb_substr((string) $l['identifier'], 0, 120) ?: null,
                    'product_id' => $l['product_id'], 'warehouse_id' => $l['warehouse_id'], 'qty_before' => $l['before'], 'qty_change' => $l['change'],
                    'qty_after' => $status === 'applied' ? $l['after'] : $l['before'], 'status' => $status, 'message' => mb_substr($message, 0, 255) ?: null]);
            }
            DB::update('stock_imports', ['rows_applied' => $applied, 'rows_skipped' => count($lines) - $applied], 'id = ?', [$importId]);
            Audit::log('stock.import', 'stock_import', $importId, ['file' => basename($originalName), 'sha256' => $sha, 'mode' => $mode, 'applied' => $applied, 'total' => count($lines)]);
            return ['id' => $importId, 'applied' => $applied, 'total' => count($lines)];
        });
    }

    /** Modèle Excel pré-rempli : un article par ligne, stock actuel de l'entrepôt choisi. */
    public static function template(int $warehouseId): string
    {
        $wh = DB::one('SELECT * FROM warehouses WHERE id = ?', [$warehouseId]) ?? DB::one('SELECT * FROM warehouses WHERE active = 1 ORDER BY id LIMIT 1');
        $rows = [];
        foreach (DB::all('SELECT p.reference, p.barcode, p.name, p.cost_price, COALESCE(sl.quantity,0) q FROM products p LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.warehouse_id = ? WHERE p.status = \'active\' AND p.track_stock = 1 ORDER BY p.name', [(int) $wh['id']]) as $p) {
            $rows[] = [(string) $p['reference'], (string) $p['barcode'], (string) $p['name'], (string) $wh['name'], (float) $p['q'], '', '', '', '', ''];
        }
        return Xlsx::build('Stock', ['reference', 'code_barres', 'nom', 'entrepot', 'stock_actuel', 'quantite', 'prix_achat', 'lot', 'peremption', 'motif'], $rows);
    }
}
