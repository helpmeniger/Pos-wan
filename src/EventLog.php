<?php
declare(strict_types=1);

namespace Verifia;

/** Journal des événements système et journal d'audit des actions utilisateurs. */
final class EventLog
{
    public static function log(string $level, string $component, string $message, ?int $sessionId = null, array $context = []): void
    {
        try {
            Database::insert('system_events', [
                'level'        => $level,
                'component'    => substr($component, 0, 40),
                'session_id'   => $sessionId,
                'message'      => mb_substr($message, 0, 1000),
                'context_json' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
            ]);
        } catch (\Throwable $e) {
            // La base est indisponible : on écrit dans un fichier pour ne rien perdre.
            @file_put_contents(
                Config::storagePath('logs/verifia.log'),
                sprintf("[%s] %s %s: %s (%s)\n", gmdate('c'), strtoupper($level), $component, $message, $e->getMessage()),
                FILE_APPEND
            );
        }
    }

    public static function audit(?int $userId, string $action, ?string $targetType = null, int|string|null $targetId = null, array $details = []): void
    {
        Database::insert('audit_logs', [
            'user_id'      => $userId,
            'action'       => substr($action, 0, 60),
            'target_type'  => $targetType,
            'target_id'    => $targetId === null ? null : (string) $targetId,
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'details_json' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
