<?php
declare(strict_types=1);

namespace Addons\MarwaIa;

use Addons\MarwaIa\Brain\Brain;
use Addons\MarwaIa\Brain\Dataset;
use Addons\MarwaIa\Brain\Evaluation;
use Addons\MarwaIa\Brain\Learning;
use Addons\MarwaIa\Brain\Nlu;
use Addons\MarwaIa\Brain\Recover;
use Addons\MarwaIa\Brain\UserContext;
use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Request;

/**
 * MARWA AI TRAINING CENTER : bibliothèque de scénarios, synonymes, questions incomprises, corrections des utilisateurs,
 * tests automatiques, performance et historique des améliorations. Tout se fait sans toucher au code source.
 * Réservé au droit « ia.manage ».
 */
final class TrainingController extends Controller
{
    // ------------------------------------------------------------------ bibliothèque de scénarios

    public function scenarios(): void
    {
        Learning::seed();
        $domain = Request::query('domaine', '');
        $intent = Request::query('intention', '');
        $q = trim((string) Request::query('q', ''));
        $w = ['1 = 1'];
        $p = [];
        if ($domain !== '') {
            $w[] = 'domain = ?';
            $p[] = $domain;
        }
        if ($intent !== '') {
            $w[] = 'intent = ?';
            $p[] = $intent;
        }
        if ($q !== '') {
            $w[] = '(question LIKE ? OR code LIKE ? OR train_json LIKE ? OR test_json LIKE ?)';
            array_push($p, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        $total = (int) DB::value('SELECT COUNT(*) FROM ai_scenarios WHERE ' . implode(' AND ', $w), $p);
        $page = max(1, (int) Request::query('page', '1'));
        $per = 50;
        $rows = DB::all('SELECT * FROM ai_scenarios WHERE ' . implode(' AND ', $w) . ' ORDER BY source = \'builtin\', id LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $p);
        $show = null;
        if ($code = (string) Request::query('voir', '')) {
            foreach (Dataset::all() as $s) {
                if ($s['code'] === $code) {
                    $show = Dataset::expand($s);
                    break;
                }
            }
        }
        view('marwa_ia::admin/scenarios', ['title' => 'MARWA AI — bibliothèque de scénarios', 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / $per), 'show' => $show,
            'domains' => DB::all('SELECT domain, COUNT(*) n FROM ai_scenarios GROUP BY domain ORDER BY domain'), 'intents' => array_column(DB::all('SELECT DISTINCT intent FROM ai_scenarios ORDER BY intent'), 'intent'),
            'filters' => ['domaine' => $domain, 'intention' => $intent, 'q' => $q], 'all' => (int) DB::value('SELECT COUNT(*) FROM ai_scenarios'), 'codes' => self::intentOptions()]);
    }

    public function scenarioAdd(): void
    {
        $code = $this->attempt(fn() => Dataset::add($_POST), '/ia/admin/scenarios');
        Audit::log('ai.scenario_add', 'ai_scenario', null, ['code' => $code]);
        flash('success', t('Scénario') . ' ' . $code . ' ' . t('ajouté : ses formulations d\'entraînement sont utilisées dès la prochaine question.'));
        redirect('/ia/admin/scenarios?voir=' . $code);
    }

    public function scenarioDelete(int $id): void
    {
        $s = DB::one("SELECT code FROM ai_scenarios WHERE id = ? AND source <> 'builtin'", [$id]);
        if ($s) {
            DB::run('DELETE FROM ai_scenarios WHERE id = ?', [$id]);
            Nlu::reset();
            Learning::version('scenario', $s['code'], 'Scénario supprimé');
            Audit::log('ai.scenario_delete', 'ai_scenario', $id, ['code' => $s['code']]);
            flash('success', t('Scénario supprimé.'));
        }
        redirect('/ia/admin/scenarios');
    }

    public function scenariosExport(): void
    {
        $data = array_map([Dataset::class, 'expand'], Dataset::all());
        Audit::log('ai.scenario_export', null, null, ['count' => count($data)]);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="marwa_ai_scenarios_' . date('Ymd') . '.json"');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // ------------------------------------------------------------------ synonymes

    public function synonyms(): void
    {
        view('marwa_ia::admin/synonyms', ['title' => 'MARWA AI — synonymes', 'rows' => DB::all('SELECT s.*, u.full_name FROM ai_synonyms s LEFT JOIN users u ON u.id = s.created_by ORDER BY s.id DESC'),
            'builtin' => count(Nlu::synonymKeys()), 'abbr' => count(Recover::ABBR)]);
    }

    public function synonymAdd(): void
    {
        $this->attempt(function () {
            $term = Text::norm(Request::str('term'));
            $canon = Text::norm(Request::str('canonical'));
            if (mb_strlen($term) < 2 || mb_strlen($canon) < 2 || str_contains($term, ' ')) {
                throw new BusinessException('Indiquez un mot (sans espace) et le mot du logiciel auquel il correspond.');
            }
            if (!isset(Nlu::vocabulary()[$canon]) && !isset(Nlu::vocabulary()[explode(' ', $canon)[0]])) {
                throw new BusinessException('« ' . $canon . ' » n\'est pas un mot connu de l\'assistant : choisissez un mot du métier (ex. stock, vente, client, depense, benefice).');
            }
            DB::run('INSERT INTO ai_synonyms (term, canonical, note, created_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE canonical = VALUES(canonical), note = VALUES(note), active = 1',
                [mb_substr($term, 0, 80), mb_substr($canon, 0, 80), mb_substr(Request::str('note'), 0, 255) ?: null, Auth::id()]);
            Nlu::reset();
            Learning::version('synonyme', $term, 'Synonyme ajouté : « ' . $term . ' » se comprend comme « ' . $canon . ' »');
            Audit::log('ai.synonym_add', null, null, ['term' => $term, 'canonical' => $canon]);
        }, '/ia/admin/synonymes');
        flash('success', t('Synonyme enregistré : il est utilisé dès la prochaine question.'));
        redirect('/ia/admin/synonymes');
    }

    public function synonymDelete(int $id): void
    {
        $s = DB::one('SELECT term FROM ai_synonyms WHERE id = ?', [$id]);
        if ($s) {
            DB::run('DELETE FROM ai_synonyms WHERE id = ?', [$id]);
            Nlu::reset();
            Learning::version('synonyme', $s['term'], 'Synonyme supprimé');
            Audit::log('ai.synonym_delete', null, $id, ['term' => $s['term']]);
            flash('success', t('Synonyme supprimé.'));
        }
        redirect('/ia/admin/synonymes');
    }

    // ------------------------------------------------------------------ intentions (activation, intention ajoutée)

    public function intentToggle(): void
    {
        $code = Request::str('code');
        $on = Request::input('active') === '1' ? 1 : 0;
        if (DB::value('SELECT 1 FROM ai_intents WHERE code = ?', [$code])) {
            DB::update('ai_intents', ['active' => $on], 'code = ?', [$code]);
            Nlu::reset();
            Learning::version('intention', $code, $on ? 'Intention réactivée' : 'Intention désactivée');
            Audit::log('ai.intent_toggle', null, null, ['code' => $code, 'active' => $on]);
            flash('success', $on ? t('Intention réactivée.') : t('Intention désactivée : l\'assistant ne la proposera plus.'));
        }
        redirect('/ia/admin/intentions');
    }

    /** Intention ajoutée par l'administrateur : une réponse rédigée, déclenchée par ses exemples (aucun accès aux données). */
    public function intentAdd(): void
    {
        $this->attempt(function () {
            $label = trim(Request::str('label'));
            $answer = trim(Request::str('answer'));
            $examples = array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/', Request::str('examples'))), fn($x) => mb_strlen($x) >= 4));
            if (mb_strlen($label) < 3 || mb_strlen($answer) < 10 || count($examples) < 2) {
                throw new BusinessException('Indiquez un libellé, une réponse (10 caractères au moins) et au moins deux exemples de questions.');
            }
            $code = 'perso_' . substr(preg_replace('/[^a-z0-9]+/', '_', Text::norm($label)), 0, 40);
            if (DB::value('SELECT 1 FROM ai_intents WHERE code = ?', [$code])) {
                throw new BusinessException('Une intention porte déjà ce libellé.');
            }
            DB::insert('ai_intents', ['code' => $code, 'label' => mb_substr($label, 0, 160), 'module' => 'personnalisé', 'tool' => 'réponse rédigée', 'description' => mb_substr($answer, 0, 4000), 'builtin' => 0, 'active' => 1]);
            foreach (array_slice($examples, 0, 30) as $ex) {
                DB::run("INSERT IGNORE INTO ai_intent_examples (intent_code, text, normalized, source, validated_by) VALUES (?, ?, ?, 'admin', ?)", [$code, mb_substr($ex, 0, 300), mb_substr(Text::norm($ex), 0, 300), Auth::id()]);
            }
            Nlu::reset();
            Learning::version('intention', $code, 'Intention ajoutée : ' . $label . ' (' . count($examples) . ' exemples)');
            Audit::log('ai.intent_add', null, null, ['code' => $code]);
        }, '/ia/admin/intentions');
        flash('success', t('Intention ajoutée. Testez-la dans « Tests automatiques → Tester une formulation ».'));
        redirect('/ia/admin/intentions');
    }

    // ------------------------------------------------------------------ questions incomprises et corrections

    public function unresolved(): void
    {
        $since = date('Y-m-d', strtotime('-60 days'));
        view('marwa_ia::admin/unresolved', ['title' => 'MARWA AI — questions incomprises',
            'rows' => DB::all("SELECT id, question, intent_guess, confidence, outcome, created_at FROM ai_learning_logs WHERE (outcome IN ('not_understood','fallback','clarify') OR (outcome = 'refused' AND intent_guess = 'out_of_scope')) AND resolved = 0 ORDER BY id DESC LIMIT 150"),
            'frequent' => DB::all("SELECT normalized, COUNT(*) n, MAX(created_at) last_at, MAX(intent_guess) guess FROM ai_learning_logs WHERE created_at >= ? AND normalized <> '' GROUP BY normalized HAVING n >= 2 ORDER BY n DESC LIMIT 40", [$since]),
            'unrecognized' => DB::all("SELECT COALESCE(intent_guess, '—') k, COUNT(*) n FROM ai_learning_logs WHERE outcome = 'not_understood' AND created_at >= ? GROUP BY k ORDER BY n DESC LIMIT 15", [$since]),
            'intents' => self::intentOptions()]);
    }

    public function dismiss(int $id): void
    {
        DB::update('ai_learning_logs', ['resolved' => 1, 'resolution' => 'Écartée par un administrateur'], 'id = ?', [$id]);
        redirect('/ia/admin/incomprises');
    }

    public function corrections(): void
    {
        view('marwa_ia::admin/corrections', ['title' => 'MARWA AI — corrections des utilisateurs',
            'rows' => DB::all('SELECT f.id, f.rating, f.comment, f.status, f.created_at, m.content, m.intent, m.id mid,
                (SELECT q.content FROM ai_messages q WHERE q.conversation_id = m.conversation_id AND q.id < m.id AND q.role = \'user\' ORDER BY q.id DESC LIMIT 1) question
                FROM ai_feedback f JOIN ai_messages m ON m.id = f.message_id ORDER BY f.status = \'new\' DESC, f.id DESC LIMIT 150'),
            'stats' => DB::one('SELECT SUM(rating > 0) up, SUM(rating < 0) down, SUM(status = \'new\' AND rating < 0) todo FROM ai_feedback'), 'intents' => self::intentOptions()]);
    }

    /** Traite un avis : rattache la question à la bonne intention (nouvel exemple validé) ou le classe sans suite. */
    public function correctionHandle(int $id): void
    {
        $this->attempt(function () use ($id) {
            $f = DB::one('SELECT f.*, m.conversation_id, m.id mid FROM ai_feedback f JOIN ai_messages m ON m.id = f.message_id WHERE f.id = ?', [$id]);
            if (!$f) {
                throw new BusinessException('Avis introuvable.');
            }
            $intent = Request::str('intent');
            if ($intent !== '') {
                $q = (string) DB::value("SELECT content FROM ai_messages WHERE conversation_id = ? AND id < ? AND role = 'user' ORDER BY id DESC LIMIT 1", [$f['conversation_id'], $f['mid']]);
                // La question est anonymisée avant de devenir un exemple : aucune conversation brute n'entre dans l'entraînement
                Learning::addExample($intent, Learning::anonymize($q));
                Learning::version('correction', $intent, 'Correction validée à partir d\'un avis : nouvelle formulation rattachée à « ' . $intent . ' »');
            }
            DB::update('ai_feedback', ['status' => 'handled'], 'id = ?', [$id]);
            Audit::log('ai.feedback_handle', 'ai_feedback', $id, ['intent' => $intent ?: null]);
        }, '/ia/admin/corrections');
        flash('success', t('Avis traité.'));
        redirect('/ia/admin/corrections');
    }

    // ------------------------------------------------------------------ tests automatiques et performance

    public function tests(): void
    {
        Learning::seed();
        view('marwa_ia::admin/tests', ['title' => 'MARWA AI — tests automatiques', 'domains' => DB::all('SELECT domain, COUNT(*) n FROM ai_scenarios GROUP BY domain ORDER BY domain'), 'result' => null, 'probe' => null,
            'last' => DB::one('SELECT * FROM ai_eval_runs ORDER BY id DESC LIMIT 1'), 'roles' => array_keys(Evaluation::ROLES)]);
    }

    public function testsRun(): void
    {
        set_time_limit(600);
        session_write_close();
        $domain = Request::str('domaine');
        $r = Evaluation::run($domain !== '' ? $domain : null, null, true, 'centre d\'entraînement');
        Audit::log('ai.eval_run', null, null, ['scope' => $domain ?: 'tout', 'phrases' => $r['phrases'], 'pass_rate' => $r['summary']['pass_rate']]);
        view('marwa_ia::admin/tests', ['title' => 'MARWA AI — tests automatiques', 'domains' => DB::all('SELECT domain, COUNT(*) n FROM ai_scenarios GROUP BY domain ORDER BY domain'), 'result' => $r, 'probe' => null,
            'last' => DB::one('SELECT * FROM ai_eval_runs ORDER BY id DESC LIMIT 1'), 'roles' => array_keys(Evaluation::ROLES)]);
    }

    /** « Tester une formulation » : ce que l'assistant comprend, sans rien enregistrer. */
    public function probe(): void
    {
        $q = mb_substr(trim(Request::str('q')), 0, 500);
        $role = Request::str('role') ?: 'admin';
        $probe = null;
        if ($q !== '') {
            $u = DB::one('SELECT * FROM users WHERE username = ? AND active = 1', [Evaluation::ROLES[$role] ?? '']);
            $ctx = $u && (int) $u['id'] !== (int) Auth::id() ? UserContext::forUser($u) : UserContext::current();
            $a = Brain::analyze($q, [], $ctx);
            $c = Nlu::classify(Recover::clean($q)['text']);
            $probe = ['q' => $q, 'role' => $u ? $role : 'votre compte', 'a' => $a, 'top' => $c['top'], 'coverage' => $c['coverage'], 'tokens' => $c['tokens']];
        }
        view('marwa_ia::admin/tests', ['title' => 'MARWA AI — tests automatiques', 'domains' => DB::all('SELECT domain, COUNT(*) n FROM ai_scenarios GROUP BY domain ORDER BY domain'), 'result' => null, 'probe' => $probe,
            'last' => DB::one('SELECT * FROM ai_eval_runs ORDER BY id DESC LIMIT 1'), 'roles' => array_keys(Evaluation::ROLES)]);
    }

    public function performance(): void
    {
        $runs = DB::all('SELECT r.*, u.full_name FROM ai_eval_runs r LEFT JOIN users u ON u.id = r.user_id ORDER BY r.id DESC LIMIT 30');
        $detail = null;
        $id = (int) Request::query('run', '0') ?: (int) ($runs[0]['id'] ?? 0);
        foreach ($runs as $r) {
            if ((int) $r['id'] === $id) {
                $detail = ['run' => $r, 'metrics' => json_decode((string) $r['metrics_json'], true) ?: [], 'failures' => json_decode((string) $r['failures_json'], true) ?: []];
            }
        }
        $since = date('Y-m-d', strtotime('-30 days'));
        view('marwa_ia::admin/performance', ['title' => 'MARWA AI — performance', 'runs' => $runs, 'detail' => $detail, 'live' => Learning::dashboard(),
            'daily' => DB::all("SELECT DATE(created_at) d, COUNT(*) n, SUM(outcome IN ('answered','fallback')) ok, SUM(outcome = 'not_understood') ko FROM ai_learning_logs WHERE created_at >= ? GROUP BY d ORDER BY d", [$since]),
            'speed' => DB::one("SELECT ROUND(AVG(duration_ms)) avg_ms, MAX(duration_ms) max_ms, COUNT(*) n FROM ai_messages WHERE role = 'assistant' AND created_at >= ?", [$since])]);
    }

    public function history(): void
    {
        view('marwa_ia::admin/history', ['title' => 'MARWA AI — historique des améliorations',
            'rows' => DB::all('SELECT v.*, u.full_name FROM ai_knowledge_versions v LEFT JOIN users u ON u.id = v.user_id ORDER BY v.id DESC LIMIT 300'), 'seed' => Learning::SEED_VERSION, 'lib' => Nlu::stats()]);
    }

    /** Codes d'intention => libellés, pour les listes déroulantes. */
    public static function intentOptions(): array
    {
        $o = [];
        foreach (DB::all('SELECT code, label FROM ai_intents WHERE active = 1 ORDER BY label') as $r) {
            $o[$r['code']] = $r['label'];
        }
        return $o;
    }
}
