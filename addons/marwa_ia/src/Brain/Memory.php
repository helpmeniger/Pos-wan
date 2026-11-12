<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

use App\Core\BusinessException;
use App\Core\DB;

/**
 * MARWA CONVERSATION MEMORY ENGINE.
 * Conversations et messages strictement rattachés à un utilisateur (jamais lus par un autre compte, même administrateur),
 * état de contexte (dernière intention, paramètres, périodes) pour les questions de suite, résumé des longues
 * conversations, durée de conservation réglable (ai_retention_days) et suppression par l'utilisateur.
 */
final class Memory
{
    public const KEEP_MESSAGES = 12;

    public static function retentionDays(): int
    {
        return max(1, min(3650, (int) setting('ai_retention_days', '90')));
    }

    /** Conversation de l'utilisateur (ou nouvelle). */
    public static function conversation(int $userId, ?int $id): array
    {
        if ($id) {
            $c = DB::one('SELECT * FROM ai_conversations WHERE id = ? AND user_id = ?', [$id, $userId]);
            if (!$c) {
                throw new BusinessException('Conversation introuvable.');
            }
            return $c;
        }
        $nid = DB::insert('ai_conversations', ['user_id' => $userId, 'updated_at' => date('Y-m-d H:i:s')]);
        return DB::one('SELECT * FROM ai_conversations WHERE id = ?', [$nid]);
    }

    public static function list(int $userId, int $limit = 30): array
    {
        return DB::all('SELECT id, title, message_count, updated_at FROM ai_conversations WHERE user_id = ? ORDER BY COALESCE(updated_at, created_at) DESC LIMIT ' . max(1, min(100, $limit)), [$userId]);
    }

    public static function messages(int $userId, int $convId): array
    {
        self::conversation($userId, $convId);
        return DB::all('SELECT id, role, content, payload_json, intent, sources, created_at FROM ai_messages WHERE conversation_id = ? ORDER BY id', [$convId]);
    }

    public static function state(array $conv): array
    {
        return json_decode((string) ($conv['context_json'] ?? ''), true) ?: [];
    }

    public static function saveState(int $convId, array $state): void
    {
        DB::update('ai_conversations', ['context_json' => json_encode($state, JSON_UNESCAPED_UNICODE)], 'id = ?', [$convId]);
    }

    public static function add(int $convId, string $role, string $content, array $extra = []): int
    {
        $id = DB::insert('ai_messages', ['conversation_id' => $convId, 'role' => $role, 'content' => mb_substr($content, 0, 60000)] + $extra);
        $c = DB::one('SELECT title, message_count FROM ai_conversations WHERE id = ?', [$convId]);
        $set = ['message_count' => (int) $c['message_count'] + 1, 'updated_at' => date('Y-m-d H:i:s')];
        if ($role === 'user' && (int) $c['message_count'] === 0) {
            $set['title'] = mb_substr(trim(preg_replace('/\s+/', ' ', $content)), 0, 80);
        }
        DB::update('ai_conversations', $set, 'id = ?', [$convId]);
        if ($role === 'assistant' && ((int) $c['message_count'] + 1) % 20 === 0) {
            self::summarize($convId);
        }
        return $id;
    }

    /** Résumé extractif d'une longue conversation (questions posées et intentions traitées), sans modèle externe. */
    public static function summarize(int $convId): void
    {
        $rows = DB::all("SELECT content, intent FROM ai_messages WHERE conversation_id = ? AND role = 'user' ORDER BY id", [$convId]);
        $topics = array_count_values(array_filter(array_column(DB::all("SELECT intent FROM ai_messages WHERE conversation_id = ? AND role = 'assistant' AND intent IS NOT NULL", [$convId]), 'intent')));
        arsort($topics);
        $sum = 'Sujets traités : ' . implode(', ', array_map(fn($k) => Catalog::TOOLS[$k][0] ?? $k, array_slice(array_keys($topics), 0, 6))) . '. Dernières questions : '
            . implode(' / ', array_map(fn($r) => mb_substr($r['content'], 0, 80), array_slice($rows, -4))) . '.';
        DB::update('ai_conversations', ['summary' => mb_substr($sum, 0, 2000)], 'id = ?', [$convId]);
    }

    /** Derniers échanges (texte seul) pour donner le contexte à un modèle. */
    public static function recent(int $convId, int $n = 6): array
    {
        $rows = array_reverse(DB::all('SELECT role, content FROM ai_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ' . max(1, min(20, $n)), [$convId]));
        return array_map(fn($r) => ['role' => $r['role'], 'content' => mb_substr(strip_tags($r['content']), 0, 1500)], $rows);
    }

    public static function rename(int $userId, int $convId, string $title): void
    {
        self::conversation($userId, $convId);
        DB::update('ai_conversations', ['title' => mb_substr(trim($title), 0, 160) ?: 'Conversation'], 'id = ?', [$convId]);
    }

    public static function delete(int $userId, int $convId): void
    {
        self::conversation($userId, $convId);
        DB::run('DELETE FROM ai_conversations WHERE id = ? AND user_id = ?', [$convId, $userId]);
    }

    public static function deleteAll(int $userId): int
    {
        return DB::run('DELETE FROM ai_conversations WHERE user_id = ?', [$userId])->rowCount();
    }

    /** Purge selon la durée de conservation. */
    public static function purge(): int
    {
        return DB::run('DELETE FROM ai_conversations WHERE COALESCE(updated_at, created_at) < NOW() - INTERVAL ? DAY', [self::retentionDays()])->rowCount();
    }
}
