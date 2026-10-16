<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Core\Database;
use App\Core\Request;
use App\Support\Logger;
use Throwable;

/** Événements de sécurité (connexions, refus d'accès, CSRF, téléversements rejetés…). */
class SecurityLogger
{
    public function __construct(private readonly Database $db, private readonly Logger $logger)
    {
    }

    /** @param array<string, mixed> $details */
    public function log(string $event, ?int $userId, ?Request $request = null, array $details = [], string $severity = 'INFO'): void
    {
        try {
            $this->db->insert('security_events', [
                'user_id' => $userId,
                'event_type' => mb_substr($event, 0, 60),
                'severity' => $severity,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'details_json' => $details ? json_encode(Logger::redact($details), JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (Throwable $e) {
            // La journalisation ne doit jamais casser la requête ; on garde une trace fichier
            $this->logger->error('Échec d\'écriture security_events', ['event' => $event, 'exception' => $e]);
        }
    }
}
