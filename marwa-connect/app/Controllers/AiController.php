<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Services\AiService;
use App\Services\AuditService;
use Throwable;

/** Interface conversationnelle OPTIMUS IA. */
final class AiController extends Controller
{
    private function conversation(): int
    {
        $id = Database::value('SELECT id FROM ai_conversations WHERE user_id = :u ORDER BY id DESC LIMIT 1', ['u' => Auth::id()]);
        return $id ? (int) $id : Database::insert('ai_conversations', ['user_id' => Auth::id(), 'title' => 'Conversation']);
    }

    public function index(Request $request): Response
    {
        $this->authorize('ai.use');
        $conv = $this->conversation();
        $messages = array_reverse(Database::fetchAll('SELECT * FROM ai_messages WHERE conversation_id = :c ORDER BY id DESC LIMIT 40', ['c' => $conv]));
        foreach ($messages as &$m) {
            $m['data'] = json_decode((string) $m['data'], true) ?: [];
        }
        $pending = Database::fetchAll("SELECT * FROM ai_pending_actions WHERE user_id = :u AND status = 'awaiting_confirmation' AND expires_at > NOW() ORDER BY id DESC", ['u' => Auth::id()]);
        return $this->view('ai/index', ['messages' => $messages, 'pending' => $pending, 'external' => AiService::externalAvailable()]);
    }

    public function ask(Request $request): Response
    {
        $this->authorize('ai.use');
        $question = mb_substr($request->str('question'), 0, 1000);
        if ($question === '') {
            return $this->json(['success' => false, 'message' => 'Question vide.'], 422);
        }
        $conv = $this->conversation();
        Database::insert('ai_messages', ['conversation_id' => $conv, 'role' => 'user', 'content' => $question]);
        $res = AiService::answer($question);
        if ($res['intent'] === 'unknown' && AiService::externalAvailable()) {
            try {
                $res = AiService::external($question) ?? $res;
            } catch (Throwable $e) {
                Logger::warning('IA externe indisponible : ' . $e->getMessage(), [], 'ai');
                $res['text'] .= "\n(Le modèle externe est injoignable : fonctionnement local uniquement.)";
            }
        }
        $actionId = null;
        if (!empty($res['action'])) {
            $actionId = Database::insert('ai_pending_actions', [
                'user_id' => Auth::id(), 'conversation_id' => $conv, 'action' => $res['action']['action'],
                'params' => json_encode($res['action']['params']), 'summary' => $res['action']['summary'], 'expires_at' => date('Y-m-d H:i:s', time() + 900),
            ]);
        }
        $payload = ['table' => $res['table'] ?? null, 'action_id' => $actionId, 'summary' => $res['action']['summary'] ?? null];
        Database::insert('ai_messages', ['conversation_id' => $conv, 'role' => 'assistant', 'content' => $res['text'], 'intent' => $res['intent'],
            'data' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'engine' => $res['engine']]);
        return $this->json(['success' => true, 'text' => $res['text'], 'engine' => $res['engine']] + $payload);
    }

    public function confirm(Request $request, string $id): Response
    {
        $this->authorize('ai.execute');
        $a = Database::fetch("SELECT * FROM ai_pending_actions WHERE id = :id AND user_id = :u AND status = 'awaiting_confirmation' AND expires_at > NOW()", ['id' => (int) $id, 'u' => Auth::id()]);
        if (!$a) {
            return $this->json(['success' => false, 'message' => 'Action introuvable ou expirée.'], 404);
        }
        if ($request->str('decision') === 'reject') {
            Database::update('ai_pending_actions', ['status' => 'rejected'], 'id = :id', ['id' => $a['id']]);
            return $this->json(['success' => true, 'text' => 'Opération annulée.']);
        }
        // Les permissions de l'action elle-même restent exigées.
        if ($a['action'] === 'generate_tickets') {
            $this->authorize('tickets.generate');
        }
        Database::update('ai_pending_actions', ['status' => 'confirmed', 'confirmed_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $a['id']]);
        @set_time_limit(300);
        try {
            $msg = AiService::execute($a, (int) Auth::id());
            Database::update('ai_pending_actions', ['status' => 'executed', 'executed_at' => date('Y-m-d H:i:s'), 'result' => json_encode(['message' => $msg])], 'id = :id', ['id' => $a['id']]);
        } catch (Throwable $e) {
            $msg = 'Échec : ' . $e->getMessage();
            Database::update('ai_pending_actions', ['status' => 'failed', 'result' => json_encode(['error' => $e->getMessage()])], 'id = :id', ['id' => $a['id']]);
        }
        AuditService::log('ai.execute', 'ai_action', (int) $a['id'], "Optimus IA : {$a['summary']} — {$msg}");
        Database::insert('ai_messages', ['conversation_id' => $a['conversation_id'] ?? $this->conversation(), 'role' => 'assistant', 'content' => $msg, 'intent' => 'action_result']);
        return $this->json(['success' => true, 'text' => $msg]);
    }

    public function reset(Request $request): Response
    {
        $this->authorize('ai.use');
        Database::insert('ai_conversations', ['user_id' => Auth::id(), 'title' => 'Conversation']);
        return $this->redirect('/ai');
    }
}
