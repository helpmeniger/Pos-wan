<?php
declare(strict_types=1);

namespace Verifia\Alerts;

use Verifia\Database;
use Verifia\EventLog;
use Verifia\Analysis\PriorityScorer;

/**
 * Centre d'alertes. Les alertes ne sont jamais supprimées automatiquement :
 * elles survivent à la fermeture ou à la suppression de la session source
 * (nom de session et de site copiés dans l'alerte).
 */
final class AlertService
{
    public const STATUSES = ['new', 'read', 'in_review', 'verified', 'ignored'];

    public static function raise(int $publicationId, int $resultId, string $priority, string $category, array $indicators, float $confidence, string $text): int
    {
        $pub = Database::one(
            'SELECT p.first_session_id, s.name AS session_name, ms.name AS site_name
               FROM detected_publications p
          LEFT JOIN monitoring_sessions s ON s.id = p.first_session_id
          LEFT JOIN monitored_sites ms ON ms.id = p.site_id WHERE p.id = ?',
            [$publicationId]
        );
        usort($indicators, fn($a, $b) => $b['weight'] <=> $a['weight']);
        $reason = implode(' · ', array_slice(array_column($indicators, 'label'), 0, 4)) ?: 'Affirmation(s) vérifiable(s) détectée(s)';
        $existing = Database::one('SELECT id, priority, status FROM alerts WHERE publication_id = ?', [$publicationId]);
        if ($existing) {
            // Réanalyse : on met à jour sans jamais rétrograder une alerte déjà traitée par un humain.
            Database::run(
                'UPDATE alerts SET result_id = ?, priority = ?, category = ?, reason = ?, confidence = ?, is_important = ? WHERE id = ?',
                [$resultId, $priority, $category, mb_substr($reason, 0, 1000), $confidence, PriorityScorer::atLeast($priority, 'high') ? 1 : 0, $existing['id']]
            );
            self::history((int) $existing['id'], null, 'reanalyzed', $existing['priority'], $priority);
            return (int) $existing['id'];
        }
        $id = Database::insert('alerts', [
            'publication_id' => $publicationId,
            'result_id'      => $resultId,
            'session_id'     => $pub['first_session_id'] ?? null,
            'session_name'   => $pub['session_name'] ?? 'Analyse manuelle',
            'site_name'      => $pub['site_name'] ?? null,
            'priority'       => $priority,
            'category'       => $category,
            'reason'         => mb_substr($reason, 0, 1000),
            'excerpt'        => mb_substr($text, 0, 1200),
            'confidence'     => $confidence,
            'is_important'   => PriorityScorer::atLeast($priority, 'high') ? 1 : 0,
        ]);
        if (!empty($pub['first_session_id'])) {
            Database::run('UPDATE monitoring_sessions SET alerts_count = alerts_count + 1 WHERE id = ?', [$pub['first_session_id']]);
        }
        self::history($id, null, 'created', null, $priority);
        EventLog::log('info', 'worker', "Alerte #$id ($priority) : $reason", $pub['first_session_id'] ?? null);
        return $id;
    }

    public static function history(int $alertId, ?int $userId, string $action, ?string $old, ?string $new, ?string $comment = null): void
    {
        Database::insert('alert_history', [
            'alert_id' => $alertId, 'user_id' => $userId, 'action' => $action,
            'old_value' => $old, 'new_value' => $new, 'comment' => $comment,
        ]);
    }

    /** Liste filtrée (toutes les valeurs passent par des paramètres liés). */
    public static function search(array $f, int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = self::where($f);
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $rows = Database::all(
            "SELECT a.*, u.display_name AS assignee, p.permalink, c.thumbnail_path,
                    (s.id IS NOT NULL AND s.desired_state IN ('running','paused')) AS session_available
               FROM alerts a
               JOIN detected_publications p ON p.id = a.publication_id
          LEFT JOIN publication_content c ON c.publication_id = a.publication_id
          LEFT JOIN users u ON u.id = a.assigned_to
          LEFT JOIN monitoring_sessions s ON s.id = a.session_id
              $where
              ORDER BY a.id DESC LIMIT $limit OFFSET $offset",
            $params
        );
        $total = (int) Database::value("SELECT COUNT(*) FROM alerts a $where", $params);
        return ['total' => $total, 'items' => $rows];
    }

    private static function where(array $f): array
    {
        $w = [];
        $p = [];
        if (!empty($f['since_id'])) {
            $w[] = 'a.id > ?';
            $p[] = (int) $f['since_id'];
        }
        if (!empty($f['priority']) && isset(PriorityScorer::ORDER[$f['priority']])) {
            $w[] = 'a.priority = ?';
            $p[] = $f['priority'];
        }
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) {
            $w[] = 'a.status = ?';
            $p[] = $f['status'];
        } elseif (empty($f['include_ignored'])) {
            $w[] = "a.status <> 'ignored'";
        }
        if (!empty($f['session_id'])) {
            $w[] = 'a.session_id = ?';
            $p[] = (int) $f['session_id'];
        }
        if (!empty($f['site'])) {
            $w[] = 'a.site_name = ?';
            $p[] = (string) $f['site'];
        }
        if (!empty($f['category'])) {
            $w[] = 'a.category = ?';
            $p[] = (string) $f['category'];
        }
        if (!empty($f['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['date_from'])) {
            $w[] = 'a.created_at >= ?';
            $p[] = $f['date_from'] . ' 00:00:00';
        }
        if (!empty($f['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['date_to'])) {
            $w[] = 'a.created_at <= ?';
            $p[] = $f['date_to'] . ' 23:59:59';
        }
        if (!empty($f['q'])) {
            $w[] = '(a.excerpt LIKE ? OR a.reason LIKE ? OR a.site_name LIKE ?)';
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            array_push($p, $like, $like, $like);
        }
        if (!empty($f['assigned_to'])) {
            $w[] = 'a.assigned_to = ?';
            $p[] = (int) $f['assigned_to'];
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
    }

    public static function setStatus(int $id, string $status, ?int $userId, ?string $comment = null): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $old = Database::value('SELECT status FROM alerts WHERE id = ?', [$id]);
        if ($old === null) {
            return false;
        }
        Database::run('UPDATE alerts SET status = ? WHERE id = ?', [$status, $id]);
        self::history($id, $userId, 'status', (string) $old, $status, $comment);
        EventLog::audit($userId, 'alert_status', 'alert', $id, ['from' => $old, 'to' => $status]);
        return true;
    }

    public static function assign(int $id, ?int $assignee, ?int $userId): bool
    {
        if ($assignee !== null && !Database::value('SELECT id FROM users WHERE id = ? AND is_active = 1', [$assignee])) {
            return false;
        }
        $old = Database::value('SELECT assigned_to FROM alerts WHERE id = ?', [$id]);
        Database::run("UPDATE alerts SET assigned_to = ?, status = IF(status IN ('new','read'), 'in_review', status) WHERE id = ?", [$assignee, $id]);
        self::history($id, $userId, 'assigned', $old === null ? null : (string) $old, $assignee === null ? null : (string) $assignee);
        EventLog::audit($userId, 'alert_assign', 'alert', $id, ['to' => $assignee]);
        return true;
    }

    public static function markAllRead(?int $userId): int
    {
        return Database::run("UPDATE alerts SET status = 'read' WHERE status = 'new'")->rowCount();
    }

    public static function unreadCount(): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM alerts WHERE status = 'new'");
    }

    /** Fiche complète : publication, analyse, affirmations, sources, images, correspondances, historique. */
    public static function detail(int $id): ?array
    {
        $a = Database::one(
            'SELECT a.*, u.display_name AS assignee, s.desired_state AS session_state FROM alerts a
          LEFT JOIN users u ON u.id = a.assigned_to
          LEFT JOIN monitoring_sessions s ON s.id = a.session_id WHERE a.id = ?',
            [$id]
        );
        if (!$a) {
            return null;
        }
        $a['publication'] = self::publication((int) $a['publication_id']);
        $a['history'] = Database::all(
            'SELECT h.*, u.display_name FROM alert_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.alert_id = ? ORDER BY h.id',
            [$id]
        );
        return $a;
    }

    public static function publication(int $pid): ?array
    {
        $p = Database::one(
            'SELECT p.*, c.text_content, c.links_json, c.dates_json, c.thumbnail_path, c.language, ms.name AS site_name, ms.domain
               FROM detected_publications p JOIN publication_content c ON c.publication_id = p.id
          LEFT JOIN monitored_sites ms ON ms.id = p.site_id WHERE p.id = ?',
            [$pid]
        );
        if (!$p) {
            return null;
        }
        $p['result'] = Database::one('SELECT * FROM analysis_results WHERE publication_id = ? ORDER BY id DESC LIMIT 1', [$pid]);
        $p['claims'] = Database::all('SELECT * FROM claims WHERE publication_id = ? ORDER BY id', [$pid]);
        foreach ($p['claims'] as &$c) {
            $c['evidence'] = Database::all('SELECT * FROM evidence_sources WHERE claim_id = ? ORDER BY source_published_at IS NULL, source_published_at, id', [$c['id']]);
        }
        unset($c);
        $p['images'] = Database::all('SELECT * FROM image_assets WHERE publication_id = ? ORDER BY id', [$pid]);
        foreach ($p['images'] as &$img) {
            $img['matches'] = Database::all(
                'SELECT m.*, i.file_path, i.publication_id, p.first_seen_at, p.published_label, p.permalink, ms.name AS site_name
                   FROM image_matches m JOIN image_assets i ON i.id = m.matched_image_id
                   JOIN detected_publications p ON p.id = i.publication_id
              LEFT JOIN monitored_sites ms ON ms.id = p.site_id
                  WHERE m.image_id = ? ORDER BY p.first_seen_at',
                [$img['id']]
            );
            $img['evidence'] = Database::all('SELECT * FROM evidence_sources WHERE image_asset_id = ? ORDER BY source_published_at IS NULL, source_published_at', [$img['id']]);
        }
        unset($img);
        $p['occurrences'] = Database::all(
            'SELECT o.*, s.name AS session_name FROM publication_occurrences o LEFT JOIN monitoring_sessions s ON s.id = o.session_id WHERE o.publication_id = ? ORDER BY o.seen_at',
            [$pid]
        );
        return $p;
    }

    /**
     * Chronologie d'une image : détections internes + sources ajoutées par les analystes.
     * Chaque entrée indique sa nature, pour ne pas confondre date de détection et date de création.
     */
    public static function imageTimeline(array $img, array $currentPub): array
    {
        $t = [];
        foreach ($img['matches'] as $m) {
            $t[] = ['date' => $m['first_seen_at'], 'kind' => 'detection_verifia', 'label' => 'Détectée par VERIFIA dans la publication #' . $m['publication_id'] . ($m['site_name'] ? ' (' . $m['site_name'] . ')' : ''), 'url' => $m['permalink'], 'shown_date' => $m['published_label']];
        }
        foreach ($img['evidence'] as $e) {
            $t[] = ['date' => $e['source_published_at'] ?? $e['consulted_at'], 'kind' => 'source_analyste', 'label' => ($e['title'] ?: $e['url']) . ($e['publisher'] ? ' — ' . $e['publisher'] : ''), 'url' => $e['url'], 'shown_date' => null];
        }
        $t[] = ['date' => $currentPub['first_seen_at'], 'kind' => 'publication_actuelle', 'label' => 'Publication analysée #' . $currentPub['id'], 'url' => $currentPub['permalink'], 'shown_date' => $currentPub['published_label']];
        usort($t, fn($a, $b) => strcmp((string) $a['date'], (string) $b['date']));
        return $t;
    }
}
