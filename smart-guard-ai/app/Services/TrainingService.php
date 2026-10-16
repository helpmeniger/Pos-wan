<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;

/**
 * Préparation de la reconnaissance (galerie d'empreintes) et gestion des versions.
 * Aucune version n'est marquée « prête » sans résultat réel retourné par le service IA.
 */
final class TrainingService
{
    public static function modelFor(int $storeId): array
    {
        $m = Database::fetch('SELECT * FROM ai_models WHERE store_id = :s ORDER BY id LIMIT 1', ['s' => $storeId]);
        if (!$m) {
            $id = Database::insert('ai_models', ['store_id' => $storeId, 'name' => 'Reconnaissance des produits', 'method' => 'embedding_similarity',
                'description' => 'Similarité visuelle (empreintes) sur photos de référence']);
            $m = Database::fetch('SELECT * FROM ai_models WHERE id = :id', ['id' => $id]);
        }
        return $m;
    }

    public static function start(int $storeId, ?string $notes = null): array
    {
        $model = self::modelFor($storeId);
        $products = Database::fetchAll('SELECT id, name, min_confidence FROM products WHERE store_id = :s AND is_active = 1 AND deleted_at IS NULL ORDER BY id', ['s' => $storeId]);
        $payloadProducts = [];
        $imageCount = 0;
        foreach ($products as $p) {
            $imgs = Database::fetchAll("SELECT id, file_path, usage_split, bbox_json FROM product_images WHERE product_id = :p AND usage_split <> 'exclue'", ['p' => $p['id']]);
            if (!$imgs) {
                continue;
            }
            $imageCount += count($imgs);
            $payloadProducts[] = ['product_id' => (int) $p['id'], 'name' => $p['name'], 'min_confidence' => (float) $p['min_confidence'],
                'images' => array_map(static fn ($i) => ['id' => (int) $i['id'], 'path' => 'product_images/' . $i['file_path'],
                    'split' => $i['usage_split'], 'bbox' => $i['bbox_json'] ? json_decode($i['bbox_json'], true) : null], $imgs)];
        }
        if (!$payloadProducts) {
            return ['ok' => false, 'error' => 'Aucun produit actif ne possède de photos.'];
        }
        $version = (int) Database::value('SELECT COALESCE(MAX(version_number),0)+1 FROM ai_model_versions WHERE ai_model_id = :m', ['m' => $model['id']]);
        $versionId = Database::insert('ai_model_versions', ['ai_model_id' => $model['id'], 'version_number' => $version, 'image_count' => $imageCount,
            'product_count' => count($payloadProducts), 'products_json' => json_encode(array_column($payloadProducts, 'product_id')),
            'status' => 'en_preparation', 'notes' => $notes, 'created_by' => Auth::id()]);
        $jobId = Database::insert('ai_training_jobs', ['uuid' => uuid4(), 'ai_model_id' => $model['id'], 'model_version_id' => $versionId,
            'status' => 'en_attente', 'requested_by' => Auth::id(), 'message' => 'Envoi au service IA']);

        $r = AiClient::request('POST', '/training/jobs', [
            'model_id' => (int) $model['id'], 'version' => $version,
            'min_images' => (int) Settings::get('ai.min_images_per_product', 8),
            'min_accuracy' => (float) Settings::get('ai.min_validation_accuracy', 0.8),
            'unknown_margin' => (float) Settings::get('ai.unknown_margin', 0.04),
            'products' => $payloadProducts,
        ], ['timeout' => 30]);
        if (!$r['ok']) {
            Database::update('ai_training_jobs', ['status' => 'echec', 'message' => mb_substr((string) $r['error'], 0, 500), 'finished_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $jobId]);
            Database::update('ai_model_versions', ['status' => 'echec', 'notes' => mb_substr((string) $r['error'], 0, 500)], 'id = :id', ['id' => $versionId]);
            return ['ok' => false, 'error' => $r['error'], 'job_id' => $jobId];
        }
        Database::update('ai_training_jobs', ['remote_job_id' => $r['data']['job_id'], 'status' => 'en_cours', 'started_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $jobId]);
        Audit::log('ia_preparation_lancee', 'ai_model_version', $versionId, ['version' => $version, 'produits' => count($payloadProducts)]);
        return ['ok' => true, 'job_id' => $jobId, 'version_id' => $versionId];
    }

    /** Interroge le service IA et enregistre le résultat réel lorsque la tâche est terminée. */
    public static function poll(array $job): array
    {
        if (!in_array($job['status'], ['en_attente', 'en_cours'], true) || !$job['remote_job_id']) {
            return $job;
        }
        $r = AiClient::request('GET', '/training/jobs/' . rawurlencode($job['remote_job_id']));
        if (!$r['ok']) {
            if ($r['status'] === 404) {
                Database::update('ai_training_jobs', ['status' => 'echec', 'message' => 'Tâche perdue (redémarrage du service IA). Relancez la préparation.', 'finished_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $job['id']]);
                Database::update('ai_model_versions', ['status' => 'echec'], 'id = :id', ['id' => $job['model_version_id']]);
            }
            return Database::fetch('SELECT * FROM ai_training_jobs WHERE id = :id', ['id' => $job['id']]);
        }
        $d = $r['data'];
        if (in_array($d['status'], ['en_attente', 'en_cours'], true)) {
            Database::update('ai_training_jobs', ['progress' => (int) $d['progress'], 'message' => mb_substr((string) $d['message'], 0, 500)], 'id = :id', ['id' => $job['id']]);
        } elseif ($d['status'] === 'echec') {
            Database::update('ai_training_jobs', ['status' => 'echec', 'message' => mb_substr((string) $d['message'], 0, 500), 'finished_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $job['id']]);
            Database::update('ai_model_versions', ['status' => 'echec', 'notes' => mb_substr((string) $d['message'], 0, 500)], 'id = :id', ['id' => $job['model_version_id']]);
        } else {
            self::storeResult($job, $d['result']);
        }
        return Database::fetch('SELECT * FROM ai_training_jobs WHERE id = :id', ['id' => $job['id']]);
    }

    private static function storeResult(array $job, array $res): void
    {
        Database::transaction(static function () use ($job, $res) {
            $vid = (int) $job['model_version_id'];
            $ok = $res['status'] === 'prete';
            Database::update('ai_model_versions', [
                'status' => $ok ? 'prete' : 'echec', 'artifact_file' => $res['gallery_file'], 'embedder' => $res['embedder'] ?? null,
                'image_count' => (int) ($res['reference_photos'] ?? 0), 'product_count' => count($res['included_products'] ?? []),
                'products_json' => json_encode($res['included_products'] ?? []),
                'metrics_json' => json_encode(['metrics' => $res['metrics'] ?? null, 'warnings' => $res['warnings'] ?? [], 'products' => $res['products'] ?? [], 'message' => $res['message']], JSON_UNESCAPED_UNICODE),
                'trained_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $vid]);
            Database::execute('DELETE FROM ai_validation_results WHERE model_version_id = :v', ['v' => $vid]);
            foreach ($res['validation'] ?? [] as $v) {
                Database::insert('ai_validation_results', ['model_version_id' => $vid, 'product_id' => $v['product_id'], 'test_images' => $v['test_images'],
                    'correct' => $v['correct'], 'wrong' => $v['wrong'], 'unidentified' => $v['unidentified'], 'accuracy' => $v['accuracy'],
                    'most_confused_with_product_id' => $v['most_confused_with'], 'details_json' => json_encode($v['details'])]);
            }
            foreach ($res['images'] ?? [] as $im) {
                Database::update('product_images', ['quality_score' => $im['quality'], 'quality_notes' => mb_substr(implode(', ', $im['issues'] ?? []), 0, 255)],
                    'id = :id', ['id' => $im['image_id']]);
            }
            foreach ($res['products'] ?? [] as $p) {
                $status = !$p['included'] ? 'photos_insuffisantes' : (($p['accuracy'] ?? 0) >= (float) Settings::get('ai.min_validation_accuracy', 0.8) ? 'validee' : 'echec');
                Database::execute("UPDATE products SET recognition_status = :st WHERE id = :id AND recognition_status <> 'active'", ['st' => $status, 'id' => $p['product_id']]);
            }
            Database::update('ai_training_jobs', ['status' => 'termine', 'progress' => 100, 'message' => mb_substr((string) $res['message'], 0, 500),
                'errors_json' => json_encode($res['warnings'] ?? [], JSON_UNESCAPED_UNICODE), 'finished_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $job['id']]);
        });
    }

    /** Active une version (ou restaure une version précédente). */
    public static function activate(int $storeId, int $versionId): array
    {
        $v = Database::fetch('SELECT v.*, m.store_id FROM ai_model_versions v JOIN ai_models m ON m.id = v.ai_model_id WHERE v.id = :id', ['id' => $versionId]);
        if (!$v || (int) $v['store_id'] !== $storeId) {
            return ['ok' => false, 'error' => 'Version introuvable.'];
        }
        if (!in_array($v['status'], ['prete', 'archivee', 'active'], true) || !$v['artifact_file']) {
            return ['ok' => false, 'error' => 'Seule une version validée peut être activée.'];
        }
        $r = AiClient::request('POST', '/recognition/activate', ['gallery_file' => $v['artifact_file']]);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error']];
        }
        Database::transaction(static function () use ($v) {
            Database::execute("UPDATE ai_model_versions SET status = 'archivee' WHERE ai_model_id = :m AND status = 'active'", ['m' => $v['ai_model_id']]);
            Database::update('ai_model_versions', ['status' => 'active', 'activated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $v['id']]);
            Database::update('ai_models', ['active_version_id' => $v['id']], 'id = :id', ['id' => $v['ai_model_id']]);
            $ids = json_decode((string) $v['products_json'], true) ?: [];
            Database::execute("UPDATE products SET recognition_status = IF(recognition_status = 'active', 'validee', recognition_status), detection_enabled = 0 WHERE store_id = :s",
                ['s' => $v['store_id']]);
            foreach ($ids as $pid) {
                Database::execute("UPDATE products SET recognition_status = 'active', detection_enabled = 1, ai_model_id = :m WHERE id = :id",
                    ['m' => $v['ai_model_id'], 'id' => (int) $pid]);
            }
        });
        Audit::log('ia_version_activee', 'ai_model_version', $v['id'], ['version' => $v['version_number']]);
        return ['ok' => true];
    }
}
