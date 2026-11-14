<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

use Addons\MarwaIa\Text;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * MARWA AI TRAINING DATASET — bibliothèque de scénarios.
 *
 * Les scénarios intégrés sont écrits à la main dans addons/marwa_ia/data/scenarios/*.txt, un par ligne :
 *
 *   intention | rôle | question | formulations d'entraînement (;) | formulations de test (;) | attendus (clé=valeur ;)
 *
 * - « question » et « formulations d'entraînement » nourrissent le classifieur (exemples).
 * - « formulations de test » (fautes, écriture rapide, autres tournures) ne sont JAMAIS données au classifieur :
 *   elles servent à mesurer s'il comprend des phrases qu'il n'a pas vues.
 * - attendus : out (answer|clarify|refuse|propose|blocked|unknown), per / per2 (today, yesterday, week, last_week, month,
 *   last_month, year), loc, xloc, prod, cust, sup, qty, lim, ctx (code du scénario précédent de la conversation),
 *   also (autres intentions attendues), has (texte attendu dans la réponse), db (vérification chiffrée : voir Evaluation).
 *
 * Les autres rubriques du format complet (outil métier, droits, entités, conditions de clarification et de refus,
 * réponse attendue) sont déduites du dictionnaire des intentions, pour ne pas être recopiées — et contredites — mille fois.
 * Les scénarios ajoutés par un administrateur vivent dans la table ai_scenarios (source « admin »).
 */
final class Dataset
{
    private static ?array $builtin = null;

    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/data/scenarios';
    }

    public static function signature(): string
    {
        $h = '';
        foreach (glob(self::dir() . '/*.txt') ?: [] as $f) {
            $h .= basename($f) . filemtime($f) . filesize($f);
        }
        return md5($h);
    }

    /** Scénarios intégrés. @return array<int,array{code:string,domain:string,intent:string,role:string,question:string,train:string[],test:string[],expect:array<string,string>,source:string}> */
    public static function builtin(): array
    {
        if (self::$builtin !== null) {
            return self::$builtin;
        }
        $out = [];
        foreach (glob(self::dir() . '/*.txt') ?: [] as $f) {
            $domain = '';
            $prefix = 'CAS';
            $i = 0;
            foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $no => $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if ($line[0] === '#') {
                    if (preg_match('/^#\s*domaine\s*:\s*(.+)$/iu', $line, $m)) {
                        $domain = trim($m[1]);
                    } elseif (preg_match('/^#\s*code\s*:\s*([A-Z]+)/', $line, $m)) {
                        $prefix = $m[1];
                    }
                    continue;
                }
                $p = array_map('trim', preg_split('/\s*\|\s*/', $line));
                if (count($p) < 3) {
                    throw new \RuntimeException(basename($f) . ' ligne ' . ($no + 1) . ' : format invalide.');
                }
                $list = fn(?string $x) => array_values(array_filter(array_map('trim', explode(';', (string) $x)), fn($v) => $v !== ''));
                $expect = [];
                foreach ($list($p[5] ?? '') as $kv) {
                    [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
                    $expect[trim($k)] = trim($v);
                }
                $i++;
                $out[] = ['code' => sprintf('%s-%03d', $prefix, $i), 'domain' => $domain, 'intent' => $p[0], 'role' => $p[1] !== '' ? $p[1] : 'admin', 'question' => $p[2],
                    'train' => $list($p[3] ?? ''), 'test' => $list($p[4] ?? ''), 'expect' => $expect, 'source' => 'builtin'];
            }
        }
        return self::$builtin = $out;
    }

    /** Scénarios validés par l'administrateur (table ai_scenarios, source ≠ builtin). */
    public static function custom(): array
    {
        try {
            $rows = DB::all("SELECT * FROM ai_scenarios WHERE source <> 'builtin' AND status = 'active' ORDER BY id");
        } catch (\Throwable) {
            return [];
        }
        return array_map(fn($r) => ['code' => $r['code'], 'domain' => $r['domain'], 'intent' => $r['intent'], 'role' => $r['role'], 'question' => $r['question'],
            'train' => json_decode((string) $r['train_json'], true) ?: [], 'test' => json_decode((string) $r['test_json'], true) ?: [], 'expect' => json_decode((string) $r['expect_json'], true) ?: [], 'source' => $r['source']], $rows);
    }

    public static function all(): array
    {
        return array_merge(self::builtin(), self::custom());
    }

    /** Formulations d'entraînement par intention (jamais les formulations de test, ni les questions de suite ou multiples). */
    public static function training(): array
    {
        $ex = [];
        foreach (self::all() as $s) {
            if (isset($s['expect']['ctx']) || isset($s['expect']['also']) || ($s['expect']['train'] ?? '') === 'no') {
                continue;
            }
            $ex[$s['intent']][] = $s['question'];
            foreach ($s['train'] as $t) {
                $ex[$s['intent']][] = $t;
            }
        }
        return $ex;
    }

    // ------------------------------------------------------------------ dictionnaire des intentions

    /** Paramètres que chaque famille d'intentions sait lire. */
    private const PARAMS = [
        'period' => ['sales_summary', 'revenue', 'profit', 'expenses_total', 'cash_in', 'cash_out', 'top_products', 'sales_by_user', 'sales_by_store', 'sales_by_method', 'sales_by_category', 'cancelled_sales', 'returns',
            'discounts_tax', 'purchases_total', 'income_vs_expenses', 'daily_report', 'activity_summary', 'anomalies', 'top_cashier', 'epay_summary', 'fiscal_summary', 'transfers', 'stock_in_total', 'product_sales',
            'items_sold', 'customer_payments', 'expenses_by_category', 'margin_rate', 'most_profitable', 'best_day', 'sales_compare', 'sales_decline', 'cash_difference', 'adjustments', 'stock_movements', 'report'],
        'location' => ['stock_value', 'stock_value_sale', 'stock_count', 'stock_out', 'stock_low', 'product_stock', 'dormant', 'reorder', 'sales_summary', 'revenue', 'top_products', 'action_stock_add', 'action_inventory_start'],
        'product' => ['product_stock', 'product_info', 'product_sales', 'stock_movements', 'action_stock_add'],
        'customer' => ['customer_info', 'report'],
        'supplier' => ['supplier_info'],
        'quantity' => ['action_stock_add'],
        'amount' => ['action_cash_out'],
        'limit' => ['top_products', 'last_sales', 'last_transactions', 'stock_movements', 'most_profitable'],
    ];

    /** Fiche d'une intention : libellé, module, outil métier, droits, paramètres, conditions de clarification et de refus. */
    public static function intent(string $code): array
    {
        $params = array_keys(array_filter(self::PARAMS, fn($list) => in_array($code, $list, true)));
        if (isset(Catalog::TOOLS[$code])) {
            [$label, $module, $perms, $method] = Catalog::TOOLS[$code];
            $clar = [];
            foreach (['product' => 'l\'article n\'est pas nommé ou plusieurs articles correspondent', 'customer' => 'le client n\'est pas nommé ou plusieurs clients correspondent', 'supplier' => 'le fournisseur n\'est pas nommé',
                'location' => 'la boutique ou le magasin cité est ambigu'] as $k => $why) {
                if (in_array($k, $params, true)) {
                    $clar[] = $why;
                }
            }
            return ['code' => $code, 'label' => $label, 'module' => $module, 'kind' => 'lecture', 'tool' => 'Catalog::run(' . $code . ') → ' . (str_contains($method, '::') ? $method : 'Catalog::' . $method) . ' (lecture seule)',
                'permissions' => $perms, 'params' => $params, 'clarify' => $clar ?: ['question trop vague pour choisir une donnée'],
                'refuse' => $perms ? ['le rôle de l\'utilisateur n\'a pas le droit : ' . implode(' ou ', array_map(fn($p) => str_replace('|', ' ou ', $p), $perms))] : [],
                'response' => 'Phrase avec les chiffres lus dans la base' . (in_array('period', $params, true) ? ', pour la période demandée' : '') . ', indicateurs / tableau / graphique selon le cas, sources affichées. Aucun chiffre si rien n\'est trouvé.'];
        }
        if (str_starts_with($code, 'action_')) {
            $tool = substr($code, 7);
            $t = Actions::TOOLS[$tool] ?? null;
            return ['code' => $code, 'label' => $t[0] ?? 'Opération non prise en charge', 'module' => 'actions', 'kind' => 'action', 'tool' => $tool === 'unsupported' ? 'aucun (explication de l\'écran à utiliser)' : 'Actions::propose(' . $tool . ') puis, après confirmation, fonction métier du logiciel',
                'permissions' => $t && $t[2] ? [$t[2]] : [], 'params' => $params,
                'clarify' => $tool === 'stock_add' ? ['article absent ou ambigu', 'quantité absente', 'magasin ambigu', 'demande ambiguë entre entrée de stock et achat fournisseur'] : ['paramètre obligatoire manquant (montant, motif, numéro…)'],
                'refuse' => $t && $t[2] ? ['droit « ' . $t[2] . ' » manquant', 'opération impossible dans l\'état des données (caisse fermée, inventaire déjà en cours, article suivi par lot…)'] : ['opération que l\'assistant ne fait pas lui-même'],
                'response' => 'Résumé de l\'opération préparée et demande de confirmation' . (($t[3] ?? 0) ? ' avec mot de passe' : '') . '. Comprendre la demande ne vaut pas autorisation : rien n\'est exécuté sans confirmation.'];
        }
        $fixed = [
            'help' => ['Aide et procédures', 'aide', 'Base de connaissances + inventaire des menus + documentation', 'Procédure pas à pas avec les liens vers les écrans.'],
            'capabilities' => ['Ce que l\'assistant sait faire', 'assistant', 'Liste filtrée par les droits de l\'utilisateur', 'Présentation des capacités selon le rôle.'],
            'definition' => ['Définition d\'un terme de gestion', 'aide', 'Transactions::explain / General::concept', 'Définition courte du terme.'],
            'transaction_types' => ['Types de transactions', 'aide', 'Transactions::listing', 'Tableau des types de mouvements et de leurs effets.'],
            'report' => ['Rapport PDF / Excel', 'rapports', 'Moteur 1.x (Engine + Reports), soumis aux droits', 'Aperçu du rapport et boutons PDF / Excel ; refus si la période ne peut pas être appliquée.'],
            'training' => ['Formation et quiz', 'formation', 'Moteur 1.x (Trainer)', 'Leçon pas à pas ou quiz.'],
            'marketing' => ['Coach marketing', 'marketing', 'Moteur 1.x (Marketing)', 'Conseils et textes prêts à publier.'],
            'checkup' => ['Contrôle de la configuration', 'configuration', 'Moteur 1.x (Checkup)', 'Liste des réglages à compléter.'],
            'advice_topic' => ['Conseils thématiques', 'conseils', 'Moteur 1.x (Advisor)', 'Conseils sur le thème demandé, appuyés sur quelques chiffres.'],
            'security' => ['Tentative de manipulation', 'sécurité', 'Guard::check (aucune exécution)', 'Refus expliqué ; aucun secret, aucune écriture, aucun contournement.'],
            'out_of_scope' => ['Hors périmètre', 'limites', 'aucun', 'L\'assistant dit que le sujet sort de son domaine, sans inventer.'],
        ];
        if (isset($fixed[$code])) {
            [$label, $module, $tool, $resp] = $fixed[$code];
            return ['code' => $code, 'label' => $label, 'module' => $module, 'kind' => 'route', 'tool' => $tool, 'permissions' => in_array($code, ['report'], true) ? ['droit du rapport demandé'] : [], 'params' => $params,
                'clarify' => [], 'refuse' => in_array($code, ['security', 'out_of_scope'], true) ? ['toujours'] : [], 'response' => $resp];
        }
        if (General::isGeneral($code)) {
            return ['code' => $code, 'label' => ['greeting' => 'Salutation', 'thanks' => 'Remerciement', 'wellbeing' => 'Prise de nouvelles', 'goodbye' => 'Au revoir', 'joke' => 'Plaisanterie', 'praise' => 'Compliment',
                'frustration' => 'Insatisfaction de l\'utilisateur', 'app_purpose' => 'À quoi sert le logiciel', 'app_name' => 'Nom et auteur du logiciel', 'concept' => 'Notion de gestion'][$code], 'module' => 'conversation', 'kind' => 'conversation',
                'tool' => 'General::reply (texte rédigé, sans chiffre de l\'entreprise)', 'permissions' => [], 'params' => [], 'clarify' => [], 'refuse' => [], 'response' => 'Réponse naturelle et brève ; pour une notion, explication rédigée.'];
        }
        return ['code' => $code, 'label' => $code, 'module' => '?', 'kind' => '?', 'tool' => '?', 'permissions' => [], 'params' => [], 'clarify' => [], 'refuse' => [], 'response' => ''];
    }

    /** Tous les codes d'intention connus (catalogue, actions, conversation, routes). */
    public static function intentCodes(): array
    {
        return array_merge(array_keys(Catalog::TOOLS), array_map(fn($k) => 'action_' . $k, array_keys(Actions::TOOLS)), ['action_unsupported'], array_keys(General::EXAMPLES),
            ['help', 'capabilities', 'definition', 'transaction_types', 'report', 'training', 'marketing', 'checkup', 'advice_topic', 'security', 'out_of_scope']);
    }

    /** Issue attendue par défaut d'un scénario. */
    public static function defaultOutcome(string $intent): string
    {
        if ($intent === 'action_unsupported' || in_array($intent, ['security', 'out_of_scope'], true)) {
            return 'refuse';
        }
        if ($intent === 'action_navigate') {
            return 'answer';
        }
        return str_starts_with($intent, 'action_') ? 'propose' : 'answer';
    }

    /** Scénario au format complet (toutes les rubriques demandées). */
    public static function expand(array $s): array
    {
        $i = self::intent($s['intent']);
        $e = $s['expect'];
        $entities = [];
        foreach (['per' => 'période', 'per2' => 'seconde période', 'loc' => 'boutique / magasin', 'xloc' => 'lieu exclu', 'prod' => 'article', 'cust' => 'client', 'sup' => 'fournisseur', 'qty' => 'quantité', 'lim' => 'nombre'] as $k => $label) {
            if (isset($e[$k])) {
                $entities[$label] = $e[$k];
            }
        }
        $out = $e['out'] ?? self::defaultOutcome($s['intent']);
        return ['id' => $s['code'], 'domaine' => $s['domain'], 'intention' => $s['intent'], 'libelle' => $i['label'], 'intentions_secondaires' => isset($e['also']) ? explode(',', $e['also']) : [],
            'question' => $s['question'], 'variantes' => $s['train'], 'variantes_fautes_et_test' => $s['test'], 'synonymes' => self::synonymsIn(array_merge([$s['question']], $s['train'], $s['test'])),
            'contexte' => $e['ctx'] ?? null, 'role' => $s['role'], 'entites' => $entities, 'parametres' => $i['params'], 'outil' => $i['tool'], 'permissions' => $i['permissions'],
            'reponse_attendue' => $i['response'], 'clarification_si' => $i['clarify'], 'refus_si' => $i['refuse'],
            'resultat_attendu' => ['issue' => $out, 'intention' => $s['intent']] + array_intersect_key($e, array_flip(['per', 'per2', 'loc', 'xloc', 'prod', 'cust', 'sup', 'qty', 'lim', 'has', 'db', 'also']))];
    }

    /** Synonymes du dictionnaire rencontrés dans les formulations d'un scénario (mot → forme canonique). */
    private static function synonymsIn(array $phrases): array
    {
        $found = [];
        foreach ($phrases as $p) {
            foreach (explode(' ', Text::norm($p)) as $w) {
                $c = Nlu::canonical($w);
                if ($c !== null && $c !== Text::stem($w) && $c !== $w) {
                    $found[$w] = $c;
                }
            }
        }
        return $found;
    }

    /** Recopie les scénarios intégrés dans la table ai_scenarios (consultation, filtres, export SQL). */
    public static function sync(): int
    {
        $n = 0;
        $codes = [];
        foreach (self::builtin() as $s) {
            $codes[] = $s['code'];
            DB::run("INSERT INTO ai_scenarios (code, domain, intent, role, question, train_json, test_json, expect_json, source, status, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'builtin', 'active', NOW())
                ON DUPLICATE KEY UPDATE domain = VALUES(domain), intent = VALUES(intent), role = VALUES(role), question = VALUES(question), train_json = VALUES(train_json), test_json = VALUES(test_json), expect_json = VALUES(expect_json), source = 'builtin'",
                [$s['code'], $s['domain'], $s['intent'], $s['role'], mb_substr($s['question'], 0, 500), json_encode($s['train'], JSON_UNESCAPED_UNICODE), json_encode($s['test'], JSON_UNESCAPED_UNICODE), json_encode($s['expect'], JSON_UNESCAPED_UNICODE)]);
            $n++;
        }
        if ($codes) {
            DB::run("DELETE FROM ai_scenarios WHERE source = 'builtin' AND code NOT IN (" . implode(',', array_fill(0, count($codes), '?')) . ')', $codes);
        }
        return $n;
    }

    /** Nouveau scénario validé par un administrateur (utilisé dès la question suivante). */
    public static function add(array $in): string
    {
        $intent = (string) ($in['intent'] ?? '');
        $q = trim((string) ($in['question'] ?? ''));
        if (!in_array($intent, self::intentCodes(), true) && !DB::value('SELECT 1 FROM ai_intents WHERE code = ? AND active = 1', [$intent])) {
            throw new BusinessException('Intention inconnue.');
        }
        if (mb_strlen($q) < 4) {
            throw new BusinessException('Question trop courte.');
        }
        $split = fn($x) => array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/', (string) $x)), fn($v) => mb_strlen($v) >= 3));
        $n = (int) DB::value("SELECT COUNT(*) FROM ai_scenarios WHERE source <> 'builtin'") + 1;
        do {
            $code = sprintf('ADM-%04d', $n++);
        } while (DB::value('SELECT 1 FROM ai_scenarios WHERE code = ?', [$code]));
        $role = preg_match('/^[a-z0-9._-]{2,40}$/i', (string) ($in['role'] ?? '')) ? (string) $in['role'] : 'admin';
        DB::insert('ai_scenarios', ['code' => $code, 'domain' => mb_substr(trim((string) ($in['domain'] ?? '')) ?: 'Ajouts de l\'administrateur', 0, 80), 'intent' => $intent, 'role' => $role, 'question' => mb_substr($q, 0, 500),
            'train_json' => json_encode(array_slice($split($in['train'] ?? ''), 0, 20), JSON_UNESCAPED_UNICODE), 'test_json' => json_encode(array_slice($split($in['test'] ?? ''), 0, 20), JSON_UNESCAPED_UNICODE),
            'expect_json' => json_encode(array_filter(['out' => in_array($in['out'] ?? '', ['answer', 'clarify', 'refuse', 'propose'], true) ? $in['out'] : null]), JSON_UNESCAPED_UNICODE),
            'source' => 'admin', 'status' => 'active', 'created_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')]);
        Nlu::reset();
        Learning::version('scenario', $code, 'Scénario ajouté pour l\'intention « ' . $intent . ' » : ' . mb_substr($q, 0, 120));
        return $code;
    }
}
