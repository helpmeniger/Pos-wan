<?php
declare(strict_types=1);

namespace Addons\Vision;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Reconnaissance des articles par photo — partie serveur.
 *
 * Le calcul se fait dans le navigateur (réseau de neurones MobileNet v2, local, sans Internet) :
 * chaque photo devient une « empreinte visuelle » de 512 nombres (octets signés). Le serveur
 * conserve ces empreintes par article et les renvoie aux caisses ; il ne reçoit jamais les
 * photos elles-mêmes, seulement une vignette de contrôle (112 px) affichée dans l'apprentissage.
 */
final class VisionService
{
    public const DIM = 512;
    public const MODEL = 'mnv2-p512';
    /** Empreinte de l'article détouré (fond remplacé par du gris neutre) — version 1.2. */
    public const MODEL_OBJ = 'mnv2-p512-obj';
    public const MODELS = [self::MODEL => 'Image entière', self::MODEL_OBJ => 'Objet isolé'];
    public const SOURCES = ['photo' => 'Photo apprise', 'fiche' => 'Photo de la fiche article', 'vente' => 'Appris pendant une vente', 'surveillance' => 'Appris en surveillance'];
    private const MAX_PER_PRODUCT = 80; // chaque photo donne 2 empreintes (objet isolé + image entière)

    public static function version(): int
    {
        return max(1, (int) setting('vision_version', '1'));
    }

    private static function bump(): void
    {
        setting_set('vision_version', (string) (self::version() + 1));
    }

    /** Empreinte reçue en base64 : exactement 512 octets, pas toute nulle. */
    public static function decodeVec(string $b64): string
    {
        $bin = base64_decode($b64, true);
        if ($bin === false || strlen($bin) !== self::DIM || trim($bin, "\0") === '') {
            throw new BusinessException('Empreinte visuelle invalide.');
        }
        return $bin;
    }

    /** Vignette JPEG de contrôle (data URL), facultative et limitée en taille. */
    public static function cleanThumb(?string $thumb): ?string
    {
        if ($thumb === null || $thumb === '') {
            return null;
        }
        if (strlen($thumb) > 60000 || !preg_match('#^data:image/jpeg;base64,[A-Za-z0-9+/=]+$#', $thumb)) {
            throw new BusinessException('Vignette invalide.');
        }
        return $thumb;
    }

    /**
     * Ajoute une empreinte à un article.
     * Source « vente » : photo confirmée par le caissier ; on garde les N plus récentes par article.
     */
    public static function addRef(int $productId, string $vecB64, ?string $thumb, string $source, ?string $model = null, mixed $size = null): array
    {
        $model = $model ?: self::MODEL;
        // taille apparente de l'article (0..1), connue seulement avec un fond appris (caméra fixe)
        $size = $model === self::MODEL_OBJ && is_numeric($size) && (float) $size > 0.02 && (float) $size <= 1 ? round((float) $size, 3) : null;
        if (!isset(self::MODELS[$model])) {
            throw new BusinessException('Type d\'empreinte inconnu.');
        }
        if (!isset(self::SOURCES[$source])) {
            throw new BusinessException('Origine de la photo inconnue.');
        }
        if ($source === 'surveillance' && !can('vision.watch') && !can('vision.teach')) {
            throw new BusinessException('Vous n\'avez pas le droit d\'apprendre des photos d\'articles.');
        }
        if (!in_array($source, ['vente', 'surveillance'], true) && !can('vision.teach')) {
            throw new BusinessException('Vous n\'avez pas le droit d\'apprendre des photos d\'articles.');
        }
        if ($source === 'vente' && setting('vision_apprendre_ventes', '1') !== '1') {
            return ['id' => null, 'version' => self::version(), 'skipped' => true];
        }
        $vec = self::decodeVec($vecB64);
        $thumb = self::cleanThumb($thumb);
        return DB::transaction(function () use ($productId, $vec, $thumb, $source, $model, $size) {
            $p = DB::one("SELECT id, name FROM products WHERE id = ? AND status = 'active' FOR UPDATE", [$productId]) ?? throw new BusinessException('Article introuvable ou archivé.');
            if ($source === 'vente') {
                $keep = max(1, min(50, (int) setting('vision_max_ventes', '12')));
                $old = DB::all("SELECT id FROM vision_refs WHERE product_id = ? AND source = 'vente' ORDER BY id DESC LIMIT 1000 OFFSET " . ($keep - 1), [$productId]);
                if ($old) {
                    DB::run('DELETE FROM vision_refs WHERE id IN (' . implode(',', array_map('intval', array_column($old, 'id'))) . ')');
                }
            } elseif ((int) DB::value('SELECT COUNT(*) FROM vision_refs WHERE product_id = ?', [$productId]) >= self::MAX_PER_PRODUCT) {
                throw new BusinessException('« ' . $p['name'] . ' » a déjà ' . self::MAX_PER_PRODUCT . ' photos : supprimez les moins bonnes avant d\'en ajouter.');
            }
            $id = DB::insert('vision_refs', ['product_id' => $productId, 'vec' => $vec, 'model' => $model, 'size' => $size, 'source' => $source, 'thumb' => $thumb, 'user_id' => Auth::id()]);
            self::bump();
            if ($source !== 'vente') {
                Audit::log('vision.learn', 'product', $productId, ['source' => $source]);
            }
            return ['id' => (int) $id, 'version' => self::version()];
        });
    }

    public static function deleteRef(int $id): int
    {
        $pid = (int) DB::value('SELECT product_id FROM vision_refs WHERE id = ?', [$id]);
        if (!$pid) {
            throw new BusinessException('Photo introuvable.');
        }
        DB::run('DELETE FROM vision_refs WHERE id = ?', [$id]);
        self::bump();
        Audit::log('vision.forget', 'product', $pid, ['ref' => $id]);
        return $pid;
    }

    public static function clearProduct(int $productId): int
    {
        $n = DB::run('DELETE FROM vision_refs WHERE product_id = ?', [$productId])->rowCount();
        if ($n) {
            self::bump();
            Audit::log('vision.forget', 'product', $productId, ['all' => $n]);
        }
        return $n;
    }

    /**
     * Index envoyé aux appareils : toutes les empreintes des articles actifs, concaténées.
     * 512 octets par photo : 5 000 photos ≈ 2,5 Mo, téléchargés une fois puis gardés en cache.
     */
    public static function index(): array
    {
        $rows = DB::all("SELECT r.id, r.product_id, r.vec, r.model, r.size FROM vision_refs r JOIN products p ON p.id = r.product_id
                         WHERE p.status = 'active' AND r.model IN (?, ?) ORDER BY r.product_id, r.id", [self::MODEL, self::MODEL_OBJ]);
        return [
            'version' => self::version(), 'dim' => self::DIM, 'model' => self::MODEL,
            'ids' => array_map('intval', array_column($rows, 'id')),
            'pids' => array_map('intval', array_column($rows, 'product_id')),
            'modes' => array_map(fn($m) => $m === self::MODEL_OBJ ? 1 : 0, array_column($rows, 'model')),
            'sizes' => array_map(fn($s) => $s === null ? 0 : (float) $s, array_column($rows, 'size')),
            'vecs' => base64_encode(implode('', array_column($rows, 'vec'))),
        ];
    }

    /** Fiches des articles proposés (nom, prix, code à transmettre, image, stock). */
    public static function products(array $ids, int $warehouseId): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 50);
        if (!$ids) {
            return [];
        }
        $rows = DB::all('SELECT p.id, p.name, p.reference, p.barcode, p.sale_price, p.image, p.track_stock, ' . \App\Controllers\PosController::stockSql($warehouseId) . ' AS stock,
                (SELECT r.thumb FROM vision_refs r WHERE r.product_id = p.id AND r.thumb IS NOT NULL ORDER BY r.source = \'vente\', r.id LIMIT 1) AS thumb
                FROM products p LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.warehouse_id = ' . $warehouseId . "
                WHERE p.status = 'active' AND p.id IN (" . implode(',', $ids) . ')');
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'], 'name' => $r['name'], 'reference' => $r['reference'], 'barcode' => $r['barcode'],
                'code' => ($r['barcode'] ?? '') !== '' ? $r['barcode'] : $r['reference'],
                'price' => (float) $r['sale_price'], 'track' => (int) $r['track_stock'] === 1, 'stock' => (float) $r['stock'],
                'image' => $r['image'] ? upload_url($r['image']) : $r['thumb'],
            ];
        }
        return $out;
    }

    public static function log(array $in): void
    {
        $outcome = (string) ($in['outcome'] ?? '');
        if (!in_array($outcome, ['confirme', 'corrige', 'auto', 'aucun', 'annule'], true)) {
            throw new BusinessException('Résultat inconnu.');
        }
        $num = fn($k) => isset($in[$k]) && is_numeric($in[$k]) ? max(-1, min(1, round((float) $in[$k], 3))) : null;
        $id = fn($k) => isset($in[$k]) && (int) $in[$k] > 0 ? (int) $in[$k] : null;
        DB::insert('vision_log', ['suggested_id' => $id('suggested_id'), 'chosen_id' => $id('chosen_id'), 'score' => $num('score'), 'margin' => $num('margin'),
            'outcome' => $outcome, 'context' => mb_substr(preg_replace('/[^a-z_]/', '', (string) ($in['context'] ?? '')), 0, 30) ?: null, 'user_id' => Auth::id()]);
    }

    /** Statistiques des 30 derniers jours : taux de bonnes propositions. */
    public static function stats(): array
    {
        $learned = (int) DB::value("SELECT COUNT(DISTINCT r.product_id) FROM vision_refs r JOIN products p ON p.id = r.product_id WHERE p.status = 'active'");
        $total = (int) DB::value("SELECT COUNT(*) FROM products WHERE status = 'active' AND sellable = 1");
        $l = DB::one("SELECT COUNT(*) AS n, SUM(outcome IN ('confirme','auto')) - SUM(outcome = 'annule') AS ok, SUM(outcome IN ('corrige','annule')) AS fixed, SUM(outcome = 'aucun') AS none
                      FROM vision_log WHERE created_at >= NOW() - INTERVAL 30 DAY") ?? [];
        $n = (int) ($l['n'] ?? 0);
        return [
            'learned' => $learned, 'total' => $total, 'refs' => (int) DB::value('SELECT COUNT(*) FROM vision_refs'),
            'by_source' => array_column(DB::all('SELECT source, COUNT(*) AS n FROM vision_refs GROUP BY source'), 'n', 'source'),
            'uses' => $n, 'ok' => (int) ($l['ok'] ?? 0), 'fixed' => (int) ($l['fixed'] ?? 0), 'none' => (int) ($l['none'] ?? 0),
            'rate' => $n ? round(100 * (int) ($l['ok'] ?? 0) / $n) : null,
            'confused' => DB::all("SELECT s.name AS suggested, c.name AS chosen, COUNT(*) AS n FROM vision_log l JOIN products s ON s.id = l.suggested_id JOIN products c ON c.id = l.chosen_id
                                   WHERE l.outcome = 'corrige' AND l.created_at >= NOW() - INTERVAL 30 DAY GROUP BY l.suggested_id, l.chosen_id ORDER BY n DESC LIMIT 5"),
        ];
    }

    /**
     * Articles déjà confondus (corrections et annulations des 90 derniers jours, au moins 2 fois) :
     * entre eux, la reconnaissance exige un écart double avant d'ajouter seule.
     */
    public static function confusions(): array
    {
        $pairs = DB::all("SELECT LEAST(suggested_id, chosen_id) AS a, GREATEST(suggested_id, chosen_id) AS b FROM vision_log
                          WHERE outcome = 'corrige' AND suggested_id IS NOT NULL AND chosen_id IS NOT NULL AND suggested_id <> chosen_id AND created_at >= NOW() - INTERVAL 90 DAY
                          GROUP BY a, b HAVING COUNT(*) >= 2 ORDER BY COUNT(*) DESC LIMIT 200");
        $risky = DB::all("SELECT suggested_id FROM vision_log WHERE outcome = 'annule' AND suggested_id IS NOT NULL AND created_at >= NOW() - INTERVAL 90 DAY
                          GROUP BY suggested_id HAVING COUNT(*) >= 2 LIMIT 200");
        return ['pairs' => array_map(fn($r) => [(int) $r['a'], (int) $r['b']], $pairs), 'risky' => array_map('intval', array_column($risky, 'suggested_id'))];
    }

    public static function saveSettings(array $in): void
    {
        $f = fn($k, $min, $max, $def) => (string) max($min, min($max, round((float) str_replace(',', '.', (string) ($in[$k] ?? $def)), 2)));
        setting_set('vision_auto', !empty($in['vision_auto']) ? '1' : '0');
        setting_set('vision_apprendre_ventes', !empty($in['vision_apprendre_ventes']) ? '1' : '0');
        setting_set('vision_seuil_auto', $f('vision_seuil_auto', 0.3, 0.99, '0.80'));
        setting_set('vision_marge', $f('vision_marge', 0.0, 0.5, '0.06'));
        setting_set('vision_seuil_objet', $f('vision_seuil_objet', 0.2, 0.99, '0.45'));
        setting_set('vision_marge_objet', $f('vision_marge_objet', 0.02, 0.6, '0.25'));
        setting_set('vision_max_ventes', (string) max(1, min(50, (int) ($in['vision_max_ventes'] ?? 12))));
        Audit::log('settings.vision', null, null, array_intersect_key($in, array_flip(['vision_auto', 'vision_seuil_auto', 'vision_marge', 'vision_seuil_objet', 'vision_marge_objet', 'vision_apprendre_ventes', 'vision_max_ventes'])));
    }
}
