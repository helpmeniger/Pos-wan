<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

use Addons\MarwaIa\Advisor;
use Addons\MarwaIa\Checkup;
use Addons\MarwaIa\Engine;
use Addons\MarwaIa\Knowledge;
use Addons\MarwaIa\Marketing;
use Addons\MarwaIa\Scope;
use Addons\MarwaIa\Text;
use Addons\MarwaIa\Trainer;
use App\Core\BusinessException;

/**
 * Chef d'orchestre de MARWA AI : compréhension → droits → données / connaissance / action → réponse → mémoire.
 * Les étapes sont émises au fur et à mesure (streaming) ; les jetons du modèle IA, quand il est utilisé, sont relayés
 * dès qu'ils arrivent. Tout ce que le nouveau moteur ne traite pas est confié au moteur MARWA IA 1.x (rapports PDF,
 * marketing, formation, conseils, vérification de la configuration), qui est conservé.
 */
final class Brain
{
    /** Intention « de base » d'une comparaison, pour les questions de suite (« et le mois dernier ? »). */
    private const BASE = ['sales_compare' => 'sales_summary', 'sales_decline' => 'sales_summary', 'stock_by_location' => 'stock_value'];
    private const PERIOD_INTENTS = ['sales_summary', 'revenue', 'profit', 'expenses_total', 'cash_in', 'cash_out', 'top_products', 'sales_by_user', 'sales_by_store', 'sales_by_method',
        'sales_by_category', 'cancelled_sales', 'returns', 'discounts_tax', 'purchases_total', 'income_vs_expenses', 'daily_report', 'activity_summary', 'anomalies', 'top_cashier', 'epay_summary', 'fiscal_summary', 'transfers'];

    /** @var callable */
    private $emit;
    private UserContext $u;
    private array $conv;
    private array $state;
    private string $question;
    private int $seed;
    private array $out = ['text' => '', 'html' => null, 'blocks' => [], 'sources' => [], 'links' => [], 'suggestions' => [], 'intent' => null, 'confidence' => null, 'provider' => null, 'proposal' => null, 'open' => null, 'legacy' => null];

    /**
     * Traite une question. $emit(string $event, array $data) reçoit les événements de streaming.
     * @return array le message de l'assistant (enregistré en mémoire)
     */
    public static function handle(string $question, ?int $convId, callable $emit, ?string $page = null): array
    {
        Learning::seed();
        $b = new self();
        $b->emit = $emit;
        $b->u = UserContext::current();
        $b->conv = Memory::conversation($b->u->id(), $convId);
        $b->state = Memory::state($b->conv);
        $b->question = mb_substr(trim($question), 0, 500);
        $b->seed = (int) $b->conv['message_count'] + crc32($b->question);
        $t = microtime(true);
        Memory::add((int) $b->conv['id'], 'user', $b->question);
        $emit('meta', ['conversation_id' => (int) $b->conv['id'], 'title' => $b->conv['title'] === 'Nouvelle conversation' ? mb_substr($b->question, 0, 80) : $b->conv['title']]);
        try {
            $b->route($page);
        } catch (BusinessException $e) {
            $msg = preg_replace('/^AUTH_REQUIRED:/', '', $e->getMessage());
            $b->say($msg);
            Learning::log($b->question, $b->out['intent'], 0, 'refused');
        } catch (\Throwable $e) {
            \App\Core\Logger::error('marwa_ai', ['error' => $e->getMessage(), 'file' => basename($e->getFile()) . ':' . $e->getLine()]);
            $b->say('Je n\'ai pas pu terminer cette analyse (erreur technique). Aucun chiffre n\'est donc donné ; réessayez ou reformulez.');
            Learning::log($b->question, $b->out['intent'], 0, 'error');
        }
        Memory::saveState((int) $b->conv['id'], $b->state);
        $payload = ['blocks' => $b->out['blocks'], 'html' => $b->out['html'], 'links' => $b->out['links'], 'suggestions' => array_values(array_unique($b->out['suggestions'])), 'open' => $b->out['open'], 'proposal' => $b->out['proposal'] ? ['id' => $b->out['proposal']['id'], 'label' => $b->out['proposal']['label'], 'summary' => $b->out['proposal']['summary'], 'sensitive' => $b->out['proposal']['sensitive']] : null];
        $mid = Memory::add((int) $b->conv['id'], 'assistant', $b->out['text'] !== '' ? $b->out['text'] : strip_tags((string) $b->out['html']), [
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'intent' => $b->out['intent'], 'confidence' => $b->out['confidence'],
            'sources' => mb_substr(implode(',', array_unique($b->out['sources'])), 0, 500) ?: null, 'provider' => $b->out['provider'], 'duration_ms' => (int) round((microtime(true) - $t) * 1000)]);
        $emit('done', ['message_id' => $mid, 'sources' => array_values(array_unique($b->out['sources'])), 'links' => $b->out['links'], 'suggestions' => $payload['suggestions'], 'proposal' => $b->out['proposal']]);
        return ['message_id' => $mid, 'conversation_id' => (int) $b->conv['id']] + $b->out;
    }

    private function status(string $s): void
    {
        ($this->emit)('status', ['text' => $s]);
    }

    /** Texte de réponse (envoyé en une fois : il est calculé, pas généré). */
    private function say(string $text): void
    {
        $this->out['text'] .= ($this->out['text'] !== '' ? "\n\n" : '') . $text;
        ($this->emit)('text', ['text' => $text]);
    }

    private function block(string $type, array $data): void
    {
        $this->out['blocks'][] = ['type' => $type] + $data;
        ($this->emit)('block', ['type' => $type] + $data);
    }

    // ------------------------------------------------------------------ aiguillage

    private function route(?string $page): void
    {
        $q = $this->question;
        $n = Text::norm($q);
        if ($q === '') {
            $this->say('Posez-moi une question sur votre activité ou sur le logiciel, par exemple « valeur du stock », « bilan de la journée » ou « comment faire un inventaire ? ».');
            return;
        }
        // Hors domaine (garde-fou existant)
        if ($r = Scope::guard($n)) {
            $this->legacy($r);
            Learning::log($q, 'hors_domaine', 1, 'refused');
            return;
        }
        // Action demandée
        if ($a = Actions::detect($q, $this->u)) {
            $this->status('Je prépare l\'opération et vérifie vos droits…');
            $r = Actions::propose($a[0], $a[1], $this->u, (int) $this->conv['id'], $q);
            $this->out['intent'] = 'action_' . $a[0];
            $this->say($r['text']);
            if (!empty($r['link'])) {
                $this->out['links'][] = $r['link'];
            }
            $this->out['proposal'] = $r['proposal'];
            Learning::log($q, 'action_' . $a[0], 1, !empty($r['refused']) ? 'refused' : 'answered');
            return;
        }
        // Domaines du moteur 1.x conservés : rapports PDF / Excel, marketing, formation, vérification, conseils
        if (preg_match('/\b(pdf|excel|csv|imprime\w*|telecharg\w*)\b/', $n) || preg_match('/^rapport\b/', $n) || (Marketing::isMarketing($n) && !preg_match('/\b(valeur|combien|chiffre)\b/', $n))
            || Trainer::isTraining($n) || Checkup::isCheckup($n) || (Advisor::isAdvice($n) && !preg_match('/\b(combien|valeur|quel est|quelle est|pourquoi)\b/', $n))) {
            $this->legacyAsk($q);
            return;
        }
        if (preg_match('/\b(que sais tu faire|que peux tu faire|tu sais faire quoi|tu peux faire quoi|qui es tu|tu es qui|presente toi|tes (capacites|fonctionnalites))\b|^aide( moi)?$/', $n)) {
            $this->capabilities();
            return;
        }
        // Définitions (brut, net, TVA, marge…) et types de transactions
        if (preg_match('/\b(c est quoi|qu est ce que|que veut dire|que signifie|definition|difference entre|quelle difference|explique moi (le|la|les) (terme|notion|difference))\b/', $n) && ($ex = Transactions::explain($q))) {
            $this->out['intent'] = 'definition';
            $this->out['html'] = $ex['html'];
            ($this->emit)('html', ['html' => $ex['html']]);
            $this->out['text'] = strip_tags($ex['html']);
            Learning::log($q, 'definition', 1, 'answered');
            return;
        }
        if (preg_match('/\b(types? de (transactions?|mouvements?|operations?))\b/', $n)) {
            $this->say('Voici les types de mouvements que POS Marwa enregistre, avec leurs effets sur le stock, la caisse et les comptes.');
            $this->block('table', ['title' => 'Types de transactions'] + Transactions::listing());
            $this->out['intent'] = 'transaction_types';
            return;
        }
        // Questions d'aide / de procédure
        if (preg_match('/^(comment|explique|expliquez|aide moi|ou (se trouve|trouver|est)|ou puis je|que faire|quelle est la procedure|les etapes|pourquoi (je ne peux|ne puis|ca ne|le ticket ne|l imprimante))\b|\b(comment (on|je|faire|creer|ajouter|enregistrer|faire un|configurer|utiliser|annuler|cloturer|consulter))\b/', $n)
            && !preg_match('/\b(combien|valeur de mon|quel est le (solde|chiffre|benefice))\b/', $n)) {
            $this->help($q);
            return;
        }
        $this->data($q, $page);
    }

    // ------------------------------------------------------------------ données

    private function data(string $q, ?string $page): void
    {
        $parts = Nlu::split($q);
        $multi = count($parts) > 1 ? array_map(fn($p) => [$p, Nlu::classify($p)], $parts) : [];
        $confident = array_filter($multi, fn($x) => $x[1]['score'] >= Nlu::THRESHOLD);
        if (count($confident) > 1 && count(array_unique(array_map(fn($x) => $x[1]['intent'], $confident))) > 1) {
            $this->status('Votre question contient ' . count($confident) . ' demandes : je les traite une à une.');
            foreach ($confident as [$part, $c]) {
                $this->answerIntent($part, $c);
            }
            return;
        }
        $c = Nlu::classify($q);
        if (!$this->answerIntent($q, $c)) {
            $this->fallback($q, $c);
        }
    }

    /** Résout l'intention (avec le contexte) et répond. Faux si rien n'a été compris. */
    private function answerIntent(string $q, array $c): bool
    {
        $n = $c['norm'];
        $slots = Nlu::slots($q, $this->u);
        $prev = $this->state['intent'] ?? null;
        $intent = $c['score'] >= Nlu::THRESHOLD ? $c['intent'] : null;
        $startsEt = (bool) preg_match('/^(et|puis|pareil|meme chose|idem|aussi|alors|ok et|d accord et|mais)\b/', $n);
        $hasSlot = isset($slots['period']) || isset($slots['location']);
        $isFollow = $startsEt || (mb_strlen($n) <= 22 && $hasSlot);
        // Une comparaison suppose un mot de comparaison ou deux périodes ; sinon c'est un simple résumé
        if ($intent === 'sales_compare' && empty($slots['period2']) && !preg_match('/\b(compar\w*|vs|versus|par rapport|contre|evolution|evolue|progress\w*|difference|ecart|mieux que|plus que|moins que)\b/', $n)) {
            $intent = 'sales_summary';
        }
        // Score un peu faible mais période explicite et intention « de période » : on retient la meilleure hypothèse
        if (!$intent && $hasSlot && $c['score'] >= 0.3) {
            foreach ([[$c['intent'], $c['score']], [$c['second'] ?? null, $c['second_score'] ?? 0]] as [$cand, $sc]) {
                if ($cand && $sc >= 0.3 && (in_array($cand, self::PERIOD_INTENTS, true) || in_array($cand, ['last_sales', 'last_sale'], true))) {
                    $intent = $cand;
                    break;
                }
            }
        }
        // « Ventes du mois dernier » : une période, pas « les dernières ventes »
        if (in_array($intent, ['last_sales', 'last_sale'], true) && isset($slots['period']) && !preg_match('/\b(dernier|derniere|derniers|dernieres) (vente|ventes|ticket|tickets|transaction|transactions)\b|\b\d+ dernier/', $n)) {
            $intent = 'sales_summary';
        }
        // Question trop vague (« combien ? ») : on demande au lieu de deviner
        if (!($prev && $isFollow) && count(array_diff($c['tokens'], ['combien', 'quoi', 'total', 'montant', 'nombre'])) === 0) {
            $this->say('Combien de quoi ? Précisez par exemple : « combien de ventes aujourd\'hui ? », « combien vaut mon stock ? », « combien me doivent les clients ? ».');
            Learning::log($q, null, $c['score'], 'clarify');
            return true;
        }
        // Mention de tickets Wi-Fi / Marwa Connect
        if (preg_match('/\b(wifi|wi fi|hotspot|marwa connect|tickets? (internet|connexion))\b/', $n) || ($intent === 'wifi_tickets')) {
            $intent = 'wifi_tickets';
        }
        // Comparaison demandée sans périodes : les deux dernières périodes de la conversation
        if (($intent === 'sales_compare' || preg_match('/^(fais (la|une) )?(comparaison|compare|compares|comparer)\b/', $n)) && empty($slots['period2']) && count($this->state['periods'] ?? []) >= 2
            && in_array(self::BASE[$prev] ?? $prev, ['sales_summary', 'revenue'], true)) {
            $ps = array_slice($this->state['periods'], -2);
            usort($ps, fn($a, $b) => strcmp($b['to'], $a['to']));
            $slots['period'] = $ps[0];
            $slots['period2'] = $ps[1];
            $intent = 'sales_compare';
        }
        // Question de suite : on reprend la dernière intention et ses paramètres
        // Suite uniquement si la question s'y prête (« et hier ? », « et dans le dépôt ? ») : jamais pour une question sans rapport
        $follow = $prev && $isFollow && ($intent === null || $intent === $prev || ($c['score'] < 0.6 && $hasSlot));
        if ($follow && $intent !== 'sales_compare' && $intent !== 'wifi_tickets') {
            $base = (isset($slots['period']) && isset(self::BASE[$prev])) ? self::BASE[$prev] : $prev;
            $slots = array_merge($this->state['slots'] ?? [], array_filter($slots, fn($v) => $v !== null));
            if (isset($slots['period']) && !isset(Nlu::slots($q, $this->u)['period2'])) {
                unset($slots['period2']);
            }
            $intent = $base;
            $c['score'] = max($c['score'], 0.5);
        } elseif ($intent && $prev === $intent && $isFollow) {
            $slots = array_merge($this->state['slots'] ?? [], $slots);
        } elseif ($intent && $isFollow && preg_match('/^et\b/', $n) && !isset($slots['period']) && !empty($this->state['slots']['period']) && in_array($intent, self::PERIOD_INTENTS, true)) {
            $slots['period'] = $this->state['slots']['period']; // « et les dépenses ? » garde la période
        }
        if (!$intent) {
            return false;
        }
        $this->out['intent'] = $intent;
        $this->out['confidence'] = $c['score'];
        $def = Catalog::TOOLS[$intent];
        // Paramètres spécifiques
        if ($intent === 'product_stock' && empty($slots['product'])) {
            $slots['product'] = Nlu::product($q, $c['tokens']);
        }
        if (in_array($intent, ['sales_summary', 'revenue', 'top_products'], true) && ($usr = Nlu::user($q))) {
            if ($usr['id'] !== $this->u->id() && !$this->u->seesAllSales()) {
                $this->say('Je ne peux vous montrer que vos propres ventes : votre rôle ne donne pas accès aux ventes de ' . $usr['name'] . '.');
                Learning::log($q, $intent, $c['score'], 'refused');
                return true;
            }
            $slots['user'] = $usr;
        }
        if (!empty($slots['location']['ambiguous'])) {
            $this->say('Laquelle voulez-vous : ' . implode(', ', $slots['location']['ambiguous']) . ' ?');
            $this->state = ['intent' => $intent, 'slots' => array_diff_key($slots, ['location' => 1]), 'periods' => $this->state['periods'] ?? []];
            Learning::log($q, $intent, $c['score'], 'clarify');
            return true;
        }
        // Droits (vérifiés côté serveur, à chaque question)
        if (!$this->u->allows($def[2])) {
            $this->say('Je ne peux pas vous donner « ' . mb_strtolower($def[0]) . ' » : votre rôle (' . $this->u->role . ') n\'a pas accès à ces données. Un responsable peut vous les communiquer ou vous accorder le droit.');
            Learning::log($q, $intent, $c['score'], 'refused');
            return true;
        }
        $this->status('Je consulte : ' . mb_strtolower($def[0]) . '…');
        $r = Catalog::run($intent, $this->u, $slots);
        if (!empty($r['clarify'])) {
            $this->say($r['clarify']);
            $this->state = ['intent' => $intent, 'slots' => $slots, 'periods' => $this->state['periods'] ?? []];
            Learning::log($q, $intent, $c['score'], 'clarify');
            return true;
        }
        $this->present($r, $q);
        // Mémoire du contexte
        $periods = $this->state['periods'] ?? [];
        if (isset($slots['period']) || in_array($intent, self::PERIOD_INTENTS, true)) {
            $periods[] = $slots['period'] ?? Catalog::period([], in_array($intent, ['sales_summary', 'daily_report', 'cash_in', 'cash_out'], true) ? 'today' : 'month');
        }
        $this->state = ['intent' => $intent, 'slots' => array_diff_key($slots, ['period2' => 1]), 'periods' => array_slice($periods, -4)];
        $this->out['sources'] = array_merge($this->out['sources'], $r['sources']);
        Learning::log($q, $intent, $c['score'], 'answered');
        return true;
    }

    /** Mise en forme : phrase (variée), indicateurs, tableau, graphique, lien, sources. */
    private function present(array $r, string $q): void
    {
        $text = Responder::pick($r['variants'] ?? [], $this->seed);
        if (setting('ai_llm_phrasing', '0') === '1' && Providers::available() && $text !== '' && empty($r['empty'])) {
            $this->status('Je formule la réponse…');
            $buf = '';
            $gen = Providers::generate(Responder::phrasingPrompt(), [['role' => 'user', 'content' => "Question : $q\nRéponse calculée : $text"]], 'phrasing',
                function (string $d) use (&$buf) {
                    $buf .= $d;
                    ($this->emit)('delta', ['text' => $d]);
                }, 300);
            if ($gen && Responder::numberGuard($gen['text'], $text, $r['facts'], $q)) {
                $this->out['text'] .= ($this->out['text'] !== '' ? "\n\n" : '') . trim($gen['text']);
                $this->out['provider'] = $gen['model'];
            } else {
                // Reformulation refusée (chiffre non vérifiable) ou modèle indisponible : on garde la phrase calculée
                ($this->emit)('replace', ['text' => $text]);
                $this->out['text'] .= ($this->out['text'] !== '' ? "\n\n" : '') . $text;
            }
        } else {
            $this->say($text);
        }
        if (!empty($r['cards'])) {
            $this->block('cards', ['items' => array_map(fn($c) => ['label' => $c[0], 'value' => $c[1]], $r['cards'])]);
        }
        if (!empty($r['table']) && !empty($r['table']['rows'])) {
            $this->block('table', $r['table'] + ['title' => '']);
        }
        if (!empty($r['chart']) && count($r['chart']['labels'] ?? []) > 1) {
            $this->block('chart', $r['chart']);
        }
        if (!empty($r['note'])) {
            $this->block('note', ['text' => $r['note']]);
        }
        if (!empty($r['link'])) {
            $this->out['links'][] = $r['link'];
        }
        $this->out['suggestions'] = array_merge($this->out['suggestions'], $r['followups'] ?? []);
    }

    // ------------------------------------------------------------------ aide et connaissance

    /** Ce que l'assistant peut faire POUR CET UTILISATEUR (selon ses droits). */
    private function capabilities(): void
    {
        $this->out['intent'] = 'capabilities';
        $by = [];
        foreach (Catalog::TOOLS as $code => $d) {
            if ($code !== 'wifi_tickets' && $this->u->allows($d[2])) {
                $by[$d[1]][] = $d[0];
            }
        }
        $acts = array_filter(Actions::TOOLS, fn($a) => $a[1] === 'action' && $this->u->can($a[2]));
        $this->say('Je suis MARWA AI, l\'assistant de POS Marwa. Je réponds à partir de vos données, avec les droits de votre compte (' . $this->u->role . ', ' . $this->u->describe()['scope'] . ') : '
            . 'je peux consulter ' . array_sum(array_map('count', $by)) . ' types d\'informations, vous expliquer chaque écran pas à pas, comparer des périodes, signaler des anomalies à vérifier'
            . ($acts ? ' et préparer ' . count($acts) . ' opération(s) que vous confirmez vous-même (' . implode(', ', array_map(fn($a) => mb_strtolower($a[0]), $acts)) . ')' : '') . '. Je ne modifie jamais rien sans votre confirmation et je ne devine pas les chiffres.');
        $rows = [];
        foreach ($by as $mod => $labels) {
            $rows[] = [ucfirst((string) $mod), implode(', ', array_slice($labels, 0, 8)) . (count($labels) > 8 ? '…' : '')];
        }
        $this->block('table', ['title' => 'Ce que je peux consulter pour vous', 'headers' => ['Domaine', 'Exemples'], 'rows' => $rows, 'more' => 0]);
        $this->out['suggestions'] = ['Fais-moi un bilan de la journée', 'Quelle est la valeur de mon stock ?', 'Comment faire un inventaire ?', 'Ma formation'];
        Learning::log($this->question, 'capabilities', 1, 'answered');
    }

    private function help(string $q): void
    {
        $this->status('Je cherche dans la connaissance de POS Marwa…');
        $this->out['intent'] = 'help';
        $kb = Learning::searchKnowledge($q, $this->u, 2);
        $menu = AppMap::search($q, $this->u, ['menu'], 3);
        $docs = Knowledge::search($q);
        foreach ($menu as $m) {
            $this->out['links'][] = [$m['title'], explode('#', $m['ref'])[0]];
        }
        $best = $kb[0] ?? null;
        $extracts = [];
        if ($best) {
            $extracts[] = $best['title'] . ' : ' . strip_tags((string) $best['body']);
        }
        foreach (array_slice($docs['docs'] ?? [], 0, 2) as $d) {
            $extracts[] = ($d['t'] ?? '') . ' : ' . mb_substr(strip_tags((string) ($d['x'] ?? '')), 0, 1200);
        }
        foreach ($menu as $m) {
            $extracts[] = $m['body'];
        }
        if (!$extracts) {
            $this->say('Je n\'ai pas trouvé de procédure correspondante dans la connaissance de POS Marwa installée. Reformulez (ex. « comment faire un inventaire ? ») ou contactez l\'assistance : ' . (string) setting('support_contact', '+227 92 08 12 82') . '.');
            Learning::log($q, 'help', 0, 'not_understood');
            return;
        }
        if (Providers::available()) {
            $this->status('Je rédige l\'explication…');
            // Historique : sans la question en cours (déjà enregistrée), commençant par l'utilisateur, rôles alternés
            $hist = Memory::recent((int) $this->conv['id'], 5);
            array_pop($hist);
            while ($hist && $hist[0]['role'] !== 'user') {
                array_shift($hist);
            }
            if ($hist && end($hist)['role'] === 'user') {
                array_pop($hist);
            }
            $gen = Providers::generate(Responder::helpPrompt(), array_merge($hist, [['role' => 'user', 'content' => "Extraits :\n" . mb_substr(implode("\n\n", $extracts), 0, 6000) . "\n\nQuestion : $q"]]),
                'help', fn(string $d) => ($this->emit)('delta', ['text' => $d]), 900);
            if ($gen) {
                $this->out['text'] = trim($gen['text']);
                $this->out['provider'] = $gen['model'];
                $this->out['sources'][] = 'documentation';
                Learning::log($q, 'help', 1, 'answered');
                return;
            }
        }
        if ($best && $best['score'] >= 0.34) {
            $this->out['html'] = '<p><b>' . e($best['title']) . '</b></p>' . $best['body'];
            ($this->emit)('html', ['html' => $this->out['html']]);
            $this->out['text'] = strip_tags($best['title'] . ' — ' . $best['body']);
            foreach (json_decode((string) $best['links_json'], true) ?: [] as $l) {
                $this->out['links'][] = $l;
            }
            Learning::log($q, 'help', $best['score'], 'answered');
            return;
        }
        $this->legacyAsk($q);
    }

    // ------------------------------------------------------------------ moteur 1.x

    private function legacyAsk(string $q): void
    {
        $r = Engine::answer($q, $this->state['legacy_ctx'] ?? null);
        if ($this->wrongPeriod($q, $r)) {
            $asked = Nlu::slots($q, $this->u)['period'];
            $this->out['intent'] = 'legacy:' . ($r['spec']['kind'] ?? 'rapport');
            $this->say('Je n\'ai pas pu appliquer la période « ' . $asked['label'] . ' » à ce rapport : pour ne pas vous donner les chiffres d\'une autre période, je préfère m\'arrêter. Indiquez les dates, par exemple « ventes du ' . date('d/m/Y', strtotime($asked['from'])) . ' au ' . date('d/m/Y', strtotime($asked['to'])) . ' en PDF ».');
            Learning::log($q, $this->out['intent'], 0.3, 'clarify');
            return;
        }
        if (!empty($r['spec'])) {
            $this->state['legacy_ctx'] = $r['spec'];
        }
        $this->legacy($r);
        Learning::log($q, 'legacy:' . ($r['spec']['kind'] ?? ($r['type'] ?? 'aide')), 0.5, ($r['type'] ?? '') === 'help' ? 'not_understood' : 'fallback');
    }

    /** Vrai si le moteur 1.x a retenu une autre période que celle demandée (il ne doit alors pas répondre). */
    private function wrongPeriod(string $q, array $r): bool
    {
        $asked = Nlu::slots($q, $this->u)['period'] ?? null;
        $sp = $r['spec']['period'] ?? null;
        if (!$asked || !isset($r['spec']['kind']) || !array_key_exists('period', $r['spec'])) {
            return false;
        }
        return !is_array($sp) || ($sp['from'] ?? null) !== $asked['from'] || ($sp['to'] ?? null) !== $asked['to'];
    }

    /** Réponse du moteur 1.x (HTML déjà préparé et échappé côté serveur). */
    private function legacy(array $r): void
    {
        $html = ($r['title'] ?? '') !== '' ? '<p><b>' . e((string) $r['title']) . '</b>' . (!empty($r['subtitle']) ? ' <span class="text-secondary">' . e((string) $r['subtitle']) . '</span>' : '') . '</p>' : '';
        $html .= (string) ($r['html'] ?? '');
        $this->out['html'] = $html;
        $this->out['text'] = trim(preg_replace('/[ \t]+/', ' ', html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/li|/h\d|/b|/span|/td|/tr)\b[^>]*>#i', '$0 ', $html)), ENT_QUOTES)));
        $this->out['intent'] ??= 'legacy';
        $this->out['legacy'] = $r;
        ($this->emit)('html', ['html' => $html]);
        if (!empty($r['open'])) {
            $this->out['open'] = (string) $r['open'];
            ($this->emit)('open', ['url' => (string) $r['open']]);
        }
        if (!empty($r['kpis'])) {
            $this->block('cards', ['items' => array_map(fn($k) => ['label' => $k['label'], 'value' => $k['value']], $r['kpis'])]);
        }
        if (!empty($r['table']['rows'])) {
            $this->block('table', ['title' => '', 'headers' => $r['table']['headers'], 'rows' => $r['table']['rows'], 'more' => $r['table']['more'] ?? 0]);
        }
        foreach ($r['actions'] ?? [] as $a) {
            if (!empty($a['url'])) {
                $this->out['links'][] = [$a['label'] ?? 'Ouvrir', $a['url']];
            }
        }
        $this->out['suggestions'] = array_merge($this->out['suggestions'], $r['suggestions'] ?? []);
    }

    /** Rien de sûr : modèle IA (classement), puis moteur 1.x, sinon aveu clair. */
    private function fallback(string $q, array $c): void
    {
        if (Providers::available()) {
            $codes = array_keys(Catalog::TOOLS);
            $gen = Providers::generate('Classe la question d\'un utilisateur de logiciel de gestion de commerce dans UNE intention de la liste, ou réponds « aucune ». Réponds uniquement par le code.',
                [['role' => 'user', 'content' => 'Intentions : ' . implode(', ', array_map(fn($k) => $k . ' (' . Catalog::TOOLS[$k][0] . ')', $codes)) . "\nQuestion : " . $q]], 'nlu', null, 20);
            $code = $gen ? trim(strtolower(preg_replace('/[^a-z_]/i', '', $gen['text']))) : '';
            if (isset(Catalog::TOOLS[$code])) {
                $this->out['provider'] = $gen['model'];
                $this->answerIntent($q, ['intent' => $code, 'score' => Nlu::THRESHOLD, 'tokens' => $c['tokens'], 'norm' => $c['norm']]);
                return;
            }
        }
        $r = Engine::answer($q, $this->state['legacy_ctx'] ?? null);
        // Le moteur 1.x ne doit jamais répondre sur une autre période que celle demandée (« ventes de 1999 » ≠ aujourd'hui)
        if (($r['type'] ?? '') !== 'help' && !empty($r['spec']) && !$this->wrongPeriod($q, $r)) {
            $this->state['legacy_ctx'] = $r['spec'];
            $this->legacy($r);
            Learning::log($q, 'legacy:' . ($r['spec']['kind'] ?? 'rapport'), 0.5, 'fallback');
            return;
        }
        $this->out['intent'] = null;
        $this->say('Je suis spécialisé dans la gestion de votre commerce et l\'utilisation de POS Marwa : cette question semble hors de ce domaine, ou je ne l\'ai pas comprise, et je préfère ne pas deviner un chiffre. Si elle concerne votre activité, reformulez-la ; Par exemple : « valeur du stock », « ventes d\'hier », « qui me doit de l\'argent ? », « comment faire un inventaire ? ».');
        $this->out['suggestions'] = ['Fais-moi un bilan de la journée', 'Quelle est la valeur de mon stock ?', 'Que sais-tu faire ?'];
        Learning::log($q, $c['intent'], $c['score'], 'not_understood');
    }
}
