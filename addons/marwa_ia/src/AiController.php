<?php
declare(strict_types=1);

namespace Addons\MarwaIa;

use Addons\MarwaIa\Brain\Actions;
use Addons\MarwaIa\Brain\AppMap;
use Addons\MarwaIa\Brain\Brain;
use Addons\MarwaIa\Brain\Catalog;
use Addons\MarwaIa\Brain\DataDictionary;
use Addons\MarwaIa\Brain\Learning;
use Addons\MarwaIa\Brain\Memory;
use Addons\MarwaIa\Brain\Nlu;
use Addons\MarwaIa\Brain\Providers;
use Addons\MarwaIa\Brain\UserContext;
use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Request;

/**
 * MARWA AI : conversation en continu (streaming), mémoire des conversations, actions confirmées, avis,
 * et administration (modèles, connaissances, intentions, apprentissage, banc de tests, dictionnaire de données).
 */
final class AiController extends Controller
{
    // ------------------------------------------------------------------ utilisateur

    public function index(): void
    {
        Learning::seed();
        $u = UserContext::current();
        view('marwa_ia::chat', ['title' => 'MARWA AI — assistant', 'conversations' => Memory::list(Auth::id()), 'examples' => IaController::examples(),
            'model' => Providers::chain()[0] ?? null, 'who' => $u->describe(), 'retention' => Memory::retentionDays()]);
    }

    /**
     * Réponse en continu (Server-Sent Events) : les étapes, blocs et jetons du modèle sont envoyés dès qu'ils sont prêts.
     * Événements : meta, status, text, html, block, delta, replace, open, done, error.
     */
    public function stream(): void
    {
        $in = Request::json();
        $q = mb_substr(trim((string) ($in['q'] ?? '')), 0, 500);
        $cid = (int) ($in['conversation_id'] ?? 0) ?: null;
        $page = mb_substr((string) ($in['page'] ?? ''), 0, 200) ?: null;
        UserContext::current();
        // La session n'est plus modifiée : on la libère pour ne pas bloquer les autres onglets pendant la réponse
        session_write_close();
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        set_time_limit(240);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store');
        header('X-Accel-Buffering: no');
        $emit = function (string $event, array $data): void {
            echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n\n";
            @flush();
        };
        echo ':' . str_repeat(' ', 512) . "\n\n"; // amorce (certains navigateurs attendent 512 octets)
        @flush();
        try {
            Brain::handle($q, $cid, $emit, $page);
        } catch (BusinessException $e) {
            $emit('error', ['message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            \App\Core\Logger::error('marwa_ai_stream', ['error' => $e->getMessage()]);
            $emit('error', ['message' => 'Erreur technique : la réponse n\'a pas pu être produite.']);
        }
        exit;
    }

    public function conversations(): void
    {
        json_response(['ok' => true, 'items' => Memory::list(Auth::id(), 60)]);
    }

    public function conversation(int $id): void
    {
        $this->jsonAttempt(function () use ($id) {
            $rows = Memory::messages(Auth::id(), (int) $id);
            $items = array_map(fn($m) => ['id' => (int) $m['id'], 'role' => $m['role'], 'content' => $m['content'], 'payload' => json_decode((string) $m['payload_json'], true) ?: null,
                'intent' => $m['intent'], 'sources' => $m['sources'] ? explode(',', $m['sources']) : [], 'at' => $m['created_at']], $rows);
            return ['items' => $items];
        });
    }

    public function rename(int $id): void
    {
        $this->jsonAttempt(function () use ($id) {
            Memory::rename(Auth::id(), (int) $id, (string) (Request::json()['title'] ?? Request::str('title')));
            return [];
        });
    }

    public function delete(int $id): void
    {
        $this->jsonAttempt(function () use ($id) {
            Memory::delete(Auth::id(), (int) $id);
            return [];
        });
    }

    public function deleteAll(): void
    {
        $n = Memory::deleteAll(Auth::id());
        DB::run('DELETE FROM ia_history WHERE user_id = ?', [Auth::id()]);
        Audit::log('ai.memory_delete_all', null, null, ['conversations' => $n]);
        if (Request::wantsJson()) {
            json_response(['ok' => true, 'deleted' => $n]);
        }
        flash('success', t('Toutes vos conversations avec MARWA AI ont été effacées.'));
        redirect('/ia');
    }

    public function feedback(): void
    {
        $this->jsonAttempt(function () {
            $in = Request::json();
            Learning::feedback((int) ($in['message_id'] ?? 0), (int) ($in['rating'] ?? 0), isset($in['comment']) ? (string) $in['comment'] : null);
            return [];
        });
    }

    public function confirm(int $id): void
    {
        $this->jsonAttempt(function () use ($id) {
            $in = Request::json();
            $secret = isset($in['secret']) && $in['secret'] !== '' ? (string) $in['secret'] : null;
            return Actions::confirm((int) $id, (string) ($in['token'] ?? ''), $secret, UserContext::current()); // AUTH_REQUIRED → code renvoyé au navigateur
        });
    }

    public function cancelAction(int $id): void
    {
        $this->jsonAttempt(function () use ($id) {
            Actions::cancel((int) $id, UserContext::current());
            return [];
        });
    }

    // ------------------------------------------------------------------ administration

    public function admin(): void
    {
        Learning::seed();
        $since = date('Y-m-d', strtotime('-30 days'));
        view('marwa_ia::admin/index', ['title' => 'MARWA AI — pilotage', 'dash' => Learning::dashboard(), 'index' => AppMap::stats(),
            'usage' => DB::all('SELECT m.name, m.model, COUNT(u.id) n, SUM(u.ok) ok, COALESCE(SUM(u.input_tokens),0) tin, COALESCE(SUM(u.output_tokens),0) tout, COALESCE(SUM(u.cost),0) cost
                FROM ai_usage_logs u JOIN ai_model_settings m ON m.id = u.model_setting_id WHERE u.created_at >= ? GROUP BY m.id, m.name, m.model ORDER BY n DESC', [$since]),
            'unresolved' => DB::all("SELECT id, question, intent_guess intent, confidence, outcome, created_at FROM ai_learning_logs WHERE outcome IN ('not_understood','fallback') AND resolved = 0 ORDER BY id DESC LIMIT 40"),
            'feedback' => DB::all('SELECT f.rating, f.comment, f.created_at, m.content, m.intent FROM ai_feedback f JOIN ai_messages m ON m.id = f.message_id WHERE f.rating < 0 ORDER BY f.id DESC LIMIT 20'),
            'actions' => DB::all('SELECT a.id, a.tool_code tool, a.status, a.summary, a.created_at, u.username FROM ai_action_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 20'),
            'intents' => self::intentOptions(), 'bench' => null]);
    }

    public function bench(): void
    {
        Learning::seed();
        Nlu::reset();
        $res = Learning::bench(Learning::BENCH);
        $ok = count(array_filter($res, fn($r) => $r['ok']));
        Audit::log('ai.bench', null, null, ['ok' => $ok, 'total' => count($res)]);
        $since = date('Y-m-d', strtotime('-30 days'));
        view('marwa_ia::admin/index', ['title' => 'MARWA AI — pilotage', 'dash' => Learning::dashboard(), 'index' => AppMap::stats(), 'usage' => [], 'unresolved' => [], 'feedback' => [], 'actions' => [],
            'intents' => self::intentOptions(), 'bench' => ['results' => $res, 'ok' => $ok, 'total' => count($res), 'since' => $since]]);
    }

    public function teach(int $id): void
    {
        $this->attempt(fn() => Learning::teach((int) $id, Request::str('intent')), '/ia/admin');
        flash('success', t('Exemple validé : la question est désormais rattachée à cette intention.'));
        redirect('/ia/admin');
    }

    public function intents(): void
    {
        Learning::seed();
        view('marwa_ia::admin/intents', ['title' => 'MARWA AI — intentions', 'intents' => DB::all('SELECT i.*, (SELECT COUNT(*) FROM ai_intent_examples e WHERE e.intent_code = i.code) examples,
            (SELECT COUNT(*) FROM ai_intent_examples e WHERE e.intent_code = i.code AND e.source <> \'builtin\') custom FROM ai_intents i ORDER BY i.module, i.label'),
            'custom' => DB::all("SELECT id, intent_code, text, source, created_at FROM ai_intent_examples WHERE source <> 'builtin' ORDER BY id DESC LIMIT 100"),
            'tools' => DB::all('SELECT code, label, kind, permission, is_sensitive, needs_confirmation FROM ai_tool_registry ORDER BY kind, code')]);
    }

    public function addExample(): void
    {
        $this->attempt(fn() => Learning::addExample(Request::str('intent'), Request::str('text')), '/ia/admin/intentions');
        flash('success', t('Exemple ajouté. Il est utilisé dès la prochaine question.'));
        redirect('/ia/admin/intentions');
    }

    public function deleteExample(int $id): void
    {
        DB::run("DELETE FROM ai_intent_examples WHERE id = ? AND source <> 'builtin'", [(int) $id]);
        Audit::log('ai.example_delete', 'ai_intent_example', (int) $id);
        flash('success', t('Exemple supprimé.'));
        redirect('/ia/admin/intentions');
    }

    public function knowledge(): void
    {
        Learning::seed();
        $edit = ($id = (int) Request::query('id', '0')) ? DB::one("SELECT * FROM ai_knowledge_base WHERE id = ? AND source <> 'builtin'", [$id]) : null;
        view('marwa_ia::admin/knowledge', ['title' => 'MARWA AI — connaissances', 'edit' => $edit,
            'items' => DB::all('SELECT id, kind, title, module, permission, source, active, updated_at, created_at FROM ai_knowledge_base ORDER BY source = \'builtin\', id DESC LIMIT 300')]);
    }

    public function knowledgeSave(): void
    {
        $id = (int) Request::input('id', '0') ?: null;
        $this->attempt(fn() => Learning::saveKnowledge($_POST, $id), '/ia/admin/connaissances');
        flash('success', t('Fiche de connaissance enregistrée.'));
        redirect('/ia/admin/connaissances');
    }

    public function models(): void
    {
        $edit = ($id = (int) Request::query('id', '0')) ? Providers::get($id) : null;
        view('marwa_ia::admin/models', ['title' => 'MARWA AI — modèles', 'models' => Providers::all(), 'edit' => $edit, 'providers' => Providers::PROVIDERS,
            'usage' => DB::all('SELECT model_setting_id, COUNT(*) n, COALESCE(SUM(input_tokens),0) tin, COALESCE(SUM(output_tokens),0) tout, COALESCE(SUM(cost),0) cost FROM ai_usage_logs WHERE created_at >= ? GROUP BY model_setting_id', [date('Y-m-01')])]);
    }

    public function modelSave(): void
    {
        $id = (int) Request::input('id', '0') ?: null;
        $id = $this->attempt(fn() => Providers::save($_POST, $id), '/ia/admin/modeles' . ($id ? '?id=' . $id : ''));
        flash('success', t('Modèle enregistré. La clé d\'API est chiffrée et n\'est jamais réaffichée.'));
        redirect('/ia/admin/modeles');
    }

    public function modelRole(int $id): void
    {
        $this->attempt(fn() => Providers::setRole((int) $id, Request::str('role')), '/ia/admin/modeles');
        flash('success', t('Rôle du modèle mis à jour.'));
        redirect('/ia/admin/modeles');
    }

    public function modelTest(int $id): void
    {
        $r = $this->attempt(fn() => Providers::test((int) $id), '/ia/admin/modeles');
        flash('success', t('Le modèle a répondu') . ' : « ' . mb_substr((string) $r, 0, 200) . ' »');
        redirect('/ia/admin/modeles');
    }

    public function dictionary(): void
    {
        view('marwa_ia::admin/dictionary', ['title' => 'MARWA AI — dictionnaire de données', 'schema' => DataDictionary::schema(), 'index' => AppMap::stats(),
            'checked' => (int) setting('ai_index_checked_at', '0')]);
    }

    public function reindex(): void
    {
        AppMap::ensureFresh(true);
        Learning::seed(true);
        Nlu::reset();
        Audit::log('ai.reindex');
        flash('success', t('Connaissance de l\'application reconstruite (modules, menus, routes, droits, tables, documentation).'));
        redirect(Request::str('back') === 'dict' ? '/ia/admin/dictionnaire' : '/ia/admin');
    }

    /** Liste des intentions pour les menus (code => libellé). */
    private static function intentOptions(): array
    {
        $o = [];
        foreach (Catalog::TOOLS as $k => $d) {
            $o[$k] = $d[0];
        }
        asort($o);
        return $o;
    }
}
