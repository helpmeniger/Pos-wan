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
        'sales_by_category', 'cancelled_sales', 'returns', 'discounts_tax', 'purchases_total', 'income_vs_expenses', 'daily_report', 'activity_summary', 'anomalies', 'top_cashier', 'epay_summary', 'fiscal_summary', 'transfers', 'stock_in_total', 'product_sales', 'items_sold', 'customer_payments', 'expenses_by_category', 'margin_rate', 'most_profitable', 'best_day'];

    /** Expressions courantes (texte normalisé) → intention. */
    private const IDIOMS = [
        '/^(alors )?(ou (on|nous) en (est|sommes)|ou en (est on|sommes nous)|on en est ou)\b/' => 'daily_report',
        '/^(alors )?(ca|sa|cela) (donne|dit) quoi\b/' => 'daily_report',
        '/^(fais|fait|faites|donne)( moi)? (le|un) (petit )?point\b/' => 'daily_report',
        '/^(quoi de neuf|quelles nouvelles)\b.{0,25}\b(boutique|magasin|commerce|ventes?|affaires?)\b/' => 'daily_report',
        '/^(donne|montre|sors)( moi)? (les|mes) chiffres$/' => 'daily_report',
        '/^(regarde|vois|jette un oeil)( un peu)?( ce qui se passe| la situation)?$/' => 'whats_wrong',
        '/^(qu est ce qui|kes ki) (se passe|cloche|ne va pas|bloque)\b/' => 'whats_wrong',
        '/^(fais|fait) le necessaire$|^arrange( moi)? ca$/' => 'whats_wrong',
    ];

    /** @var callable */
    private $emit;
    private UserContext $u;
    private array $conv;
    private array $state;
    private string $question;
    private int $seed;
    /** Analyse à blanc (banc de tests, « tester une formulation ») : rien n'est enregistré, aucun modèle externe n'est appelé. */
    private bool $dry = false;
    /** Trace de la compréhension : route, issue, paramètres, corrections de saisie. */
    private array $trace = ['outcome' => null, 'slots' => [], 'intents' => [], 'corrected' => [], 'tool' => null];
    private bool $inherit = false;
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
            $msg = preg_replace('/^(AUTH_REQUIRED|CLARIFY):/', '', $e->getMessage());
            $b->say($msg);
            $this->log($b->question, $b->out['intent'], 0, 'refused');
        } catch (\Throwable $e) {
            \App\Core\Logger::error('marwa_ai', ['error' => $e->getMessage(), 'file' => basename($e->getFile()) . ':' . $e->getLine()]);
            $b->say('Je n\'ai pas pu terminer cette analyse (erreur technique). Aucun chiffre n\'est donc donné ; réessayez ou reformulez.');
            $this->log($b->question, $b->out['intent'], 0, 'error');
        }
        Memory::saveState((int) $b->conv['id'], $b->state);
        $payload = ['blocks' => $b->out['blocks'], 'html' => $b->out['html'], 'links' => $b->out['links'], 'suggestions' => array_values(array_unique($b->out['suggestions'])), 'open' => $b->out['open'], 'proposal' => $b->out['proposal'] ? ['id' => $b->out['proposal']['id'], 'label' => $b->out['proposal']['label'], 'summary' => $b->out['proposal']['summary'], 'sensitive' => $b->out['proposal']['sensitive']] : null];
        $mid = Memory::add((int) $b->conv['id'], 'assistant', $b->out['text'] !== '' ? $b->out['text'] : strip_tags((string) $b->out['html']), [
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'intent' => $b->out['intent'], 'confidence' => $b->out['confidence'],
            'sources' => mb_substr(implode(',', array_unique($b->out['sources'])), 0, 500) ?: null, 'provider' => $b->out['provider'], 'duration_ms' => (int) round((microtime(true) - $t) * 1000)]);
        $emit('done', ['message_id' => $mid, 'sources' => array_values(array_unique($b->out['sources'])), 'links' => $b->out['links'], 'suggestions' => $payload['suggestions'], 'proposal' => $b->out['proposal']]);
        return ['message_id' => $mid, 'conversation_id' => (int) $b->conv['id'], 'trace' => $b->trace] + $b->out;
    }

    /**
     * Analyse à blanc d'une question : même compréhension et mêmes lectures que handle(), mais sans rien enregistrer
     * (ni conversation, ni journal, ni proposition d'action) et sans appel à un modèle externe.
     * @param array $state contexte de conversation (celui renvoyé par l'analyse précédente)
     * @return array{intent: ?string, outcome: ?string, slots: array, intents: string[], text: string, state: array, corrected: array, ms: float}
     */
    public static function analyze(string $question, array $state = [], ?UserContext $u = null): array
    {
        $b = new self();
        $b->dry = true;
        $b->emit = static function (): void {
        };
        $b->u = $u ?? UserContext::current();
        $b->conv = ['id' => 0, 'message_count' => 0, 'title' => ''];
        $b->state = $state;
        $b->question = mb_substr(trim($question), 0, 500);
        $b->seed = crc32($b->question);
        $t = microtime(true);
        try {
            $b->route(null);
        } catch (BusinessException $e) {
            $b->say(preg_replace('/^(AUTH_REQUIRED|CLARIFY):/', '', $e->getMessage()));
            $b->trace['outcome'] ??= 'blocked';
        } catch (\Throwable $e) {
            $b->say('Erreur technique : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
            $b->trace['outcome'] = 'error';
        }
        return ['intent' => $b->out['intent'], 'outcome' => $b->trace['outcome'], 'slots' => $b->trace['slots'], 'intents' => $b->trace['intents'], 'tool' => $b->trace['tool'],
            'text' => $b->out['text'] !== '' ? $b->out['text'] : trim(strip_tags((string) $b->out['html'])), 'blocks' => $b->out['blocks'], 'proposal' => $b->out['proposal'], 'state' => $b->state,
            'corrected' => $b->trace['corrected'], 'confidence' => $b->out['confidence'], 'ms' => round((microtime(true) - $t) * 1000, 1)];
    }

    /** Journal d'apprentissage (sauf à blanc) et issue de la compréhension. */
    private function log(string $q, ?string $intent, float $conf, string $outcome): void
    {
        $this->trace['outcome'] = ['answered' => 'answer', 'fallback' => 'answer', 'refused' => 'refuse', 'clarify' => 'clarify', 'not_understood' => 'unknown', 'error' => 'error', 'proposed' => 'propose', 'blocked' => 'blocked'][$outcome] ?? $outcome;
        if ($intent !== null) {
            $this->trace['intents'][] = $intent;
        }
        if (!$this->dry) {
            Learning::log($this->question, $intent, $conf, in_array($outcome, ['proposed', 'blocked'], true) ? 'answered' : $outcome);
        }
    }

    private function llm(): bool
    {
        return !$this->dry && Providers::available();
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
        $raw = $this->question;
        if ($raw === '') {
            $this->say('Posez-moi une question sur votre activité ou sur le logiciel, par exemple « valeur du stock », « bilan de la journée » ou « comment faire un inventaire ? ».');
            $this->trace['outcome'] = 'clarify';
            return;
        }
        // 0. Récupération des fautes : le moteur lit le texte remis en forme, la question d'origine reste affichée
        $clean = Recover::clean($raw);
        $q = $clean['text'] !== '' ? $clean['text'] : $raw;
        $this->trace['corrected'] = $clean['changes'];
        $n = Text::norm($q);
        // 1. Tentative de manipulation : refus expliqué
        if ($msg = Guard::check($n)) {
            $this->out['intent'] = 'security';
            $this->say($msg);
            $this->log($q, 'security', 1, 'refused');
            return;
        }
        // 2. Action demandée explicitement
        if ($a = Actions::detect($q, $this->u)) {
            $this->action($a[0], $a[1], $q);
            return;
        }
        $c = Nlu::classify($q);
        $top = $c['score'] >= Nlu::THRESHOLD ? $c['intent'] : null;
        $follow = !empty($this->state['intent']) && (preg_match('/^(et|puis|pareil|idem|aussi|alors|mais|sans|retire|enleve|hors|sauf)\b/', $n) || mb_strlen($n) <= 22);
        // Formules de politesse reconnues sans ambiguïté (« comment vas-tu ? », « merci, c'est tout »)
        if (preg_match('/^(comment (vas tu|va tu|allez vous|tu vas|ca va)|ca va( bien)?( marwa)?$|tu vas bien)/', $n) && $this->general('wellbeing', $q, $c)) {
            return;
        }
        if (preg_match('/^((ok|super|parfait|top|bien|d accord) )?merci\b/', $n) && !preg_match('/\b(combien|quel|quelle|quels|montre|donne|affiche|fais|et (maintenant|les|le|la)|ventes?|stock|caisse|produits?|clients?|rapport)\b/', $n)
            && $this->general('thanks', $q, $c)) {
            return;
        }
        // 3. Conversation : salutations, remerciements, plaisanteries, notions de gestion
        if ($top && General::isGeneral($top) && ($c['score'] >= 0.55 || count($c['tokens']) <= 4) && !($follow && $c['score'] < 0.7)
            && !(preg_match('/^(comment|ou |que faire|pourquoi)\b/', $n) && !in_array($top, ['wellbeing', 'app_purpose', 'app_name', 'concept'], true) && $c['score'] < 0.9)) {
            if ($top !== 'concept' || (($k = General::concept($q)) && ($k['hits'] >= 2 || $c['score'] >= 0.6))) {
                if ($this->general($top, $q, $c)) {
                    return;
                }
            }
        }
        // Notion de gestion demandée (« c'est quoi le stock minimum ? ») : explication, sauf intention de données très nette
        if (!($top && isset(Catalog::TOOLS[$top]) && $c['score'] >= 0.9) && !$follow && General::concept($q)
            && preg_match('/^(c est quoi|c quoi|qu est ce qu|que veu[tx] dire|que signifie|a quoi ser[tv]|pourquoi|comment fonctionne|quelle est la difference|difference entre)/', $n) && $this->general('concept', $q, $c)) {
            return;
        }
        // 4. Hors domaine (garde-fou 1.x : météo, politique, santé…)
        if ($r = Scope::guard($n)) {
            $this->out['intent'] = 'out_of_scope';
            $this->legacy($r);
            $this->log($q, 'out_of_scope', 1, 'refused');
            return;
        }
        // Expressions toutes faites que les mots seuls ne permettent pas de classer
        if (true) {
            foreach (self::IDIOMS as $re => $idiom) {
                if (preg_match($re, $n)) {
                    $this->answerIntent($q, ['intent' => $idiom, 'score' => 0.9, 'forced' => true] + $c);
                    return;
                }
            }
        }
        // 5. Conseils calculés sur les données, alertes, diagnostic de caisse (moteur Expert)
        if (in_array($top, ['advice', 'whats_wrong', 'cash_diagnostic'], true) && count(Nlu::split($q)) <= 1) {
            $this->answerIntent($q, $c);
            return;
        }
        // 6. Domaines du moteur 1.x conservés : rapports PDF / Excel, formation, marketing, contrôle, conseils thématiques
        $howTo = (bool) preg_match('/^(comment|pourquoi|ou |que faire|c est quoi|a quoi)\b|\b(n|ne) \w+ (pas|plus|pa)\b|\b(marche|fonctionne|sort|imprime) (pas|plus|pa)\b|\bprobleme\b/', $n);
        // « Quand le ticket a-t-il été imprimé ? » est une question, pas une demande d'impression
        $asksFact = (bool) preg_match('/^(quand|qui|combien|quel|quelle|a quelle|est ce)\b/', $n);
        if (!$howTo && (preg_match('/\b(pdf|excel|csv)\b/', $n) || (!$asksFact && preg_match('/\b(imprime\w*|telecharg\w*|exporte\w*)\b/', $n)) || preg_match('/^rapport\b/', $n))) {
            $this->legacyAsk($q, 'report');
            return;
        }
        if (Trainer::isTraining($n) && !($top && $top !== 'training' && $c['score'] >= 0.55)) {
            $this->legacyAsk($q, 'training');
            return;
        }
        if (Checkup::isCheckup($n) && !($top && $top !== 'checkup' && $c['score'] >= 0.55)) {
            $this->legacyAsk($q, 'checkup');
            return;
        }
        if (Marketing::isMarketing($n) && !preg_match('/\b(valeur|combien|chiffre)\b/', $n) && !($top === 'help' && $c['score'] >= 0.55)) {
            $this->legacyAsk($q, 'marketing');
            return;
        }
        if (Advisor::isAdvice($n) && !preg_match('/\b(combien|valeur|quel est|quelle est|pourquoi)\b/', $n) && !($top && isset(Catalog::TOOLS[$top]) && $c['score'] >= 0.6)) {
            $this->legacyAsk($q, 'advice_topic');
            return;
        }
        if (preg_match('/\b(que sais tu faire|que peux tu faire|tu sais faire quoi|tu peux faire quoi|qui es tu|tu es qui|presente toi|tes (capacites|fonctionnalites))\b|^aide( moi)?$/', $n)) {
            $this->capabilities();
            return;
        }
        // 7. Définitions (brut, net, TVA, marge…) et types de transactions
        if (preg_match('/\b(c est quoi|qu est ce que|que veu[tx] dire|que signifie|definition|difference entre|quelle difference|explique moi (le|la|les) (terme|notion|difference))\b/', $n) && ($ex = Transactions::explain($q))) {
            $this->out['intent'] = 'definition';
            $this->out['html'] = $ex['html'];
            ($this->emit)('html', ['html' => $ex['html']]);
            $this->out['text'] = strip_tags($ex['html']);
            $this->log($q, 'definition', 1, 'answered');
            return;
        }
        if (preg_match('/\b(types? de (transactions?|mouvements?|operations?))\b/', $n)) {
            $this->say('Voici les types de mouvements que POS Marwa enregistre, avec leurs effets sur le stock, la caisse et les comptes.');
            $this->block('table', ['title' => 'Types de transactions'] + Transactions::listing());
            $this->out['intent'] = 'transaction_types';
            $this->log($q, 'transaction_types', 1, 'answered');
            return;
        }
        // Notion de gestion demandée sans que le classifieur l'ait reconnue (« c'est quoi la rotation du stock ? »)
        if (preg_match('/^(c est quoi|qu est ce qu|que veu[tx] dire|que signifie|a quoi sert|a quoi servent|pourquoi (faut il|faire|mon|ma|mes|le|la|les|un|une)|comment fonctionne|quelle est la difference|difference entre|explique moi)\b/', $n)
            && !($top && isset(Catalog::TOOLS[$top]) && $c['score'] >= 0.6) && General::concept($q) && $this->general('concept', $q, $c)) {
            return;
        }
        // 8. Intention hors catalogue reconnue par le classifieur (aide, action, rapport, limites…)
        if ($top && !isset(Catalog::TOOLS[$top]) && $c['score'] >= 0.5 && !$follow && !($howTo && str_starts_with($top, 'action_')) && $this->dispatch($top, $q, $c)) {
            return;
        }
        // 9. Questions d'aide / de procédure (formulations en « comment… », « où se trouve… »)
        if (preg_match('/^(comment|explique|expliquez|aide moi|ou (se trouve|trouver|est|voir|regler|changer|indiquer)|ou puis je|que faire|quelle est la procedure|les etapes|pourquoi (je ne peux|ne puis|ca ne|le ticket ne|l imprimante))\b|\b(comment (on|je|faire|creer|ajouter|enregistrer|faire un|configurer|utiliser|annuler|cloturer|consulter))\b|\b(que faire|quoi faire|comment faire|je fais quoi|on fait quoi)$/', $n)
            && !preg_match('/\b(combien|valeur de mon|quel est le (solde|chiffre|benefice))\b/', $n) && !($top && isset(Catalog::TOOLS[$top]) && $c['score'] >= 0.9)) {
            $this->help($q);
            return;
        }
        $this->data($q, $page);
    }

    /** Conversation et notions (moteur General). Faux si rien d'adapté. */
    private function general(string $intent, string $q, array $c): bool
    {
        $r = General::reply($intent, $q, $this->u, $this->seed);
        if (!$r) {
            return false;
        }
        $this->out['intent'] = $intent;
        $this->out['confidence'] = $c['score'];
        if (!empty($r['title'])) {
            $this->trace['slots']['concept'] = $r['concept'];
        }
        $this->say($r['text']);
        $this->out['suggestions'] = array_merge($this->out['suggestions'], $r['suggestions'] ?? []);
        $this->log($q, $intent, $c['score'], 'answered');
        return true;
    }

    /** Intentions hors catalogue de données reconnues par le classifieur. Faux si elle n'est pas traitée ici. */
    private function dispatch(string $intent, string $q, array $c): bool
    {
        $this->out['confidence'] = $c['score'];
        if (str_starts_with($intent, 'action_')) {
            $tool = substr($intent, 7);
            $n = Text::norm($q);
            // Une question (« que dois-je commander ? ») n'est pas une demande d'opération
            if (preg_match('/^(que|quoi|quel|quelle|quels|quelles|combien|est ce|qui|quand|ou|pourquoi|comment|y a t il)\b|\b(dois je|faut il|doit on)\b/', $n) && $tool !== 'navigate') {
                return false;
            }
            // Une opération se demande à l'impératif (« ajoute… », « prépare… ») : « les ventes annulées ce mois » n'en est pas une
            if (!preg_match('/^(stp |svp |peux tu |tu peux |il faut |faut |je veux |je voudrais |merci d |j ai recu \\d+ [a-z ]+ )?(ajoute|rajoute|mets|met|augmente|incremente|entre|prepare|cree|genere|fais|fait|lance|ouvre|demarre|commence|retire|sors|sort|enregistre|annule|commande|va|emmene|amene|affiche|supprime|modifie|change|valide|rembourse|efface)\\w*\\b/', $n)
                && !preg_match('/\\b(ajoute les|ajoute le|ajoute la)$/', $n)) {
                return false;
            }
            $params = match ($tool) {
                'stock_add' => Actions::stockAddParams($q, $n, $this->u),
                'inventory_start' => ['location' => Nlu::location($n, $this->u)],
                'cash_out' => ['amount' => preg_match('/(\d[\d ]*(?:[.,]\d+)?)/', $n, $m) ? (float) str_replace([' ', ','], ['', '.'], $m[1]) : 0.0, 'reason' => preg_match('/\b(?:pour|motif|raison)\s*:?\s+(.{3,120})$/u', $n, $r) ? trim($r[1]) : ''],
                'sale_cancel' => ['number' => preg_match('/\b([a-z]{1,3}\d{4}[- ]\d{3,6})\b/i', $q, $m) ? strtoupper(str_replace(' ', '-', $m[1])) : '', 'reason' => preg_match('/\b(?:pour|motif|raison|car)\s*:?\s+(.{3,120})$/u', $n, $r) ? trim($r[1]) : ''],
                'unsupported' => ['message' => 'Je ne fais pas cette opération moi-même : elle se fait dans l\'écran concerné du logiciel, pour que l\'historique soit tenu. Dites-moi « comment … ? » et je vous guide pas à pas.'],
                default => [],
            };
            $this->action($tool, $params, $q);
            return true;
        }
        switch ($intent) {
            case 'help':
                $this->help($q);
                return true;
            case 'capabilities':
                $this->capabilities();
                return true;
            case 'report':
            case 'training':
            case 'marketing':
            case 'checkup':
            case 'advice_topic':
                $this->legacyAsk($q, $intent);
                return true;
            case 'definition':
                if ($ex = Transactions::explain($q)) {
                    $this->out['intent'] = 'definition';
                    $this->out['html'] = $ex['html'];
                    ($this->emit)('html', ['html' => $ex['html']]);
                    $this->out['text'] = strip_tags($ex['html']);
                    $this->log($q, 'definition', $c['score'], 'answered');
                    return true;
                }
                return General::concept($q) ? $this->general('concept', $q, $c) : false;
            case 'transaction_types':
                $this->say('Voici les types de mouvements que POS Marwa enregistre, avec leurs effets sur le stock, la caisse et les comptes.');
                $this->block('table', ['title' => 'Types de transactions'] + Transactions::listing());
                $this->out['intent'] = 'transaction_types';
                $this->log($q, 'transaction_types', $c['score'], 'answered');
                return true;
            case 'security':
                if ($c['score'] < 0.62) {
                    return false;
                }
                $this->out['intent'] = 'security';
                $this->say('Je ne peux pas faire cela : je travaille uniquement avec les droits du compte connecté, sans contourner les contrôles ni modifier ou masquer des opérations enregistrées.');
                $this->log($q, 'security', $c['score'], 'refused');
                return true;
            case 'out_of_scope':
                if ($c['score'] < 0.62) {
                    return false;
                }
                $this->out['intent'] = 'out_of_scope';
                $this->say('Ce sujet sort de ce que je connais : je suis l\'assistant de POS Marwa, à l\'aise avec votre gestion (ventes, stock, caisse, clients, fournisseurs) et l\'utilisation du logiciel. Sur ce terrain-là, je vous aide volontiers.');
                $this->log($q, 'out_of_scope', $c['score'], 'refused');
                return true;
        }
        if (General::isGeneral($intent)) {
            return $this->general($intent, $q, $c);
        }
        return false;
    }

    /** Opération demandée : vérifications, clarification si besoin, proposition à confirmer (jamais d'exécution ici). */
    private function action(string $tool, array $params, string $q): void
    {
        $this->status('Je prépare l\'opération et vérifie vos droits…');
        $this->out['intent'] = 'action_' . $tool;
        $this->trace['tool'] = $tool;
        $slots = [];
        foreach (['quantity', 'amount', 'number', 'reason'] as $k) {
            if (isset($params[$k]) && $params[$k] !== '' && $params[$k] !== 0.0) {
                $slots[$k] = $params[$k];
            }
        }
        foreach (['product', 'location', 'exclude'] as $k) {
            if (!empty($params[$k]['name'])) {
                $slots[$k] = $params[$k]['name'];
            }
        }
        $this->trace['slots'] = $slots;
        $r = Actions::propose($tool, $params, $this->u, (int) $this->conv['id'] ?: null, $q, $this->dry);
        $this->say($r['text']);
        if (!empty($r['link'])) {
            $this->out['links'][] = $r['link'];
        }
        $this->out['proposal'] = $r['proposal'];
        $outcome = !empty($r['refused']) || $tool === 'unsupported' ? 'refused' : (!empty($r['clarify']) ? 'clarify' : (!empty($r['blocked']) ? 'blocked' : ($r['proposal'] ? 'proposed' : 'answered')));
        $this->log($q, 'action_' . $tool, 1, $outcome);
    }

    // ------------------------------------------------------------------ données

    private function data(string $q, ?string $page): void
    {
        $parts = Nlu::split($q);
        $multi = count($parts) > 1 ? array_map(fn($p) => [$p, Nlu::classify($p)], $parts) : [];
        // Une partie sans intention propre mais avec un mot de comparaison ou de suite reste rattachée à la précédente
        $confident = array_values(array_filter($multi, fn($x) => $x[1]['score'] >= 0.36 || preg_match('/^(compare|comparaison|et)\b/', $x[1]['norm'])));
        if (count($confident) > 1 && count(array_unique(array_map(fn($x) => $x[1]['intent'], $confident))) > 1) {
            $this->status('Votre question contient ' . count($confident) . ' demandes : je les traite une à une.');
            $this->inherit = true;
            $all = [];
            foreach ($confident as [$part, $c]) {
                $c['score'] = max($c['score'], $c['score'] >= 0.36 ? Nlu::THRESHOLD : 0);   // partie retenue : traitée comme comprise
                $top = $c['score'] >= Nlu::THRESHOLD ? $c['intent'] : null;
                if ($top && !isset(Catalog::TOOLS[$top]) ? $this->dispatch($top, $part, $c) : $this->answerIntent($part, $c)) {
                    $all = array_merge($all, [$this->out['intent']]);
                }
            }
            $this->inherit = false;
            $this->trace['intents'] = array_values(array_unique(array_filter($all)));
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
        if (in_array($intent, ['last_sale', 'last_sales'], true)) {
            $intent = preg_match('/\b(derniers|dernieres|les \d+|les dern\w*|\d+ dern\w*|liste|historique)\b/', $n) || isset($slots['limit']) ? 'last_sales' : (preg_match('/\b(dernier|derniere)\b/', $n) ? 'last_sale' : $intent);
        }
        // Question de suite : entre deux intentions proches, celle du même domaine que la question précédente
        if ($prev && $startsEt && $intent && isset(Catalog::TOOLS[$prev], Catalog::TOOLS[$intent]) && !empty($c['second']) && isset(Catalog::TOOLS[$c['second']])
            && ($c['second_score'] ?? 0) >= $c['score'] - 0.2 && ($c['second_score'] ?? 0) >= 0.35
            && Catalog::TOOLS[$intent][1] !== Catalog::TOOLS[$prev][1] && Catalog::TOOLS[$c['second']][1] === Catalog::TOOLS[$prev][1]) {
            $intent = $c['second'];
        }
        // « Et les 5 meilleurs ? » après une question sur les ventes : les articles les plus vendus
        if ($prev && $startsEt && isset(Catalog::TOOLS[$prev]) && Catalog::TOOLS[$prev][1] === 'ventes' && preg_match('/\b(meilleur\w*|top|mieux vendu\w*|plus vendu\w*)\b/', $n)
            && !preg_match('/\b(client|vendeur|caissier|jour|journee|boutique|magasin)\w*\b/', $n)) {
            $intent = 'top_products';
        }
        // Question trop vague (« combien ? ») : on demande au lieu de deviner
        if (empty($c['forced']) && !($prev && $isFollow) && $c['tokens'] && count(array_diff($c['tokens'], ['combien', 'quoi', 'total', 'montant', 'nombre'])) === 0) {
            $this->say('Combien de quoi ? Précisez par exemple : « combien de ventes aujourd\'hui ? », « combien vaut mon stock ? », « combien me doivent les clients ? ».');
            $this->log($q, null, $c['score'], 'clarify');
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
        // « Et au dépôt ? », « et hier ? » : rien d'autre qu'un lieu ou une période → suite, quel que soit le score
        $locWords = !empty($slots['location']['name']) ? Text::words($slots['location']['name']) : [];
        $rest = array_filter(explode(' ', $n), fn($w) => !in_array($w, $locWords, true) && !preg_match('/^\d+(er|e|eme)?$/', $w) && !in_array($w, ['et', 'le', 'la', 'les', 'l', 'du', 'de', 'des', 'd', 'au', 'aux', 'a', 'dans',
            'pour', 'sur', 'en', 'ce', 'cette', 'hier', 'avant', 'aujourd', 'hui', 'semaine', 'mois', 'annee', 'an', 'dernier', 'derniere', 'jour', 'journee', 'passe', 'passee', 'alors', 'puis', 'aussi', 'meme', 'chose',
            'pareil', 'idem', 'mais', 'ou', 'magasin', 'boutique', 'depot', 'celui', 'celle', 'janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin', 'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'], true));
        $onlySlot = $startsEt && $hasSlot && !$rest;
        // « Et le sucre ? » après une question sur un article : même question pour un autre article
        $otherItem = $startsEt && $intent !== $prev && $c['score'] < 0.8 && ((in_array($prev, ['product_stock', 'product_info', 'product_sales'], true) && Nlu::named($q, 'product'))
            || ($prev === 'customer_info' && Nlu::named($q, 'customer')) || ($prev === 'supplier_info' && Nlu::named($q, 'supplier')));
        $follow = $prev && $isFollow && ($intent === null || $intent === $prev || ($c['score'] < 0.6 && $hasSlot) || $onlySlot || $otherItem);
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
        // « Retire les produits de la boutique », « sans le dépôt » : même question, en excluant ce lieu
        if ($prev && isset(Catalog::TOOLS[$prev]) && preg_match('/^(et )?(retire|enleve|sans|hors|sauf|exclu\w*|ne compte pas|en dehors)\b/', $n) && !empty($slots['location'])) {
            if (!empty($slots['location']['ambiguous'])) {
                $this->say('Lequel faut-il retirer : ' . implode(', ', $slots['location']['ambiguous']) . ' ?');
                $this->log($q, $prev, $c['score'], 'clarify');
                return true;
            }
            $intent = $prev;
            $slots = array_merge($this->state['slots'] ?? [], ['exclude' => $slots['location']]);
            unset($slots['location']);
            $c['score'] = max($c['score'], 0.5);
        }
        // « Compare avec la semaine dernière » : la période citée est comparée à celle dont on vient de parler
        if (($intent === 'sales_compare' || preg_match('/^(compare|comparaison|par rapport)\b/', $n)) && empty($slots['period2']) && !empty($slots['period']) && !empty($this->state['slots']['period'])
            && $this->state['slots']['period']['from'] !== $slots['period']['from']) {
            $slots['period2'] = $slots['period'];
            $slots['period'] = $this->state['slots']['period'];
            $intent = 'sales_compare';
        }
        // Plusieurs demandes dans une même question : les suivantes gardent la période de la première
        if ($this->inherit && $intent && empty($slots['period']) && !empty($this->state['slots']['period']) && in_array($intent, self::PERIOD_INTENTS, true)) {
            $slots['period'] = $this->state['slots']['period'];
        }
        // « Et son prix ? », « combien on en a vendu ? » : le pronom renvoie à l'article dont on vient de parler
        $prevItem = $this->state['slots']['product'] ?? null;
        if ($prevItem && empty($prevItem['ambiguous']) && preg_match('/\b(son|sa|ses|en a|on en|j en|il en|cet article|ce produit|celui la|lui)\b/', $n) && !Nlu::named($q, 'product')) {
            if (in_array($intent, ['sales_summary', 'items_sold', 'top_products', 'revenue'], true) || ($intent === null && preg_match('/\bvendu\w*\b/', $n))) {
                $intent = 'product_sales';
            } elseif ($intent === null && preg_match('/\b(prix|coute|combien)\b/', $n)) {
                $intent = 'product_info';
            }
            if (in_array($intent, ['product_stock', 'product_info', 'product_sales', 'stock_movements'], true)) {
                $slots['product'] = $prevItem;
                $c['score'] = max($c['score'], 0.5);
            }
        }
        // Autre question dans le même domaine (« et les ruptures ? ») : le magasin dont on parlait est conservé
        if ($prev && $startsEt && $intent && $intent !== $prev && isset(Catalog::TOOLS[$prev], Catalog::TOOLS[$intent]) && Catalog::TOOLS[$prev][1] === Catalog::TOOLS[$intent][1]
            && empty($slots['location']) && !empty($this->state['slots']['location']) && empty($this->state['slots']['location']['ambiguous'])) {
            $slots['location'] = $this->state['slots']['location'];
        }
        // Comparaison avec une seule période citée et sans contexte : elle est comparée à la période en cours équivalente
        if ($intent === 'sales_compare' && empty($slots['period2']) && !empty($slots['period'])) {
            $cur = ['le mois dernier' => 'ce mois', 'la semaine derniere' => 'cette semaine', 'hier' => 'aujourd hui'][Text::norm((string) $slots['period']['label'])] ?? null;
            if ($cur && ($p1 = Text::period($cur))) {
                $slots['period2'] = $slots['period'];
                $slots['period'] = $p1;
            }
        }
        // Trop de mots inconnus pour une intention peu assurée : on ne répond pas à côté
        if ($intent && empty($c['forced']) && !$follow && ($c['coverage'] ?? 1) < 0.5 && $c['score'] < 0.75 && !$this->inherit) {
            return false;
        }
        if (!$intent) {
            return false;
        }
        if (!isset(Catalog::TOOLS[$intent])) {
            if ($this->dispatch($intent, $q, $c)) {
                return true;
            }
            $alt = array_values(array_filter(array_keys($c['top'] ?? []), fn($k) => isset(Catalog::TOOLS[$k]) && $c['top'][$k] >= Nlu::THRESHOLD));
            if (!$alt) {
                return false;
            }
            $intent = $alt[0];
        }
        // « Qu'est-ce que je ne dois plus commander ? » : l'inverse du réapprovisionnement
        if ($intent === 'reorder' && preg_match('/\b(ne|n)\b.{0,25}\b(plus|pas)\b.{0,12}\b(command|recommand|rachet|reapprovision)\w*|\bne plus (command|recommand|rachet)\w*/', $n)) {
            $intent = 'dormant';
        }
        $this->out['intent'] = $intent;
        $this->out['confidence'] = $c['score'];
        $def = Catalog::TOOLS[$intent];
        // Paramètres spécifiques : article, client ou fournisseur nommé
        foreach (['product' => ['product_stock', 'product_info', 'product_sales', 'stock_movements'], 'customer' => ['customer_info'], 'supplier' => ['supplier_info']] as $kind => $for) {
            if (!in_array($intent, $for, true)) {
                continue;
            }
            $found = Nlu::named($q, $kind);
            if ($found && !empty($found['ambiguous'])) {
                $this->say('Plusieurs ' . ['product' => 'articles', 'customer' => 'clients', 'supplier' => 'fournisseurs'][$kind] . ' correspondent : ' . implode(', ', $found['ambiguous']) . '. Lequel voulez-vous ?');
                $this->state = ['intent' => $intent, 'slots' => $slots, 'periods' => $this->state['periods'] ?? []];
                $this->trace['slots'] = ['ambiguous' => $found['ambiguous']];
                $this->log($q, $intent, $c['score'], 'clarify');
                return true;
            }
            if (!$found && in_array($kind, ['customer', 'supplier'], true) && empty($slots[$kind]) && !preg_match('/\b(cherche\w*|trouve\w*|fiche|contact|numero|telephone|infos?|informations?|plafond|dernier achat|derniere)\b/', $n)) {
                $intent = $kind === 'customer' ? 'customer_debts' : 'supplier_payables';   // « combien je dois au fournisseur » sans nom → tous
                $this->out['intent'] = $intent;
                $def = Catalog::TOOLS[$intent];
                break;
            }
            if ($found) {
                $slots[$kind] = $found;
            } elseif ($kind === 'product' && $intent === 'product_stock' && empty($slots['product'])) {
                $slots['product'] = Nlu::product($q, $c['tokens']);
            }
        }
        if (in_array($intent, ['sales_summary', 'revenue', 'top_products'], true) && ($usr = Nlu::user($q))) {
            if ($usr['id'] !== $this->u->id() && !$this->u->seesAllSales()) {
                $this->say('Je ne peux vous montrer que vos propres ventes : votre rôle ne donne pas accès aux ventes de ' . $usr['name'] . '.');
                $this->log($q, $intent, $c['score'], 'refused');
                return true;
            }
            $slots['user'] = $usr;
        }
        if (!empty($slots['location']['ambiguous'])) {
            $this->say('Laquelle voulez-vous : ' . implode(', ', $slots['location']['ambiguous']) . ' ?');
            $this->state = ['intent' => $intent, 'slots' => array_diff_key($slots, ['location' => 1]), 'periods' => $this->state['periods'] ?? []];
            $this->log($q, $intent, $c['score'], 'clarify');
            return true;
        }
        // Droits (vérifiés côté serveur, à chaque question)
        if (!$this->u->allows($def[2])) {
            $this->say('Je ne peux pas vous donner « ' . mb_strtolower($def[0]) . ' » : votre rôle (' . $this->u->role . ') n\'a pas accès à ces données. Un responsable peut vous les communiquer ou vous accorder le droit.');
            $this->log($q, $intent, $c['score'], 'refused');
            return true;
        }
        $this->status('Je consulte : ' . mb_strtolower($def[0]) . '…');
        $this->trace['tool'] = $intent;
        $this->trace['slots'] = array_filter(['period' => $slots['period'] ?? null, 'period2' => $slots['period2'] ?? null, 'location' => $slots['location']['name'] ?? null, 'exclude' => $slots['exclude']['name'] ?? null,
            'product' => $slots['product']['name'] ?? null, 'customer' => $slots['customer']['name'] ?? null, 'supplier' => $slots['supplier']['name'] ?? null, 'user' => $slots['user']['name'] ?? null,
            'limit' => $slots['limit'] ?? null], fn($v) => $v !== null);
        $r = Catalog::run($intent, $this->u, $slots);
        if (!empty($r['clarify'])) {
            $this->say($r['clarify']);
            $this->state = ['intent' => $intent, 'slots' => $slots, 'periods' => $this->state['periods'] ?? []];
            $this->log($q, $intent, $c['score'], 'clarify');
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
        $this->log($q, $intent, $c['score'], 'answered');
        return true;
    }

    /** Mise en forme : phrase (variée), indicateurs, tableau, graphique, lien, sources. */
    private function present(array $r, string $q): void
    {
        $text = Responder::pick($r['variants'] ?? [], $this->seed);
        if (setting('ai_llm_phrasing', '0') === '1' && $this->llm() && $text !== '' && empty($r['empty'])) {
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
        $this->log($this->question, 'capabilities', 1, 'answered');
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
            $this->log($q, 'help', 0, 'not_understood');
            return;
        }
        if ($this->llm()) {
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
                $this->log($q, 'help', 1, 'answered');
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
            $this->log($q, 'help', $best['score'], 'answered');
            return;
        }
        $this->legacyAsk($q);
    }

    // ------------------------------------------------------------------ moteur 1.x

    private function legacyAsk(string $q, ?string $label = null): void
    {
        if ($label !== null) {
            $this->out['intent'] ??= $label;
        }
        $r = Engine::answer($q, $this->state['legacy_ctx'] ?? null);
        if ($this->wrongPeriod($q, $r)) {
            $asked = Nlu::slots($q, $this->u)['period'];
            $this->out['intent'] ??= 'legacy:' . ($r['spec']['kind'] ?? 'rapport');
            $this->say('Je n\'ai pas pu appliquer la période « ' . $asked['label'] . ' » à ce rapport : pour ne pas vous donner les chiffres d\'une autre période, je préfère m\'arrêter. Indiquez les dates, par exemple « ventes du ' . date('d/m/Y', strtotime($asked['from'])) . ' au ' . date('d/m/Y', strtotime($asked['to'])) . ' en PDF ».');
            $this->log($q, $this->out['intent'], 0.3, 'clarify');
            return;
        }
        if (!empty($r['spec'])) {
            $this->state['legacy_ctx'] = $r['spec'];
        }
        $type = (string) ($r['type'] ?? '');
        $notFound = $type === 'help' && str_contains((string) ($r['html'] ?? ''), 'pas trouvé de réponse sûre');
        if ($notFound && $label === 'report') {
            // Pas de rapport PDF / Excel pour cette donnée : on donne au moins le résultat à l'écran
            $plain = trim(preg_replace('/\b(en |au format |un |le |fais moi |sors moi )?(pdf|excel|csv|rapport|exporte\w*|imprime\w*|telecharge\w*)\b/u', ' ', Text::norm($q)));
            $c2 = Nlu::classify($plain);
            if ($c2['score'] >= Nlu::THRESHOLD && isset(Catalog::TOOLS[$c2['intent']])) {
                $this->out['intent'] = null;
                if ($this->answerIntent($plain, $c2)) {
                    $this->out['intent'] = 'report';
                    $this->block('note', ['text' => 'Il n\'existe pas d\'export PDF / Excel pour cette donnée : voici le résultat à l\'écran. L\'écran d\'origine (lien ci-dessous) propose ses propres exports.']);
                    return;
                }
                $this->out['intent'] = 'report';
            }
        }
        if ($notFound && ($menu = AppMap::search($q, $this->u, ['menu'], 3)) && $menu[0]['score'] >= 4.5) {
            // Aucune fiche rédigée : on indique au moins l'écran où cela se fait (menus réels, filtrés par les droits)
            $this->say('Je n\'ai pas de procédure détaillée pour cette question, mais cela se passe ici : ' . implode(' ; ', array_map(fn($m) => $m['body'] ?: $m['title'], array_slice($menu, 0, 2)))
                . '. Ouvrez l\'écran avec le lien ci-dessous ; si un point reste flou, dites-moi ce que vous voyez à l\'écran.');
            foreach (array_slice($menu, 0, 2) as $m) {
                $this->out['links'][] = [$m['title'], explode('#', $m['ref'])[0]];
            }
            $this->log($q, $this->out['intent'] ?? 'help', 0.5, 'answered');
            return;
        }
        $this->legacy($r);
        $this->trace['slots'] = array_filter(['period' => Nlu::slots($q, $this->u)['period'] ?? null]);
        $this->log($q, $this->out['intent'] ?? 'legacy:' . ($r['spec']['kind'] ?? ($type ?: 'aide')), 0.5, $notFound ? 'not_understood' : ($type === 'error' ? 'refused' : 'fallback'));
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
        if ($this->llm()) {
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
            $this->log($q, 'legacy:' . ($r['spec']['kind'] ?? 'rapport'), 0.5, 'fallback');
            return;
        }
        if (count($c['tokens']) >= 2 && ($c['coverage'] ?? 1) <= 0.5 && $c['score'] < 0.6) {
            // La plupart des mots n'appartiennent pas au vocabulaire de la gestion ni du logiciel : sujet hors périmètre
            $this->out['intent'] = 'out_of_scope';
            $this->say('Ce sujet sort de ce que je connais. Je suis l\'assistant de POS Marwa : je réponds sur votre gestion (ventes, stock, caisse, clients, fournisseurs, finances) et sur l\'utilisation du logiciel. Si votre question concerne bien votre activité, reformulez-la avec d\'autres mots.');
            $this->out['suggestions'] = ['Que sais-tu faire ?', 'Fais-moi le point'];
            $this->log($q, 'out_of_scope', $c['score'], 'refused');
            return;
        }
        $this->out['intent'] = null;
        $this->say('Je suis spécialisé dans la gestion de votre commerce et l\'utilisation de POS Marwa : cette question semble hors de ce domaine, ou je ne l\'ai pas comprise, et je préfère ne pas deviner un chiffre. Si elle concerne votre activité, reformulez-la ; Par exemple : « valeur du stock », « ventes d\'hier », « qui me doit de l\'argent ? », « comment faire un inventaire ? ».');
        $this->out['suggestions'] = ['Fais-moi un bilan de la journée', 'Quelle est la valeur de mon stock ?', 'Que sais-tu faire ?'];
        $this->log($q, $c['intent'], $c['score'], 'not_understood');
    }
}
