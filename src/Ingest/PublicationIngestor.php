<?php
declare(strict_types=1);

namespace Verifia\Ingest;

use Verifia\Analysis\SimHash;
use Verifia\Analysis\TextNormalizer;
use Verifia\Config;
use Verifia\Database;
use Verifia\EventLog;
use Verifia\Queue\JobQueue;
use Verifia\Settings;

/**
 * Réception des publications visibles envoyées par le moteur de surveillance
 * (ou saisies manuellement) : validation, déduplication, stockage, mise en file d'analyse.
 */
final class PublicationIngestor
{
    public const MAX_TEXT = 20000;
    public const MAX_IMAGES = 4;
    public const MAX_IMAGE_BYTES = 3_000_000;
    private const ALLOWED_MIME = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /** Paramètres de suivi retirés des permaliens avant calcul de l'empreinte. */
    private const TRACKING = ['/^utm_/', '/^fbclid$/', '/^__cft__/', '/^__tn__$/', '/^ref$/', '/^refsrc$/', '/^igshid$/', '/^s$/', '/^t$/'];

    /**
     * @return array{status:string, publication_id?:int, reason?:string}
     *   status = created | duplicate | rejected
     */
    public static function ingest(array $post, ?int $sessionId, ?string $pageUrl, string $source = 'monitor'): array
    {
        $text = TextNormalizer::clean((string) ($post['text'] ?? ''));
        $text = mb_substr($text, 0, self::MAX_TEXT);
        $images = array_slice((array) ($post['images'] ?? []), 0, self::MAX_IMAGES);
        if (mb_strlen($text) < 15 && !$images) {
            return ['status' => 'rejected', 'reason' => 'Contenu vide ou trop court'];
        }
        $permalink = self::normalizePermalink($post['permalink'] ?? null);
        $session = $sessionId ? Database::one('SELECT id, site_id, analysis_enabled FROM monitoring_sessions WHERE id = ?', [$sessionId]) : null;
        $siteId = $session['site_id'] ?? null;
        if (!$siteId && ($permalink || $pageUrl)) {
            $siteId = \Verifia\Sessions\SessionService::siteFor($permalink ?: $pageUrl);
        }

        $fingerprint = self::fingerprint($siteId, $permalink, (string) ($post['key'] ?? ''), $text, $images);

        // Deux sessions peuvent transmettre la même publication au même instant : en cas d'interblocage
        // ou de violation de la clé unique, la transaction est rejouée et retrouve la publication existante.
        for ($attempt = 1; ; $attempt++) {
            try {
                return self::store($fingerprint, $sessionId, $pageUrl, $permalink, $siteId, $post, $text, $images, $source);
            } catch (\PDOException $e) {
                $retryable = in_array($e->getCode(), ['40001', '23000'], true) || in_array($e->errorInfo[1] ?? 0, [1213, 1205, 1062], true);
                if (!$retryable || $attempt >= 4) {
                    throw $e;
                }
                usleep(random_int(20_000, 120_000) * $attempt);
            }
        }
    }

    private static function store(string $fingerprint, ?int $sessionId, ?string $pageUrl, ?string $permalink, ?int $siteId, array $post, string $text, array $images, string $source): array
    {
        return Database::transaction(function () use ($fingerprint, $sessionId, $pageUrl, $permalink, $siteId, $post, $text, $images, $source) {
            $existing = Database::one('SELECT id FROM detected_publications WHERE fingerprint = ? FOR UPDATE', [$fingerprint]);
            if ($existing) {
                $pid = (int) $existing['id'];
                $added = Database::run(
                    'INSERT IGNORE INTO publication_occurrences (publication_id, session_id, page_url) VALUES (?, ?, ?)',
                    [$pid, $sessionId, self::truncUrl($pageUrl)]
                )->rowCount();
                Database::run(
                    'UPDATE detected_publications SET last_seen_at = ?, occurrences = occurrences + ? WHERE id = ?',
                    [Database::now(), $added, $pid]
                );
                if ($added && $sessionId) {
                    Database::run('UPDATE monitoring_sessions SET posts_seen = posts_seen + 1 WHERE id = ?', [$sessionId]);
                }
                return ['status' => 'duplicate', 'publication_id' => $pid];
            }

            $pid = Database::insert('detected_publications', [
                'fingerprint'      => $fingerprint,
                'first_session_id' => $sessionId,
                'site_id'          => $siteId,
                'permalink'        => self::truncUrl($permalink),
                'author_label'     => self::str($post['author'] ?? null, 255),
                'published_label'  => self::str($post['published_label'] ?? null, 120),
                'source'           => $source,
            ]);
            Database::insert('publication_occurrences', [
                'publication_id' => $pid,
                'session_id'     => $sessionId,
                'page_url'       => self::truncUrl($pageUrl),
            ]);

            $media = [];
            foreach ($images as $i => $img) {
                $saved = self::storeImage((string) ($img['data_base64'] ?? ''), "p{$pid}_{$i}");
                if ($saved) {
                    $media[] = [
                        'path'    => $saved,
                        'src'     => self::truncUrl(isset($img['src']) && !str_starts_with((string) $img['src'], 'data:') ? (string) $img['src'] : null),
                        'alt'     => self::str($img['alt'] ?? null, 500),
                        'caption' => self::str($img['caption'] ?? null, 2000),
                    ];
                }
            }
            $thumb = null;
            if (Settings::get('store_thumbnails', '1') === '1' && !empty($post['thumbnail_base64'])) {
                $thumb = self::storeImage((string) $post['thumbnail_base64'], "p{$pid}_thumb", 'thumbs');
            }
            $links = array_values(array_filter(array_map(fn($l) => self::truncUrl(is_string($l) ? $l : null), array_slice((array) ($post['links'] ?? []), 0, 30))));

            Database::insert('publication_content', [
                'publication_id' => $pid,
                'text_content'   => $text,
                'text_simhash'   => $text !== '' ? SimHash::hash($text) : null,
                'links_json'     => json_encode($links, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'thumbnail_path' => $thumb,
                'media_json'     => json_encode($media, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            if ($sessionId) {
                Database::run('UPDATE monitoring_sessions SET posts_seen = posts_seen + 1 WHERE id = ?', [$sessionId]);
            }
            // Analyse manuelle prioritaire ; les publications avec image passent avant le texte seul.
            $priority = $source === 'manual' ? 3 : ($media ? 4 : 5);
            JobQueue::push($pid, $priority);
            return ['status' => 'created', 'publication_id' => $pid];
        });
    }

    public static function fingerprint(?int $siteId, ?string $permalink, string $key, string $text, array $images): string
    {
        if ($permalink) {
            $basis = 'url|' . $permalink;
        } elseif ($key !== '') {
            $basis = 'key|' . $siteId . '|' . $key;
        } else {
            $norm = TextNormalizer::forComparison($text);
            if ($norm === '' && $images) {
                $norm = 'img|' . hash('sha256', (string) ($images[0]['data_base64'] ?? ''));
            }
            $basis = 'text|' . $siteId . '|' . $norm;
        }
        return hash('sha256', $basis);
    }

    public static function normalizePermalink(mixed $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }
        $p = parse_url(trim($url));
        if (!$p || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host'])) {
            return null;
        }
        $host = preg_replace('/^(www|m|mobile|web)\./', '', strtolower($p['host']));
        $query = '';
        if (!empty($p['query'])) {
            parse_str($p['query'], $q);
            foreach (array_keys($q) as $k) {
                foreach (self::TRACKING as $re) {
                    if (preg_match($re, (string) $k)) {
                        unset($q[$k]);
                        break;
                    }
                }
            }
            ksort($q);
            $query = $q ? '?' . http_build_query($q) : '';
        }
        $port = isset($p['port']) && !in_array((int) $p['port'], [80, 443], true) ? ':' . (int) $p['port'] : '';
        return 'https://' . $host . $port . rtrim($p['path'] ?? '/', '/') . $query;
    }

    /** Valide le contenu réel de l'image (pas seulement l'extension) puis l'enregistre. */
    public static function storeImage(string $b64, string $name, string $sub = 'images'): ?string
    {
        if ($b64 === '') {
            return null;
        }
        if (str_starts_with($b64, 'data:')) {
            $b64 = substr($b64, (int) strpos($b64, ',') + 1);
        }
        $bin = base64_decode($b64, true);
        if ($bin === false || strlen($bin) > self::MAX_IMAGE_BYTES || strlen($bin) < 64) {
            return null;
        }
        $info = @getimagesizefromstring($bin);
        if (!$info || !isset(self::ALLOWED_MIME[$info['mime']])) {
            return null;
        }
        $rel = $sub . '/' . gmdate('Y/m') . '/' . $name . '_' . substr(hash('sha256', $bin), 0, 16) . '.' . self::ALLOWED_MIME[$info['mime']];
        $abs = Config::storagePath($rel);
        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0775, true) && !is_dir(dirname($abs))) {
            EventLog::log('error', 'ingest', 'Impossible de créer le dossier ' . dirname($abs));
            return null;
        }
        file_put_contents($abs, $bin);
        return $rel;
    }

    private static function str(mixed $v, int $max): ?string
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        return mb_substr(trim($v), 0, $max);
    }

    private static function truncUrl(?string $u): ?string
    {
        if ($u === null || $u === '' || !preg_match('#^https?://#i', $u)) {
            return null;
        }
        return substr($u, 0, 2048);
    }
}
