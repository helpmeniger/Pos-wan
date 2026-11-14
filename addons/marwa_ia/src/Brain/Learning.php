<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

use Addons\MarwaIa\Knowledge;
use Addons\MarwaIa\Text;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * MARWA AI CONTINUOUS LEARNING.
 * - Journal des questions (comprises, non comprises, refusées) et avis 👍 / 👎 des utilisateurs.
 * - L'administrateur VALIDE les corrections : une question mal comprise devient un nouvel exemple d'intention,
 *   ou une réponse rédigée entre dans la base de connaissances. Rien n'est appris automatiquement des conversations
 *   brutes, aucun poids de modèle n'est modifié ; la question enregistrée est anonymisée (nombres et noms retirés).
 * - Banc de tests de compréhension et tableau de bord de qualité.
 */
final class Learning
{
    public const SEED_VERSION = '3.0.0';

    /** Remplit les bibliothèques intégrées (idempotent, mis à jour quand la version change). */
    public static function seed(bool $force = false): void
    {
        if (!$force && setting('ai_seed_version', '') === self::SEED_VERSION) {
            return;
        }
        foreach (Catalog::TOOLS as $code => [$label, $module, $perms]) {
            DB::run('INSERT INTO ai_intents (code, label, module, tool, permission, builtin) VALUES (?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE label = VALUES(label), module = VALUES(module), permission = VALUES(permission)',
                [$code, $label, $module, $code, implode(',', $perms) ?: null]);
        }
        foreach (Nlu::EXAMPLES as $code => $list) {
            foreach ($list as $e) {
                DB::run("INSERT IGNORE INTO ai_intent_examples (intent_code, text, normalized, source) VALUES (?, ?, ?, 'builtin')", [$code, $e, Text::norm($e)]);
            }
        }
        foreach (Actions::TOOLS as $code => [$label, $kind, $perm, $sensitive, $desc]) {
            DB::run('INSERT INTO ai_tool_registry (code, label, kind, permission, is_sensitive, needs_confirmation, description) VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE label = VALUES(label), permission = VALUES(permission), is_sensitive = VALUES(is_sensitive), description = VALUES(description)',
                [$code, $label, $kind, $perm, $sensitive, $kind === 'action' ? 1 : 0, $desc]);
        }
        foreach (Catalog::TOOLS as $code => [$label, , $perms]) {
            DB::run("INSERT INTO ai_tool_registry (code, label, kind, permission, description) VALUES (?, ?, 'read', ?, 'Consultation en lecture seule')
                ON DUPLICATE KEY UPDATE label = VALUES(label), permission = VALUES(permission)", ['data_' . $code, $label, implode(',', $perms) ?: null]);
        }
        // Intentions hors catalogue (conversation, aide, actions, limites) : visibles et désactivables dans le centre d'entraînement
        foreach (Dataset::intentCodes() as $code) {
            if (!isset(Catalog::TOOLS[$code])) {
                $i = Dataset::intent($code);
                DB::run('INSERT INTO ai_intents (code, label, module, tool, permission, builtin) VALUES (?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE label = VALUES(label), module = VALUES(module)',
                    [$code, mb_substr($i['label'], 0, 160), $i['module'], mb_substr($code, 0, 60), implode(',', $i['permissions']) ?: null]);
            }
        }
        try {
            Dataset::sync();
        } catch (\Throwable $e) {
            \App\Core\Logger::error('marwa_ai_dataset', ['error' => $e->getMessage()]);
        }
        foreach (array_merge(Knowledge::FAQ, self::EXTRA_FAQ) as [$keys, $title, $body, $links]) {
            if (!DB::value("SELECT 1 FROM ai_knowledge_base WHERE source = 'builtin' AND title = ?", [$title])) {
                DB::insert('ai_knowledge_base', ['kind' => 'faq', 'title' => $title, 'keywords' => $keys, 'body' => $body, 'links_json' => json_encode($links, JSON_UNESCAPED_UNICODE), 'source' => 'builtin']);
            }
        }
        // Reprise de l'ancien réglage Ollama (MARWA IA 1.x) dans le gestionnaire de modèles
        if (setting('ia_llm_url', '') !== '' && setting('ia_llm_model', '') !== '' && !DB::value("SELECT 1 FROM ai_model_settings WHERE provider = 'ollama'")) {
            DB::insert('ai_model_settings', ['name' => 'Ollama (repris de MARWA IA 1.x)', 'provider' => 'ollama', 'base_url' => setting('ia_llm_url'), 'model' => setting('ia_llm_model'),
                'external_ack' => 1, 'active' => 1, 'is_default' => DB::value('SELECT 1 FROM ai_model_settings WHERE is_default = 1') ? 0 : 1]);
        }
        setting_set('ai_seed_version', self::SEED_VERSION);
        Nlu::reset();
    }

    /** Fiches de dépannage ajoutées en 3.0 (questions « pourquoi ça ne marche pas ? »). */
    public const EXTRA_FAQ = [
        ['pourquoi valider vente impossible marche pas bloque refuse caisse valide encaisser erreur', 'Pourquoi je n\'arrive pas à valider une vente ?',
            '<p>La caisse refuse une vente dans ces cas :</p><ol><li><b>Caisse fermée</b> : ouvrez-la (Ma caisse → Ouvrir, avec le fond de caisse).</li><li><b>Panier vide</b> ou quantité à zéro.</li><li><b>Stock insuffisant</b> : l\'article n\'a plus assez de stock et les ventes en stock négatif ne sont pas autorisées — enregistrez la réception ou corrigez le stock.</li><li><b>Paiement insuffisant</b> : la somme saisie est inférieure au total ; pour une vente à crédit, choisissez d\'abord un client.</li><li><b>Plafond de crédit</b> du client dépassé.</li><li><b>Remise ou prix modifié</b> au-delà de ce que votre rôle autorise : un responsable doit saisir son code.</li><li><b>Article sur ordonnance</b> (pharmacie) : les informations de l\'ordonnance sont obligatoires.</li></ol><p>Le message affiché en rouge dans la fenêtre d\'encaissement indique la cause exacte.</p>', [['Ma caisse', '/cash'], ['Écran de caisse', '/pos']]],
        ['imprimante ticket imprime pas sort pas papier blanc thermique impression', 'Le ticket ne s\'imprime pas : que vérifier ?',
            '<ol><li>L\'imprimante est allumée, branchée, et son voyant n\'est pas rouge (capot fermé, papier présent).</li><li>Le rouleau est dans le bon sens : le papier thermique ne noircit que d\'un côté — un ticket qui sort blanc est un rouleau à l\'envers.</li><li>La bonne imprimante est choisie dans la fenêtre d\'impression du navigateur, ou dans Configuration → Impression pour une imprimante réseau.</li><li>La largeur du ticket (58 ou 80 mm) correspond au papier.</li><li>Réimprimez depuis la caisse : bouton <b>Tickets</b> → la vente → Ticket.</li></ol>', [['Paramètres', '/settings']]],
        ['connexion connecter mot passe oublie bloque compte identifiant login', 'Je n\'arrive pas à me connecter',
            '<ol><li>Vérifiez l\'identifiant et les majuscules du mot de passe (touche Verr. Maj).</li><li>Après plusieurs essais ratés, le compte est bloqué quelques minutes : patientez.</li><li>Mot de passe oublié : un administrateur le réinitialise dans Configuration → Utilisateurs.</li><li>Si la page ne s\'ouvre pas du tout, le serveur de la boutique est peut-être éteint ou le réseau coupé.</li></ol>', [['Utilisateurs', '/users']]],
        ['ecart caisse manque excedent cloture difference compte juste tombe pas', 'Ma caisse ne tombe pas juste : que faire ?',
            '<ol><li>Recomptez les espèces, coupure par coupure.</li><li>Dans Ma caisse, relisez les mouvements de la session : une sortie ou une dépense oubliée, ou saisie deux fois.</li><li>Vérifiez les paiements saisis en espèces alors qu\'ils étaient en mobile money ou par carte.</li><li>Notez l\'écart et son explication à la clôture : il reste visible dans Sessions de caisse.</li></ol><p>Demandez-moi « c\'est quoi le problème avec ma caisse ? » pour un contrôle des enregistrements.</p>', [['Ma caisse', '/cash'], ['Sessions de caisse', '/cash/sessions']]],
        ['stock negatif faux incorrect corriger erreur quantite mauvais', 'Le stock affiché est faux : comment le corriger ?',
            '<ol><li>Une marchandise reçue mais pas saisie : enregistrez la réception (Achats) ou un <b>Ajout de stock rapide</b>.</li><li>Une perte ou de la casse : Gestion des articles → <b>Ajustement / pertes</b>, avec le motif.</li><li>Un doute sur plusieurs articles : faites un <b>inventaire</b> ; à sa validation, le logiciel corrige les écarts et en garde la trace.</li></ol><p>On ne modifie jamais une quantité « à la main » sans mouvement : c\'est ce qui rend l\'historique fiable.</p>', [['Ajout de stock rapide', '/stock/quick'], ['Inventaires', '/inventories']]],
        ['article introuvable caisse trouve pas apparait pas recherche scanner code barres', 'Un article n\'apparaît pas à la caisse',
            '<ol><li>Il est peut-être <b>archivé</b> ou marqué <b>non vendable</b> : ouvrez sa fiche (Gestion des articles → Articles, filtre « Tous »).</li><li>Son code-barres n\'est pas saisi ou est différent de celui de l\'emballage : scannez-le dans la fiche.</li><li>Il vient d\'être créé : rechargez l\'écran de caisse.</li><li>Il appartient à un autre canal de vente (restaurant, boutique en ligne).</li></ol>', [['Articles', '/products']]],
    ];

    /** Question anonymisée pour le journal d'apprentissage (chiffres, numéros et téléphones retirés). */
    public static function anonymize(string $q): string
    {
        $q = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[COURRIEL]', $q);
        $q = preg_replace('/\b[A-Z]{1,3}\d{4}-\d{3,6}\b/i', '[N°]', (string) $q);
        $q = preg_replace('/\+?\d[\d \-]{6,}\d/', '[NUMÉRO]', (string) $q);
        return mb_substr((string) $q, 0, 500);
    }

    public static function log(string $q, ?string $intent, float $conf, string $outcome): void
    {
        $a = self::anonymize($q);
        DB::insert('ai_learning_logs', ['user_id' => Auth::id(), 'question' => $a, 'normalized' => mb_substr(Text::norm($a), 0, 500), 'intent_guess' => $intent, 'confidence' => round($conf, 3), 'outcome' => $outcome]);
    }

    public static function feedback(int $messageId, int $rating, ?string $comment): void
    {
        $uid = Auth::id();
        $ok = DB::value("SELECT 1 FROM ai_messages m JOIN ai_conversations c ON c.id = m.conversation_id WHERE m.id = ? AND c.user_id = ? AND m.role = 'assistant'", [$messageId, $uid]);
        if (!$ok) {
            throw new BusinessException('Message introuvable.');
        }
        DB::run('INSERT INTO ai_feedback (message_id, user_id, rating, comment) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), status = \'new\'',
            [$messageId, $uid, $rating >= 0 ? 1 : -1, $comment !== null ? mb_substr(trim($comment), 0, 500) : null]);
    }

    /** Historique des améliorations de la connaissance (qui a changé quoi, quand). */
    public static function version(string $kind, ?string $ref, string $summary): void
    {
        try {
            DB::insert('ai_knowledge_versions', ['kind' => $kind, 'ref' => $ref !== null ? mb_substr($ref, 0, 120) : null, 'summary' => mb_substr($summary, 0, 500), 'user_id' => Auth::id()]);
        } catch (\Throwable) {
            // table absente (migration non encore passée) : l'historique est simplement ignoré
        }
    }

    /** Correction validée : la question devient un exemple de l'intention choisie. */
    public static function teach(int $logId, string $intent): void
    {
        if (!isset(Catalog::TOOLS[$intent])) {
            throw new BusinessException('Intention inconnue.');
        }
        $l = DB::one('SELECT * FROM ai_learning_logs WHERE id = ?', [$logId]);
        if (!$l) {
            throw new BusinessException('Question introuvable.');
        }
        self::addExample($intent, $l['question']);
        DB::update('ai_learning_logs', ['resolved' => 1, 'resolution' => 'Exemple ajouté à « ' . Catalog::TOOLS[$intent][0] . ' »'], 'id = ?', [$logId]);
    }

    public static function addExample(string $intent, string $text): void
    {
        $norm = Text::norm($text);
        if (mb_strlen($norm) < 4) {
            throw new BusinessException('Exemple trop court.');
        }
        DB::run("INSERT IGNORE INTO ai_intent_examples (intent_code, text, normalized, source, validated_by) VALUES (?, ?, ?, 'admin', ?)", [$intent, mb_substr($text, 0, 300), mb_substr($norm, 0, 300), Auth::id()]);
        \App\Core\Audit::log('ai.example_add', 'ai_intent', null, ['intent' => $intent]);
        Nlu::reset();
    }

    public static function saveKnowledge(array $in, ?int $id = null): int
    {
        $title = trim((string) ($in['title'] ?? ''));
        $body = trim((string) ($in['body'] ?? ''));
        if ($title === '' || mb_strlen($body) < 10) {
            throw new BusinessException('Titre et contenu (10 caractères au moins) obligatoires.');
        }
        $kind = in_array($in['kind'] ?? '', ['faq', 'procedure', 'validated_answer', 'note'], true) ? $in['kind'] : 'procedure';
        $data = ['kind' => $kind, 'title' => mb_substr($title, 0, 200), 'keywords' => mb_substr(trim((string) ($in['keywords'] ?? '')), 0, 500) ?: null, 'body' => e($body) === $body ? nl2br($body) : nl2br(e($body)),
            'module' => mb_substr(trim((string) ($in['module'] ?? '')), 0, 60) ?: null, 'permission' => preg_match('/^[a-z_.]{3,60}$/', (string) ($in['permission'] ?? '')) ? $in['permission'] : null,
            'active' => !empty($in['active']) || !$id ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')];
        if ($id) {
            DB::update('ai_knowledge_base', $data, 'id = ? AND source <> \'builtin\'', [$id]);
        } else {
            $id = DB::insert('ai_knowledge_base', $data + ['source' => 'admin', 'created_by' => Auth::id()]);
        }
        \App\Core\Audit::log('ai.knowledge_save', 'ai_knowledge_base', $id, ['title' => $title]);
        return $id;
    }

    /** Recherche dans la base de connaissances (FAQ intégrée + réponses validées), filtrée par droits. */
    public static function searchKnowledge(string $q, UserContext $u, int $limit = 3): array
    {
        $qs = array_values(array_unique(Text::stems($q)));
        if (!$qs) {
            return [];
        }
        $out = [];
        foreach (DB::all('SELECT * FROM ai_knowledge_base WHERE active = 1') as $k) {
            if ($k['permission'] && !$u->allows([$k['permission']])) {
                continue;
            }
            $hay = Text::stems($k['title'] . ' ' . $k['keywords'] . ' ' . $k['keywords']);
            $hit = count(array_intersect($qs, $hay));
            $score = $hit / max(2, count($qs)) + ($k['source'] !== 'builtin' ? 0.15 : 0);
            if ($hit > 0) {
                $out[] = $k + ['score' => $score];
            }
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, $limit);
    }

    /** Tableau de bord de qualité. */
    public static function dashboard(): array
    {
        $since = date('Y-m-d', strtotime('-30 days'));
        $o = array_column(DB::all('SELECT outcome, COUNT(*) n FROM ai_learning_logs WHERE created_at >= ? GROUP BY outcome', [$since]), 'n', 'outcome');
        $total = array_sum($o);
        $fb = DB::one('SELECT SUM(rating > 0) up, SUM(rating < 0) down FROM ai_feedback WHERE created_at >= ?', [$since]);
        return ['total' => $total, 'outcomes' => $o, 'understood' => $total ? round((($o['answered'] ?? 0) + ($o['fallback'] ?? 0)) / $total * 100, 1) : null,
            'up' => (int) ($fb['up'] ?? 0), 'down' => (int) ($fb['down'] ?? 0),
            'examples' => (int) DB::value("SELECT COUNT(*) FROM ai_intent_examples"), 'admin_examples' => (int) DB::value("SELECT COUNT(*) FROM ai_intent_examples WHERE source <> 'builtin'"),
            'knowledge' => (int) DB::value('SELECT COUNT(*) FROM ai_knowledge_base WHERE active = 1')];
    }

    /** Banc de tests de compréhension : [question, intention attendue] → résultats. */
    public static function bench(array $cases): array
    {
        $res = [];
        foreach ($cases as [$q, $expected]) {
            $c = Nlu::classify($q);
            $got = $c['score'] >= Nlu::THRESHOLD ? $c['intent'] : null;
            $res[] = ['q' => $q, 'expected' => $expected, 'got' => $got, 'score' => $c['score'], 'ok' => $got === $expected];
        }
        return $res;
    }

    /** Jeu de tests intégré (formulations variées, fautes, familier) utilisé par l'écran « Amélioration ». */
    public const BENCH = [
        ['Combien j\'ai de stock ?', 'stock_value'], ['Donne-moi la valeur du stock.', 'stock_value'], ['Quelle est la valeur totale de ma marchandise ?', 'stock_value'],
        ['Combien valent les articles actuellement en magasin ?', 'stock_value'], ['Mon stock représente combien d\'argent ?', 'stock_value'], ['valeur du stok', 'stock_value'],
        ['Donne-moi le nombre de tickets sortis.', 'sales_summary'], ['Combien de tickets ont été générés ?', 'sales_summary'], ['Combien de tickets ont été créés ?', 'sales_summary'],
        ['Fais-moi le total des tickets produits.', 'sales_summary'], ['Combien de tickets ont été émis ?', 'sales_summary'], ['combien jai vendu ojourdui', 'sales_summary'],
        ['Quels sont les produits en rupture ?', 'stock_out'], ['produits épuisés', 'stock_out'], ['Quels produits ont un stock faible ?', 'stock_low'],
        ['Quel est le chiffre d\'affaires de ce mois ?', 'revenue'], ['mon CA du mois', 'revenue'], ['Quel est le bénéfice estimé ?', 'profit'], ['est-ce que je gagne de l\'argent', 'profit'],
        ['Qui me doit de l\'argent ?', 'customer_debts'], ['Quels fournisseurs doivent être payés ?', 'supplier_payables'], ['Fais-moi un bilan de la journée', 'daily_report'],
        ['Qui a effectué la dernière sortie de caisse ?', 'last_cash_out'], ['Quelle est la date et l\'heure du dernier ticket ?', 'last_sale'], ['Quel caissier a réalisé le plus de ventes ?', 'top_cashier'],
        ['Compare les ventes de cette semaine avec la semaine dernière', 'sales_compare'], ['Pourquoi mes ventes ont-elles diminué cette semaine ?', 'sales_decline'],
        ['Quels produits dois-je recommander ?', 'reorder'], ['Identifie les anomalies dans les transactions', 'anomalies'], ['Combien de tickets wifi sont expirés ?', 'wifi_tickets'],
    ];
}
