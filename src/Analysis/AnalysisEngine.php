<?php
declare(strict_types=1);

namespace Verifia\Analysis;

use Verifia\Alerts\AlertService;
use Verifia\Config;
use Verifia\Database;
use Verifia\Settings;

/** Exécute l'analyse complète d'une publication et enregistre les résultats. */
final class AnalysisEngine
{
    public const VERSION = '1.0.0';
    private const SIMILAR_SCAN_LIMIT = 5000;
    private const IMAGE_SCAN_LIMIT = 50000;

    public function __construct(private TextAnalyzer $text = new TextAnalyzer())
    {
    }

    public function analyze(int $publicationId): array
    {
        $pub = Database::one(
            'SELECT p.*, c.text_content, c.text_simhash, c.links_json, c.media_json, ms.name AS site_name, ms.is_satire,
                    s.name AS session_name
               FROM detected_publications p
               JOIN publication_content c ON c.publication_id = p.id
          LEFT JOIN monitored_sites ms ON ms.id = p.site_id
          LEFT JOIN monitoring_sessions s ON s.id = p.first_session_id
              WHERE p.id = ?',
            [$publicationId]
        );
        if (!$pub) {
            throw new \RuntimeException("Publication #$publicationId introuvable");
        }
        Database::run("UPDATE detected_publications SET status = 'analyzing' WHERE id = ?", [$publicationId]);

        $text = (string) $pub['text_content'];
        $links = json_decode((string) $pub['links_json'], true) ?: [];

        // 1. Images : empreintes, OCR, EXIF, comparaison avec les images déjà rencontrées
        $images = $this->processImages($pub);
        $ocrText = trim(implode("\n", array_filter(array_column($images, 'ocr_text'))));
        $fullText = trim($text . ($ocrText !== '' ? "\n" . $ocrText : ''));

        // 2. Texte : classification, affirmations, indices
        $ta = $this->text->analyze($fullText, $links, (bool) $pub['is_satire']);
        $indicators = $ta['indicators'];
        $limitations = [
            'Analyse heuristique locale (lexiques et règles) : elle signale des indices, elle ne prouve pas qu\'un contenu est faux.',
            'Aucune recherche externe automatique n\'a été effectuée. Les sources de vérification doivent être ajoutées par un analyste.',
        ];
        if ($images && !array_filter($images, fn($i) => $i['ocr_status'] === 'done' || $i['ocr_status'] === 'no_text')) {
            $limitations[] = 'OCR non disponible (Tesseract non configuré) : le texte incrusté dans les images n\'a pas été lu.';
        }

        // 3. Comparaison des images avec l'historique
        $matchedEarlier = [];
        foreach ($images as $img) {
            foreach ($img['matches'] as $m) {
                $matchedEarlier[] = $m + ['image_id' => $img['id']];
            }
        }
        if ($matchedEarlier) {
            $first = $matchedEarlier[0];
            $indicators[] = [
                'code'    => 'image_reuse',
                'label'   => 'Image déjà rencontrée dans une publication antérieure : contexte à vérifier',
                'weight'  => 4,
                'excerpt' => sprintf(
                    'Correspondance %s (distance %d) avec l\'image de la publication #%d détectée le %s%s. '
                    . 'Cette date est celle de la première détection par VERIFIA, pas nécessairement celle de la prise de vue.',
                    $first['match_type'] === 'exact' ? 'exacte' : 'perceptuelle',
                    $first['distance'],
                    $first['publication_id'],
                    $first['first_seen_at'],
                    $first['published_label'] ? ' (date affichée par le site : « ' . $first['published_label'] . ' »)' : ''
                ),
                'ref_publication_id' => (int) $first['publication_id'],
            ];
            if ($ta['recency_terms']) {
                $indicators[] = [
                    'code' => 'image_presented_as_recent', 'weight' => 2,
                    'label' => 'Image réutilisée présentée comme actuelle',
                    'excerpt' => 'Termes : ' . implode(', ', $ta['recency_terms']),
                ];
            }
        }

        // 4. Comparaison avec les contenus antérieurs (SimHash puis Jaccard)
        $similar = $text !== '' ? $this->findSimilar($publicationId, $text, (int) $pub['text_simhash']) : [];
        foreach ($similar as $s) {
            if ($s['contradicted_claims'] > 0) {
                $indicators[] = ['code' => 'similar_contradicted', 'weight' => 4,
                    'label' => 'Contenu très proche d\'une publication dont une affirmation a été contredite',
                    'excerpt' => sprintf('Publication #%d (similarité %.0f %%)', $s['publication_id'], $s['similarity'] * 100),
                    'ref_publication_id' => $s['publication_id']];
                break;
            }
            if ($s['alert_id']) {
                $indicators[] = ['code' => 'similar_alerted', 'weight' => 2,
                    'label' => 'Contenu proche d\'une publication déjà signalée',
                    'excerpt' => sprintf('Publication #%d (similarité %.0f %%)', $s['publication_id'], $s['similarity'] * 100),
                    'ref_publication_id' => $s['publication_id']];
                break;
            }
        }

        // 5. Affirmations déjà contredites (même texte normalisé)
        $claimRows = [];
        foreach ($ta['claims'] as $c) {
            $hash = hash('sha256', TextNormalizer::forComparison($c['text']));
            $prev = Database::one(
                "SELECT c.id, c.publication_id FROM claims c WHERE c.claim_hash = ? AND c.verdict = 'contradicted' AND c.publication_id <> ? LIMIT 1",
                [$hash, $publicationId]
            );
            if ($prev) {
                $indicators[] = ['code' => 'contradicted_claim', 'weight' => 4,
                    'label' => 'Affirmation identique à une affirmation déjà contredite par un analyste',
                    'excerpt' => mb_substr($c['text'], 0, 200) . " (affirmation #{$prev['id']})",
                    'ref_publication_id' => (int) $prev['publication_id']];
            }
            $claimRows[] = ['text' => $c['text'], 'type' => $c['type'], 'hash' => $hash];
        }

        // 6. Score, priorité, catégorie finale
        $scored = PriorityScorer::score($indicators, count($claimRows), $ta['flags']);
        $category = $ta['category'];
        if ($scored['priority'] === 'critical' && !in_array($category, ['satire', 'opinion'], true)) {
            $category = 'trompeur_potentiel';
        }
        $confidence = PriorityScorer::confidence($ta['word_count'], count($indicators), (bool) $matchedEarlier);

        $resultId = Database::transaction(function () use ($publicationId, $category, $ta, $scored, $confidence, $indicators, $similar, $limitations, $claimRows) {
            // Suppression par clé primaire : un DELETE sur un intervalle vide poserait des verrous d'intervalle
            // (gap locks) source d'interblocages entre workers parallèles.
            $old = Database::all("SELECT id FROM claims WHERE publication_id = ? AND verdict = 'unverified' AND verified_by IS NULL", [$publicationId]);
            if ($old) {
                $ids = array_map('intval', array_column($old, 'id'));
                Database::run('DELETE FROM claims WHERE id IN (' . implode(',', $ids) . ')');
            }
            foreach ($claimRows as $c) {
                Database::insert('claims', [
                    'publication_id' => $publicationId,
                    'claim_text'     => $c['text'],
                    'claim_hash'     => $c['hash'],
                    'claim_type'     => $c['type'],
                    'limitations'    => 'Aucune recherche externe effectuée à ce stade.',
                ]);
            }
            $rid = Database::insert('analysis_results', [
                'publication_id'   => $publicationId,
                'category'         => $category,
                'topic'            => $ta['topic'],
                'priority'         => $scored['priority'],
                'score'            => $scored['score'],
                'confidence'       => $confidence,
                'indicators_json'  => json_encode($indicators, JSON_UNESCAPED_UNICODE),
                'places_json'      => json_encode(['places' => $ta['places'], 'dates' => $ta['dates']], JSON_UNESCAPED_UNICODE),
                'sources_json'     => json_encode($ta['sources'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'similar_json'     => json_encode($similar, JSON_UNESCAPED_UNICODE),
                'limitations'      => implode("\n", $limitations),
                'analyzer_version' => self::VERSION,
            ]);
            Database::run(
                "UPDATE detected_publications p JOIN publication_content c ON c.publication_id = p.id
                    SET p.status = 'analyzed', c.dates_json = ?, c.language = ? WHERE p.id = ?",
                [json_encode($ta['dates'], JSON_UNESCAPED_UNICODE), $ta['language'], $publicationId]
            );
            return $rid;
        });

        // 7. Transmission au centre d'alertes
        $alertId = null;
        $min = Settings::get('alert_min_priority', 'high');
        if (PriorityScorer::atLeast($scored['priority'], $min)) {
            $alertId = AlertService::raise($publicationId, $resultId, $scored['priority'], $category, $indicators, $confidence, $text);
        }

        return [
            'result_id'  => $resultId,
            'priority'   => $scored['priority'],
            'score'      => $scored['score'],
            'category'   => $category,
            'confidence' => $confidence,
            'indicators' => $indicators,
            'claims'     => count($claimRows),
            'images'     => count($images),
            'alert_id'   => $alertId,
        ];
    }

    /** Hache les images reçues, les enregistre et cherche les correspondances antérieures. */
    private function processImages(array $pub): array
    {
        $media = json_decode((string) $pub['media_json'], true) ?: [];
        $out = [];
        $threshold = Settings::int('image_dhash_threshold', 10);
        foreach ($media as $m) {
            $abs = Config::storagePath($m['path']);
            if (!is_file($abs)) {
                continue;
            }
            $existing = Database::one('SELECT * FROM image_assets WHERE publication_id = ? AND file_path = ?', [$pub['id'], $m['path']]);
            if ($existing) {
                $id = (int) $existing['id'];
                $ocr = ['status' => $existing['ocr_status'], 'text' => $existing['ocr_text']];
                $h = ['sha256' => $existing['sha256'], 'dhash' => (int) $existing['dhash'], 'ahash' => (int) $existing['ahash']];
            } else {
                $h = ImageHasher::fromFile($abs);
                $ocr = Ocr::run($abs);
                $exif = ImageHasher::exif($abs);
                $id = Database::insert('image_assets', [
                    'publication_id' => $pub['id'],
                    'sha256'     => $h['sha256'],
                    'dhash'      => $h['dhash'],
                    'ahash'      => $h['ahash'],
                    'width'      => $h['width'],
                    'height'     => $h['height'],
                    'file_path'  => $m['path'],
                    'source_url' => $m['src'] ?? null,
                    'caption'    => $m['caption'] ?? ($m['alt'] ?? null),
                    'ocr_text'   => $ocr['text'],
                    'ocr_status' => $ocr['status'],
                    'exif_json'  => $exif ? json_encode($exif, JSON_UNESCAPED_UNICODE) : null,
                ]);
            }
            $out[] = [
                'id' => $id,
                'ocr_text' => $ocr['text'],
                'ocr_status' => $ocr['status'],
                'matches' => $this->matchImage($id, (int) $pub['id'], $h['sha256'], (int) $h['dhash'], (int) $h['ahash'], $threshold, (string) $pub['text_content']),
            ];
        }
        return $out;
    }

    /**
     * Correspondance perceptuelle : les DEUX empreintes (différence et moyenne) doivent être proches.
     * Une seule empreinte produit des faux positifs sur des images peu texturées.
     */
    private function matchImage(int $imageId, int $publicationId, string $sha, int $dhash, int $ahash, int $threshold, string $text): array
    {
        $rows = Database::all(
            'SELECT i.id, i.sha256, i.dhash, i.ahash, i.publication_id, i.caption, p.first_seen_at, p.published_label, c.text_content
               FROM image_assets i
               JOIN detected_publications p ON p.id = i.publication_id
               JOIN publication_content c ON c.publication_id = i.publication_id
              WHERE i.publication_id <> ? AND i.id < ?
              ORDER BY i.id DESC LIMIT ' . self::IMAGE_SCAN_LIMIT,
            [$publicationId, $imageId]
        );
        $matches = [];
        foreach ($rows as $r) {
            $exact = hash_equals($r['sha256'], $sha);
            $dist = $exact ? 0 : SimHash::hamming($dhash, (int) $r['dhash']);
            if (!$exact && ($dist > $threshold || SimHash::hamming($ahash, (int) $r['ahash']) > $threshold)) {
                continue;
            }
            $captionSim = round(TextNormalizer::jaccard($text, (string) $r['text_content'], 2), 3);
            Database::run(
                'INSERT IGNORE INTO image_matches (image_id, matched_image_id, match_type, distance, caption_similarity) VALUES (?, ?, ?, ?, ?)',
                [$imageId, $r['id'], $exact ? 'exact' : 'perceptual', $dist, $captionSim]
            );
            $matches[] = [
                'matched_image_id' => (int) $r['id'],
                'publication_id'   => (int) $r['publication_id'],
                'match_type'       => $exact ? 'exact' : 'perceptual',
                'distance'         => $dist,
                'caption_similarity' => $captionSim,
                'first_seen_at'    => $r['first_seen_at'],
                'published_label'  => $r['published_label'],
            ];
        }
        // La correspondance la plus ancienne en premier (chronologie)
        usort($matches, fn($a, $b) => strcmp($a['first_seen_at'], $b['first_seen_at']));
        return $matches;
    }

    private function findSimilar(int $publicationId, string $text, int $simhash): array
    {
        $threshold = (float) Settings::get('text_similarity_threshold', '0.6');
        $rows = Database::all(
            'SELECT c.publication_id, c.text_simhash FROM publication_content c
              WHERE c.publication_id < ? AND c.text_simhash IS NOT NULL
              ORDER BY c.publication_id DESC LIMIT ' . self::SIMILAR_SCAN_LIMIT,
            [$publicationId]
        );
        $candidates = [];
        foreach ($rows as $r) {
            if (SimHash::hamming($simhash, (int) $r['text_simhash']) <= 16) {
                $candidates[] = (int) $r['publication_id'];
            }
        }
        $out = [];
        foreach (array_slice($candidates, 0, 20) as $cid) {
            $other = Database::one(
                "SELECT c.text_content, p.first_seen_at, a.id AS alert_id,
                        (SELECT COUNT(*) FROM claims cl WHERE cl.publication_id = p.id AND cl.verdict = 'contradicted') AS contradicted
                   FROM publication_content c JOIN detected_publications p ON p.id = c.publication_id
              LEFT JOIN alerts a ON a.publication_id = p.id WHERE c.publication_id = ?",
                [$cid]
            );
            $sim = TextNormalizer::jaccard($text, (string) $other['text_content']);
            if ($sim >= $threshold) {
                $out[] = [
                    'publication_id' => $cid,
                    'similarity' => round($sim, 3),
                    'first_seen_at' => $other['first_seen_at'],
                    'alert_id' => $other['alert_id'] ? (int) $other['alert_id'] : null,
                    'contradicted_claims' => (int) $other['contradicted'],
                ];
            }
        }
        usort($out, fn($a, $b) => $b['contradicted_claims'] <=> $a['contradicted_claims'] ?: $b['similarity'] <=> $a['similarity']);
        return array_slice($out, 0, 5);
    }
}
