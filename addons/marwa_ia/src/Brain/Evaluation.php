<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

use Addons\MarwaIa\Text;
use App\Core\Auth;
use App\Core\DB;

/**
 * MARWA AI LEARNING & EVALUATION ENGINE — banc de tests exécutable.
 *
 * Chaque scénario est rejoué avec le compte de son rôle, par Brain::analyze (même compréhension et mêmes lectures que
 * la vraie conversation, sans rien enregistrer). Pour chaque formulation on contrôle :
 *   - l'intention reconnue (et les intentions secondaires d'une question multiple) ;
 *   - les paramètres extraits (période, boutique / magasin, article, client, quantité, nombre) ;
 *   - l'issue : réponse, clarification, refus (droits, sécurité, hors périmètre), proposition d'action ;
 *   - le texte attendu, et pour certains scénarios le chiffre recalculé directement en SQL.
 * Les formulations « vues » (données au classifieur) et « jamais vues » (fautes, autres tournures) sont comptées à part :
 * seul le second chiffre dit si l'assistant généralise.
 */
final class Evaluation
{
    /** Comptes de la base de démonstration utilisés pour jouer les rôles. */
    public const ROLES = ['admin' => 'admin', 'gerant' => 'gerant', 'caissier' => 'caissier', 'magasin' => 'magasin'];

    private static array $users = [];
    private static array $stateCache = [];

    /**
     * Contexte de droits du compte qui joue le rôle. En ligne de commande, le compte est réellement connecté (le moteur
     * 1.x lit aussi ses droits) ; depuis l'interface, la session de l'administrateur n'est jamais modifiée.
     */
    private static function context(string $role): ?UserContext
    {
        if (!array_key_exists($role, self::$users)) {
            self::$users[$role] = DB::one('SELECT * FROM users WHERE username = ? AND active = 1', [$role]) ?: null;
        }
        if (!self::$users[$role]) {
            return null;
        }
        if (PHP_SAPI === 'cli') {
            Auth::login(self::$users[$role]);
            Auth::reset();
            UserContext::reset();
            return UserContext::current();
        }
        return UserContext::forUser(self::$users[$role]);
    }

    /** Période attendue (dates) pour un mot-clé du jeu de tests. */
    public static function period(string $key): ?array
    {
        $phrase = ['today' => 'aujourd\'hui', 'yesterday' => 'hier', 'week' => 'cette semaine', 'last_week' => 'la semaine dernière', 'month' => 'ce mois', 'last_month' => 'le mois dernier', 'year' => 'cette année'][$key] ?? null;
        if ($phrase === null) {
            return preg_match('/^(19|20)\d{2}$/', $key) ? ['from' => $key . '-01-01', 'to' => $key . '-12-31'] : null;
        }
        return Text::period($phrase);
    }

    private static function sameText(string $got, string $want): bool
    {
        return str_contains(Text::norm($got), Text::norm($want));
    }

    /** Vérifie une analyse contre les attendus d'un scénario. @return string[] écarts (vide = réussi) */
    public static function check(array $s, array $a): array
    {
        $e = $s['expect'];
        $err = [];
        $want = $s['intent'];
        $also = isset($e['also']) ? array_map('trim', explode(',', $e['also'])) : [];
        $got = $a['intent'];
        // « définition » et « notion » sont une même demande d'explication ; un rapport du moteur 1.x reste un rapport
        $norm = fn(?string $i) => $i === null ? null : (str_starts_with($i, 'legacy:') ? 'report' : ($i === 'definition' ? 'concept' : $i));
        $want = $norm($want);
        if ($also) {
            $seen = array_map($norm, array_merge($a['intents'], [$got]));
            foreach (array_merge([$want], $also) as $w) {
                if (!in_array($w, $seen, true)) {
                    $err[] = 'intention « ' . $w . ' » non traitée (obtenu : ' . implode(', ', array_unique(array_filter($seen))) . ')';
                }
            }
        } elseif ($norm($got) !== $want) {
            $err[] = 'intention : attendu ' . $want . ', obtenu ' . ($got ?? 'aucune');
        }
        $out = $e['out'] ?? Dataset::defaultOutcome($want);
        $gotOut = $a['outcome'] ?? 'unknown';
        // Une action bloquée par l'état des données (caisse fermée, inventaire déjà ouvert) reste une demande bien comprise
        $okOut = $gotOut === $out || ($out === 'propose' && in_array($gotOut, ['blocked'], true) && ($e['strict'] ?? '') !== 'yes') || ($out === 'answer' && $gotOut === 'propose');
        if (!$okOut) {
            $err[] = 'issue : attendu ' . $out . ', obtenu ' . $gotOut;
        }
        $sl = $a['slots'];
        foreach (['per' => 'period', 'per2' => 'period2'] as $k => $slot) {
            if (isset($e[$k])) {
                $p = self::period($e[$k]);
                $g = $sl[$slot] ?? null;
                if (!$p || !$g || $g['from'] !== $p['from'] || $g['to'] !== $p['to']) {
                    $err[] = 'période ' . $e[$k] . ' : obtenu ' . ($g ? $g['from'] . '→' . $g['to'] : 'aucune');
                }
            }
        }
        foreach (['loc' => 'location', 'xloc' => 'exclude', 'prod' => 'product', 'cust' => 'customer', 'sup' => 'supplier', 'user' => 'user'] as $k => $slot) {
            if (isset($e[$k]) && !self::sameText((string) ($sl[$slot] ?? ''), $e[$k])) {
                $err[] = $slot . ' : attendu « ' . $e[$k] . ' », obtenu « ' . ($sl[$slot] ?? '') . ' »';
            }
        }
        if (isset($e['qty']) && abs((float) ($sl['quantity'] ?? $sl['amount'] ?? -1) - (float) $e['qty']) > 0.0001) {
            $err[] = 'quantité : attendu ' . $e['qty'] . ', obtenu ' . ($sl['quantity'] ?? $sl['amount'] ?? 'aucune');
        }
        if (isset($e['lim']) && (int) ($sl['limit'] ?? 0) !== (int) $e['lim']) {
            $err[] = 'nombre : attendu ' . $e['lim'] . ', obtenu ' . ($sl['limit'] ?? 'aucun');
        }
        if (isset($e['has'])) {
            $hay = $a['text'] . ' ' . json_encode($a['blocks'] ?? [], JSON_UNESCAPED_UNICODE);
            foreach (explode('&&', $e['has']) as $h) {
                if (!self::sameText($hay, trim($h))) {
                    $err[] = 'texte attendu absent : « ' . trim($h) . ' »';
                }
            }
        }
        if (isset($e['not'])) {
            foreach (explode('&&', $e['not']) as $h) {
                if (self::sameText($a['text'], trim($h))) {
                    $err[] = 'texte interdit présent : « ' . trim($h) . ' »';
                }
            }
        }
        if (isset($e['db']) && ($v = self::reference($e['db'], $s)) !== null) {
            $hay = $a['text'] . ' ' . json_encode($a['blocks'] ?? [], JSON_UNESCAPED_UNICODE);
            if (!str_contains($hay, $v)) {
                $err[] = 'chiffre recalculé en SQL absent : ' . $v . ' (' . $e['db'] . ')';
            }
        }
        return $err;
    }

    /** Valeur de référence recalculée directement en SQL, sans passer par les outils de l'assistant. */
    public static function reference(string $key, array $s): ?string
    {
        $d0 = date('Y-m-d 00:00:00');
        $d1 = date('Y-m-d 23:59:59');
        $sales = fn(string $a, string $b) => DB::one("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM sales WHERE status <> 'cancelled' AND created_at BETWEEN ? AND ?", [$a, $b]);
        switch ($key) {
            case 'stock_value':
                return money(round((float) DB::value('SELECT COALESCE(SUM(l.quantity * p.cost_price),0) FROM stock_levels l JOIN products p ON p.id = l.product_id WHERE p.track_stock = 1 AND l.quantity > 0'), 2));
            case 'sales_today':
                return money(round((float) $sales($d0, $d1)['t'], 2));
            case 'sales_yesterday':
                return money(round((float) $sales(date('Y-m-d 00:00:00', strtotime('-1 day')), date('Y-m-d 23:59:59', strtotime('-1 day')))['t'], 2));
            case 'sales_month':
                return money(round((float) $sales(date('Y-m-01 00:00:00'), $d1)['t'], 2));
            case 'debts':
                return money(round((float) DB::value('SELECT COALESCE(SUM(balance),0) FROM customers WHERE active = 1 AND balance > 0'), 2));
            case 'payables':
                return money(round((float) DB::value('SELECT COALESCE(SUM(balance),0) FROM suppliers WHERE active = 1 AND balance > 0'), 2));
            case 'last_sale':
                return (string) DB::value('SELECT number FROM sales ORDER BY created_at DESC, id DESC LIMIT 1');
            case 'customers':
                return (string) (int) DB::value('SELECT COUNT(*) FROM customers WHERE active = 1');
        }
        return null;
    }

    /** État de conversation après le scénario $code (rejoué avec sa question d'origine et ses propres prédécesseurs). */
    private static function stateAfter(string $code, array $byCode, UserContext $u, string $role): array
    {
        $key = $role . '|' . $code;
        if (isset(self::$stateCache[$key])) {
            return self::$stateCache[$key];
        }
        $s = $byCode[$code] ?? null;
        if (!$s) {
            return [];
        }
        $prev = isset($s['expect']['ctx']) ? self::stateAfter($s['expect']['ctx'], $byCode, $u, $role) : [];
        return self::$stateCache[$key] = Brain::analyze($s['question'], $prev, $u)['state'];
    }

    /**
     * Rejoue les scénarios. $filter : domaine (texte) ou null pour tout ; $limit : nombre maximal de scénarios.
     * @return array{summary: array, domains: array, failures: array, scenarios: int, phrases: int}
     */
    public static function run(?string $filter = null, ?int $limit = null, bool $save = true, ?string $label = null): array
    {
        $t0 = microtime(true);
        $me = Auth::user();
        Learning::seed();
        self::$stateCache = [];
        $all = Dataset::all();
        $byCode = [];
        foreach ($all as $s) {
            $byCode[$s['code']] = $s;
        }
        $list = $filter ? array_values(array_filter($all, fn($s) => stripos($s['domain'], $filter) !== false || stripos($s['code'], $filter) === 0)) : $all;
        if ($limit) {
            $list = array_slice($list, 0, $limit);
        }
        $tot = ['phrases' => 0, 'pass' => 0, 'intent_ok' => 0, 'slot_n' => 0, 'slot_ok' => 0, 'out_ok' => 0, 'clar_n' => 0, 'clar_ok' => 0, 'refuse_n' => 0, 'refuse_ok' => 0, 'unknown' => 0, 'errors' => 0, 'ms' => 0.0, 'ms_max' => 0.0,
            'seen_n' => 0, 'seen_ok' => 0, 'held_n' => 0, 'held_ok' => 0, 'scen_ok' => 0, 'skipped' => 0];
        $dom = [];
        $fail = [];
        foreach ($list as $s) {
            $u = self::context(self::ROLES[$s['role']] ?? $s['role']);
            if (!$u || (PHP_SAPI !== 'cli' && $me && $u->id() !== (int) $me['id'] && in_array($s['intent'], ['report', 'training', 'marketing', 'checkup', 'advice_topic'], true))) {
                $tot['skipped']++;
                continue;
            }
            $state = isset($s['expect']['ctx']) ? self::stateAfter($s['expect']['ctx'], $byCode, $u, $s['role']) : [];
            $seenKind = isset($s['expect']['ctx']) || isset($s['expect']['also']) || ($s['expect']['train'] ?? '') === 'no' ? 'held' : 'seen'; // ces questions ne sont pas données au classifieur
            $phr = array_merge([[$s['question'], $seenKind]], array_map(fn($x) => [$x, $seenKind], $s['train']), array_map(fn($x) => [$x, 'held'], $s['test']));
            $allOk = true;
            $d = &$dom[$s['domain']];
            $d ??= ['scenarios' => 0, 'phrases' => 0, 'pass' => 0, 'held_n' => 0, 'held_ok' => 0];
            $d['scenarios']++;
            $wantOut = $s['expect']['out'] ?? Dataset::defaultOutcome($s['intent']);
            $hasSlots = (bool) array_intersect_key($s['expect'], array_flip(['per', 'per2', 'loc', 'xloc', 'prod', 'cust', 'sup', 'qty', 'lim', 'user']));
            foreach ($phr as [$q, $kind]) {
                $a = Brain::analyze($q, $state, $u);
                $err = self::check($s, $a);
                $ok = !$err;
                $tot['phrases']++;
                $d['phrases']++;
                $tot['ms'] += $a['ms'];
                $tot['ms_max'] = max($tot['ms_max'], $a['ms']);
                $intentErr = (bool) array_filter($err, fn($x) => str_starts_with($x, 'intention'));
                $slotErr = (bool) array_filter($err, fn($x) => preg_match('/^(période|location|exclude|product|customer|supplier|user|quantité|nombre)/u', $x));
                $outErr = (bool) array_filter($err, fn($x) => str_starts_with($x, 'issue'));
                $tot['intent_ok'] += $intentErr ? 0 : 1;
                $tot['out_ok'] += $outErr ? 0 : 1;
                if ($hasSlots) {
                    $tot['slot_n']++;
                    $tot['slot_ok'] += $slotErr ? 0 : 1;
                }
                if ($wantOut === 'clarify') {
                    $tot['clar_n']++;
                    $tot['clar_ok'] += ($a['outcome'] ?? '') === 'clarify' ? 1 : 0;
                }
                if ($wantOut === 'refuse') {
                    $tot['refuse_n']++;
                    $tot['refuse_ok'] += ($a['outcome'] ?? '') === 'refuse' ? 1 : 0;
                }
                $tot['unknown'] += ($a['outcome'] ?? 'unknown') === 'unknown' ? 1 : 0;
                $tot['errors'] += ($a['outcome'] ?? '') === 'error' ? 1 : 0;
                $tot[$kind . '_n']++;
                if ($kind === 'held') {
                    $d['held_n']++;
                }
                if ($ok) {
                    $tot['pass']++;
                    $d['pass']++;
                    $tot[$kind . '_ok']++;
                    if ($kind === 'held') {
                        $d['held_ok']++;
                    }
                } else {
                    $allOk = false;
                    if (count($fail) < 400) {
                        $fail[] = ['code' => $s['code'], 'domain' => $s['domain'], 'role' => $s['role'], 'kind' => $kind, 'q' => $q, 'expected' => $s['intent'], 'got' => $a['intent'], 'outcome' => $a['outcome'], 'why' => $err];
                    }
                }
            }
            unset($d);
            $tot['scen_ok'] += $allOk ? 1 : 0;
        }
        if ($me && PHP_SAPI === 'cli') {
            Auth::login($me);
            Auth::reset();
            UserContext::reset();
        }
        $pc = fn(int $a, int $b) => $b ? round($a / $b * 100, 1) : null;
        $n = $tot['phrases'];
        $summary = ['scenarios' => count($list) - $tot['skipped'], 'scenarios_ok' => $tot['scen_ok'], 'phrases' => $n, 'passed' => $tot['pass'], 'pass_rate' => $pc($tot['pass'], $n),
            'intent_rate' => $pc($tot['intent_ok'], $n), 'slot_rate' => $pc($tot['slot_ok'], $tot['slot_n']), 'slot_phrases' => $tot['slot_n'], 'outcome_rate' => $pc($tot['out_ok'], $n),
            'clarify_rate' => $pc($tot['clar_ok'], $tot['clar_n']), 'clarify_phrases' => $tot['clar_n'], 'refuse_rate' => $pc($tot['refuse_ok'], $tot['refuse_n']), 'refuse_phrases' => $tot['refuse_n'],
            'seen_rate' => $pc($tot['seen_ok'], $tot['seen_n']), 'seen_phrases' => $tot['seen_n'], 'held_rate' => $pc($tot['held_ok'], $tot['held_n']), 'held_phrases' => $tot['held_n'],
            'error_rate' => $pc($n - $tot['pass'], $n), 'not_understood' => $tot['unknown'], 'technical_errors' => $tot['errors'], 'avg_ms' => $n ? round($tot['ms'] / $n, 1) : null, 'max_ms' => round($tot['ms_max'], 1),
            'skipped' => $tot['skipped'], 'duration_s' => round(microtime(true) - $t0, 1), 'library' => Nlu::stats()];
        ksort($dom);
        if ($save) {
            try {
                DB::insert('ai_eval_runs', ['label' => $label, 'scope' => $filter ?: 'tout', 'scenarios' => $summary['scenarios'], 'phrases' => $n, 'passed' => $tot['pass'],
                    'metrics_json' => json_encode(['summary' => $summary, 'domains' => $dom], JSON_UNESCAPED_UNICODE), 'failures_json' => json_encode(array_slice($fail, 0, 200), JSON_UNESCAPED_UNICODE),
                    'version' => Learning::SEED_VERSION, 'duration_ms' => (int) round((microtime(true) - $t0) * 1000), 'user_id' => $me['id'] ?? null]);
            } catch (\Throwable) {
            }
        }
        return ['summary' => $summary, 'domains' => $dom, 'failures' => $fail, 'scenarios' => $summary['scenarios'], 'phrases' => $n];
    }
}
