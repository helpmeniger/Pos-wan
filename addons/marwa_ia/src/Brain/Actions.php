<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

use Addons\MarwaIa\Text;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Services\CashService;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\SaleService;

/**
 * MARWA AI ACTION ENGINE.
 * L'assistant PRÉPARE une opération ; elle n'est exécutée qu'après une confirmation explicite de l'utilisateur
 * (jeton à usage unique, valable 10 minutes), avec revérification de ses droits au moment de l'exécution.
 * Les opérations financières ou irréversibles exigent en plus son mot de passe ou son code PIN (autorisation renforcée).
 * Toutes passent par les fonctions métier existantes de POS Marwa (mêmes règles que les écrans) et sont journalisées.
 * L'assistant n'annonce jamais une opération comme faite si elle n'a pas réellement été exécutée.
 */
final class Actions
{
    /** code => [libellé, type, permission, sensible (autorisation renforcée), description] */
    public const TOOLS = [
        'navigate' => ['Ouvrir une page', 'navigate', null, 0, 'Lien direct vers un écran autorisé.'],
        'report_pdf' => ['Rapport PDF / Excel', 'report', 'reports.view', 0, 'Rapport détaillé du moteur de rapports existant.'],
        'purchase_draft' => ['Brouillon de commande fournisseur', 'action', 'purchases.manage', 0, 'Crée des commandes au statut « brouillon » à partir des articles à recommander.'],
        'inventory_start' => ['Préparer un inventaire', 'action', 'stock.inventory', 0, 'Ouvre un inventaire en cours (aucun stock modifié avant validation).'],
        'stock_add' => ['Ajout de stock', 'action', 'stock.adjust', 0, 'Ajoute une quantité au stock d\'un article (entrée de stock rapide), après confirmation.'],
        'cash_out' => ['Sortie de caisse', 'action', 'cash.movements', 1, 'Retire des espèces de la caisse ouverte de l\'utilisateur.'],
        'sale_cancel' => ['Annuler une vente', 'action', 'pos.cancel_sale', 1, 'Annule une vente (stock, caisse et crédit contre-passés), dans les délais autorisés.'],
    ];
    /** Exemples d'entraînement du classifieur (une demande d'action reste une proposition à confirmer). */
    public const EXAMPLES = [
        'action_stock_add' => ['ajoute 50 unites a l article eau minerale', 'mets 50 dans le stock d eau', 'rajoute 50 eaux minerales', 'augmente le stock de l eau de 50', 'il faut ajouter 50 eau minerale',
            'ajoute 50 bouteilles d eau', 'ajoute un stock rapide a l article eau minerale 50', 'entre 20 sacs de riz en stock', 'ajoute 10 cartons de savon au magasin', 'fais une entree de stock de 30 sucre',
            'incremente le stock du lait de 12', 'j ai recu 40 huile ajoute les', 'ajoute du stock', 'ajoute 20 dans le magasin mais pas dans la boutique'],
        'action_purchase_draft' => ['prepare une commande pour les produits manquants', 'prepare un brouillon de commande fournisseur', 'cree un bon de commande pour les ruptures', 'fais la commande de reapprovisionnement',
            'genere une commande fournisseur', 'commande ce qui manque'],
        'action_inventory_start' => ['prepare un inventaire du depot', 'ouvre un inventaire', 'demarre un inventaire du magasin principal', 'lance un inventaire', 'commence l inventaire'],
        'action_cash_out' => ['fais une sortie de caisse de 5000 pour le transport', 'retire 2000 de la caisse pour le pain', 'enregistre une sortie de caisse', 'sors 10000 de la caisse motif carburant'],
        'action_sale_cancel' => ['annule la vente v2026 000123 pour erreur de saisie', 'annule le dernier ticket', 'annule cette vente', 'je veux annuler une vente'],
        'action_navigate' => ['ouvre les inventaires', 'va sur la page des clients', 'emmene moi aux rapports', 'ouvre la caisse', 'affiche l ecran des articles', 'montre moi la page des depenses'],
        'action_unsupported' => ['supprime l article', 'modifie le prix du riz', 'change le prix de vente', 'valide l inventaire', 'rembourse le client', 'efface ce produit'],
    ];

    public const UNSUPPORTED = [
        '/\b(supprim\w*|efface\w*)\b.{0,30}\b(article|produit)\b/' => 'Je ne supprime pas d\'article : dans POS Marwa un article utilisé s\'archive depuis sa fiche (Gestion des articles → Articles), pour garder l\'historique.',
        '/\b(modifi\w*|change\w*|mets?)\b.{0,25}\bprix\b/' => 'Je ne modifie pas les prix moi-même : faites-le dans la fiche de l\'article (bouton Modifier) ou en masse via Gestion des articles → Prix, afin que l\'historique des prix soit tenu.',
        '/\b(valide\w*)\b.{0,20}\binventaire\b/' => 'La validation d\'un inventaire modifie le stock : elle se fait dans l\'écran de l\'inventaire, après contrôle des écarts, par une personne autorisée.',
        '/\b(rembours\w*)\b/' => 'Pour un remboursement, ouvrez la vente (Historique des ventes → Retour / remboursement) ou, pour un paiement électronique, Marwa Pay → Transactions : le remboursement y est transmis au prestataire.',
    ];

    private static function unsupported(string $n): ?array
    {
        foreach (self::UNSUPPORTED as $re => $msg) {
            if (preg_match($re, $n) && preg_match('/\b(fais|fait|peux tu|pourrais tu|veux|stp|svp|merci de|je voudrais|faut)\b|^(supprime|modifie|change|valide|rembourse)/', $n)) {
                return ['unsupported', ['message' => $msg]];
            }
        }
        return null;
    }

    /** Détecte une demande d'action. @return array|null [tool, params] */
    public static function detect(string $q, UserContext $u): ?array
    {
        $n = Text::norm($q);
        // Une question (« qui a fait la dernière sortie de caisse ? ») n'est pas une demande d'action
        $asks = (bool) preg_match('/^(qui|quand|quel|quelle|quels|quelles|combien|ou|est ce|a quelle|pourquoi|comment)\b|\bderni\w*\b/', $n);
        if ($asks && !preg_match('/^(comment|pourquoi)\b/', $n)) {
            return self::unsupported($n);
        }
        if (preg_match('/^(comment|procedure|la procedure|quelle est la procedure|les etapes|explique|expliquez)\b/', $n)) {
            return null; // procédure : réponse d'aide
        }
        if (preg_match('/\b(prepare|cree|genere|fais|etablis)\w*\b.{0,40}\b(brouillon|bon) de commande|\bcommande fournisseur\b.{0,20}\b(brouillon|prepare)/', $n)) {
            return ['purchase_draft', []];
        }
        // Ajout de stock : « ajoute 50 eau minérale », « mets 50 dans le stock de l'eau », « augmente article eau de 50 »
        if (preg_match('/^(il faut |faut |tu peux |peux tu |merci d |je veux |je voudrais )?(ajoute[rz]?|rajoute[rz]?|mets|mettre|met|augmente[rz]?|incremente[rz]?|entre[rz]?|ajout)\b/', $n)
            && !preg_match('/\b(client|fournisseur|utilisateur|depense|categorie|article nouveau|nouvel article|un article|un produit|au panier|remise|note)\b/', $n)
            && (preg_match('/\d/', $n) || (preg_match('/\b(stock|stocke|stok)\b/', $n) && !preg_match('/^entre\b/', $n)))) {
            return ['stock_add', self::stockAddParams($q, $n, $u)];
        }
        if (preg_match('/\b(prepare|ouvre|demarre|lance|commence|cree)\w*\b.{0,20}\binventaire\b/', $n) && !preg_match('/\b(page|ecran|liste|menu|les inventaires|des inventaires)\b/', $n)) {
            return ['inventory_start', ['location' => Nlu::location($n, $u)]];
        }
        if (preg_match('/\b(fais|effectue|enregistre|retire|sors)\w*\b.{0,25}\b(sortie|retrait)\b.{0,20}\bcaisse\b|\bsortie de caisse de\b/', $n)) {
            preg_match('/(\d[\d ]*(?:[.,]\d+)?)\s*(?:f|fcfa|francs?)?\b/', $n, $m);
            preg_match('/\b(?:pour|motif|raison)\s*:?\s+(.{3,120})$/u', mb_strtolower($q), $r);
            return ['cash_out', ['amount' => isset($m[1]) ? (float) str_replace([' ', ','], ['', '.'], $m[1]) : 0.0, 'reason' => isset($r[1]) ? trim($r[1]) : '']];
        }
        if (preg_match('/\bannule\w*\b.{0,20}\b(vente|ticket|facture)\b/', $n)) {
            preg_match('/\b([a-z]{1,3}\d{4}-\d{3,6})\b/i', $q, $m);
            preg_match('/\b(?:pour|motif|raison|car)\s*:?\s+(.{3,120})$/u', mb_strtolower($q), $r);
            return ['sale_cancel', ['number' => isset($m[1]) ? strtoupper($m[1]) : '', 'reason' => isset($r[1]) ? trim($r[1]) : '']];
        }
        if ($r = self::unsupported($n)) {
            return $r;
        }
        if (preg_match('/^(ouvre|ouvrir|va|aller|emmene moi|amene moi|affiche (?:la page|l ecran|le menu)|montre moi la page|je veux aller)\b.{0,10}\b(a la|au|aux|sur|vers|la page|l ecran|le module|les?|la)?\b/', $n) && !preg_match('/\b(combien|quel|quelle|valeur|total)\b/', $n)) {
            return ['navigate', []];
        }
        return null;
    }

    /** Paramètres d'un ajout de stock : quantité, article, magasin (inclus / exclu), et ce qui reste à préciser. */
    public static function stockAddParams(string $q, string $n, UserContext $u): array
    {
        $qty = null;
        if (preg_match_all('/(?<![a-z\d])(\d+(?:[.,]\d+)?)(?![\d]*\s*(?:l|cl|ml|kg|g|mm|cm)\b)/', $n, $m)) {
            $qty = (float) str_replace(',', '.', $m[1][0]);
        }
        // « dans le magasin mais pas dans la boutique » : le lieu nié est écarté, l'autre est retenu
        $neg = null;
        $text = $n;
        if (preg_match('/\b(?:mais |et )?(?:pas|non|sauf|hors)\s+(?:dans |a |au |de |du |celui de |celui du )?(?:la |le |l )?([a-z ]{3,30})$/', $n, $mm)) {
            $neg = Nlu::location('dans la ' . trim($mm[1]), $u) ?? Nlu::location('le ' . trim($mm[1]), $u);
            $text = trim(substr($n, 0, -strlen($mm[0])));
        }
        $loc = Nlu::location($text, $u);
        $locWords = $loc && empty($loc['ambiguous']) ? Text::words($loc['name']) : [];
        $nameText = implode(' ', array_filter(explode(' ', $text), fn($w) => !in_array($w, $locWords, true)));
        $product = Nlu::named($nameText, 'product');
        return ['quantity' => $qty, 'product' => $product, 'location' => $loc, 'exclude' => $neg,
            'bare' => (bool) preg_match('/^(ajoute|rajoute)\w*\s+\d+(?:[.,]\d+)?\s+(a|au|aux)\b/', $n) && !preg_match('/\b(stock|unite|unites|piece|pieces|bouteille|bouteilles|carton|cartons|sac|sacs|paquet|paquets|magasin|depot|boutique)\b/', $n)];
    }

    /** Prépare une opération : vérifie droits et données, enregistre la proposition, renvoie le résumé à confirmer. */
    public static function propose(string $tool, array $params, UserContext $u, ?int $convId, string $q, bool $dry = false): array
    {
        if ($tool === 'unsupported') {
            return ['text' => $params['message'], 'proposal' => null];
        }
        if ($tool === 'navigate') {
            $nav = AppMap::navigate($q, $u);
            return $nav ? ['text' => 'Voici la page « ' . $nav['label'] . ' ».', 'link' => [$nav['label'], $nav['url']], 'proposal' => null, 'navigate' => $nav['url']]
                : ['text' => 'Je n\'ai pas trouvé de page correspondante accessible avec vos droits. Précisez le nom de l\'écran (ex. « ouvre les inventaires »).', 'proposal' => null];
        }
        [$label, , $perm, $sensitive] = self::TOOLS[$tool];
        if ($perm && !$u->can($perm)) {
            if (!$dry) {
                self::log($u, $convId, $tool, $params, 'Refusé : droit « ' . $perm . ' » manquant.', 'refused');
            }
            return ['text' => 'Je ne peux pas préparer « ' . $label . ' » : votre rôle n\'a pas ce droit. Demandez à un responsable.', 'proposal' => null, 'refused' => true];
        }
        try {
            [$summary, $params] = self::prepare($tool, $params, $u);
        } catch (BusinessException $e) {
            if (str_starts_with($e->getMessage(), 'CLARIFY:')) {
                return ['text' => substr($e->getMessage(), 8), 'proposal' => null, 'clarify' => true];
            }
            return ['text' => $e->getMessage(), 'proposal' => null, 'blocked' => true];
        }
        if ($dry) {
            return ['text' => 'Je peux préparer cette opération : ' . $summary, 'proposal' => ['id' => 0, 'token' => '', 'label' => $label, 'summary' => $summary, 'sensitive' => (bool) $sensitive, 'expires_in' => 600], 'params' => $params];
        }
        $token = bin2hex(random_bytes(16));
        $id = DB::insert('ai_action_logs', ['user_id' => $u->id(), 'conversation_id' => $convId, 'tool_code' => $tool, 'params_json' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'summary' => mb_substr($summary, 0, 500), 'status' => 'proposed', 'confirm_token_hash' => hash('sha256', $token), 'expires_at' => date('Y-m-d H:i:s', time() + 600)]);
        return ['text' => 'Je peux préparer cette opération : ' . $summary . ' Rien n\'est fait tant que vous ne confirmez pas.' . ($sensitive ? ' Opération sensible : votre mot de passe ou code PIN sera demandé.' : ''),
            'proposal' => ['id' => $id, 'token' => $token, 'label' => $label, 'summary' => $summary, 'sensitive' => (bool) $sensitive, 'expires_in' => 600]];
    }

    /** Vérifications et résumé lisible, sans rien modifier. */
    private static function prepare(string $tool, array $p, UserContext $u): array
    {
        switch ($tool) {
            case 'purchase_draft':
                $r = Catalog::run('reorder', $u, []);
                $bySupplier = [];
                $skipped = 0;
                foreach ($r['reorder_items'] ?? [] as $it) {
                    $sup = DB::value('SELECT supplier_id FROM products WHERE id = ?', [$it['id']]);
                    if (!$sup) {
                        $skipped++;
                        continue;
                    }
                    $bySupplier[(int) $sup][] = ['product_id' => (int) $it['id'], 'quantity' => (float) $it['suggest'], 'unit_cost' => (float) $it['cost_price']];
                }
                if (!$bySupplier) {
                    throw new BusinessException($skipped ? 'Les articles à recommander n\'ont pas de fournisseur habituel : renseignez-le dans leur fiche, ou créez la commande depuis Achats.' : 'Aucun article à recommander pour le moment.');
                }
                $wh = self::warehouse($u, null);
                $names = array_column(DB::all('SELECT id, name FROM suppliers WHERE id IN (' . implode(',', array_map('intval', array_keys($bySupplier))) . ')'), 'name', 'id');
                $parts = array_map(fn($sid, $items) => ($names[$sid] ?? '?') . ' (' . count($items) . ' article(s))', array_keys($bySupplier), $bySupplier);
                return ['créer ' . count($bySupplier) . ' commande(s) fournisseur au statut BROUILLON pour le magasin « ' . $wh['name'] . ' » : ' . implode(', ', $parts) . ($skipped ? " ; $skipped article(s) sans fournisseur ignoré(s)" : '') . '.',
                    ['suppliers' => $bySupplier, 'warehouse_id' => $wh['id']]];
            case 'stock_add':
                $pr = $p['product'] ?? null;
                if (!$pr) {
                    throw new BusinessException('CLARIFY:À quel article faut-il ajouter du stock ? Donnez son nom, par exemple « ajoute 50 au stock de l\'eau minérale ».');
                }
                if (!empty($pr['ambiguous'])) {
                    throw new BusinessException('CLARIFY:Plusieurs articles correspondent : ' . implode(', ', $pr['ambiguous']) . '. Lequel voulez-vous ?');
                }
                if (empty($p['quantity']) || $p['quantity'] <= 0) {
                    throw new BusinessException('CLARIFY:Quelle quantité faut-il ajouter au stock de « ' . $pr['name'] . ' » ?');
                }
                if (!empty($p['bare'])) {
                    throw new BusinessException('CLARIFY:Voulez-vous ajouter ' . Catalog::n((float) $p['quantity']) . ' unité(s) au stock de « ' . $pr['name'] . ' » (entrée de stock), ou préparer un achat chez le fournisseur ? Répondez par exemple « ajoute ' . Catalog::n((float) $p['quantity']) . ' au stock de ' . $pr['name'] . ' ».');
                }
                if (!empty($p['location']['ambiguous'])) {
                    throw new BusinessException('CLARIFY:Dans quel magasin : ' . implode(', ', $p['location']['ambiguous']) . ' ?');
                }
                $loc = $p['location'] ?? null;
                if ($loc && $loc['type'] === 'store') {
                    $whs = DB::all('SELECT id, name FROM warehouses WHERE active = 1 AND store_id = ?' . (!empty($p['exclude']) && $p['exclude']['type'] === 'warehouse' ? ' AND id <> ' . (int) $p['exclude']['id'] : ''), [$loc['id']]);
                    if (count($whs) !== 1) {
                        throw new BusinessException('CLARIFY:La boutique « ' . $loc['name'] . ' » a plusieurs magasins : ' . implode(', ', array_column($whs, 'name')) . '. Dans lequel ajouter le stock ?');
                    }
                    $loc = ['type' => 'warehouse', 'id' => (int) $whs[0]['id'], 'name' => $whs[0]['name']];
                }
                $wh = self::warehouse($u, $loc);
                if (!empty($p['exclude']) && $p['exclude']['type'] === 'warehouse' && (int) $p['exclude']['id'] === $wh['id']) {
                    throw new BusinessException('CLARIFY:Vous excluez « ' . $wh['name'] . ' » : dans quel autre magasin faut-il ajouter le stock ?');
                }
                $row = DB::one('SELECT track_stock, track_lots FROM products WHERE id = ?', [$pr['id']]);
                if (!(int) $row['track_stock']) {
                    throw new BusinessException('« ' . $pr['name'] . ' » n\'est pas suivi en stock (service) : il n\'y a pas de quantité à ajouter.');
                }
                if ((int) $row['track_lots']) {
                    throw new BusinessException('« ' . $pr['name'] . ' » est suivi par lot : le numéro de lot et la date de péremption sont obligatoires. Utilisez Gestion des articles → Ajout de stock rapide.');
                }
                $cur = (float) DB::value('SELECT COALESCE(quantity,0) FROM stock_levels WHERE product_id = ? AND warehouse_id = ?', [$pr['id'], $wh['id']]);
                return ['ajouter ' . Catalog::n((float) $p['quantity']) . ' unité(s) au stock de « ' . $pr['name'] . ' » dans le magasin « ' . $wh['name'] . ' » (stock actuel ' . Catalog::n($cur) . ', après ajout ' . Catalog::n($cur + (float) $p['quantity']) . ').',
                    ['product_id' => (int) $pr['id'], 'product' => $pr['name'], 'warehouse_id' => $wh['id'], 'quantity' => (float) $p['quantity']]];
            case 'inventory_start':
                $wh = self::warehouse($u, $p['location'] ?? null);
                if (DB::value("SELECT 1 FROM inventories WHERE warehouse_id = ? AND status = 'draft'", [$wh['id']])) {
                    throw new BusinessException('Un inventaire est déjà en cours pour « ' . $wh['name'] . ' » : terminez-le d\'abord (Gestion des articles → Inventaires).');
                }
                return ['ouvrir un inventaire du magasin « ' . $wh['name'] . ' » (tous les articles). Le stock ne changera qu\'à sa validation.', ['warehouse_id' => $wh['id']]];
            case 'cash_out':
                if ($p['amount'] <= 0) {
                    throw new BusinessException('Précisez le montant, par exemple : « fais une sortie de caisse de 5000 pour le transport ».');
                }
                if (mb_strlen($p['reason']) < 3) {
                    throw new BusinessException('Précisez le motif, par exemple : « … de 5000 pour le transport ».');
                }
                $session = $u->registerId ? CashService::openSessionForRegister($u->registerId) : null;
                if (!$session) {
                    throw new BusinessException('Aucune session de caisse ouverte sur votre caisse.');
                }
                $avail = CashService::expectedCash((int) $session['id']);
                if ($p['amount'] > $avail + 0.001) {
                    throw new BusinessException('Montant supérieur aux espèces théoriquement présentes (' . Catalog::money($avail) . ').');
                }
                return ['retirer ' . Catalog::money($p['amount']) . ' en espèces de votre caisse, motif « ' . $p['reason'] . ' ».', $p + ['session_id' => (int) $session['id']]];
            case 'sale_cancel':
                if ($p['number'] === '') {
                    throw new BusinessException('Indiquez le numéro de la vente, par exemple : « annule la vente V2026-000123 pour erreur de saisie ».');
                }
                $s = DB::one('SELECT id, number, total, user_id, status, created_at FROM sales WHERE number = ?', [$p['number']]);
                if (!$s || (!$u->seesAllSales() && (int) $s['user_id'] !== $u->id())) {
                    throw new BusinessException('Vente ' . $p['number'] . ' introuvable dans votre périmètre.');
                }
                if ($s['status'] !== 'completed') {
                    throw new BusinessException('Cette vente ne peut plus être annulée (état : ' . $s['status'] . ').');
                }
                if (mb_strlen($p['reason']) < 3) {
                    throw new BusinessException('Le motif d\'annulation est obligatoire : « annule la vente ' . $s['number'] . ' pour … ».');
                }
                return ['annuler la vente ' . $s['number'] . ' du ' . fdate($s['created_at']) . ' (' . Catalog::money((float) $s['total']) . '), motif « ' . $p['reason'] . ' » : stock, caisse et crédit seront contre-passés.', $p + ['sale_id' => (int) $s['id']]];
        }
        throw new BusinessException('Opération inconnue.');
    }

    private static function warehouse(UserContext $u, ?array $loc): array
    {
        if ($loc && $loc['type'] === 'warehouse' && $loc['id']) {
            return ['id' => $loc['id'], 'name' => $loc['name']];
        }
        $wid = $u->registerId ? (int) DB::value('SELECT warehouse_id FROM cash_registers WHERE id = ?', [$u->registerId]) : 0;
        $ids = $u->warehouseIds();
        $w = DB::one('SELECT id, name FROM warehouses WHERE active = 1' . ($wid ? ' AND id = ' . $wid : '') . ($ids !== null ? ' AND id IN (' . implode(',', $ids) . ')' : '') . ' ORDER BY id LIMIT 1')
            ?? DB::one('SELECT id, name FROM warehouses WHERE active = 1' . ($ids !== null ? ' AND id IN (' . implode(',', $ids) . ')' : '') . ' ORDER BY id LIMIT 1');
        if (!$w) {
            throw new BusinessException('Aucun magasin accessible.');
        }
        return ['id' => (int) $w['id'], 'name' => $w['name']];
    }

    /** Exécution après confirmation (jeton, propriétaire, délai, droits revérifiés, mot de passe si sensible). */
    public static function confirm(int $id, string $token, ?string $secret, UserContext $u): array
    {
        $a = DB::one('SELECT * FROM ai_action_logs WHERE id = ? AND user_id = ?', [$id, $u->id()]);
        if (!$a || $a['status'] !== 'proposed' || !hash_equals((string) $a['confirm_token_hash'], hash('sha256', $token))) {
            throw new BusinessException('Opération introuvable ou déjà traitée.');
        }
        if (strtotime((string) $a['expires_at']) < time()) {
            DB::update('ai_action_logs', ['status' => 'expired'], 'id = ?', [$id]);
            throw new BusinessException('Délai de confirmation dépassé : redemandez l\'opération.');
        }
        [$label, , $perm, $sensitive] = self::TOOLS[$a['tool_code']];
        if ($perm && !$u->can($perm)) {
            DB::update('ai_action_logs', ['status' => 'refused', 'result' => 'Droit retiré'], 'id = ?', [$id]);
            throw new BusinessException('Vous n\'avez plus le droit nécessaire.');
        }
        if ($sensitive) {
            if (!$secret) {
                throw new BusinessException('AUTH_REQUIRED:Saisissez votre mot de passe ou code PIN pour confirmer.');
            }
            Auth::verifySupervisor((string) $u->user['username'], $secret, (string) $perm); // autorisation renforcée
        }
        $p = json_decode((string) $a['params_json'], true) ?: [];
        try {
            [$msg, $refType, $refId, $link] = match ($a['tool_code']) {
                'purchase_draft' => self::doPurchase($p),
                'stock_add' => [(\App\Services\StockEntry::quickAdd((int) $p['product_id'], (int) $p['warehouse_id'], (float) $p['quantity'], null, null, null, 'Ajout demandé à MARWA AI') ? Catalog::n((float) $p['quantity']) . ' unité(s) ajoutée(s) au stock de « ' . $p['product'] . ' ».' : ''), 'product', (int) $p['product_id'], '/stock/quick'],
                'inventory_start' => [($n = InventoryService::start((int) $p['warehouse_id'], null, 'Préparé par MARWA AI')) ? 'Inventaire ouvert.' : '', 'inventory', $n, '/inventories/' . $n],
                'cash_out' => [(CashService::manual((int) $p['session_id'], 'out', (float) $p['amount'], (string) $p['reason']) ? 'Sortie de caisse de ' . Catalog::money((float) $p['amount']) . ' enregistrée.' : ''), 'cash_session', (int) $p['session_id'], '/cash'],
                'sale_cancel' => [(SaleService::cancel((int) $p['sale_id'], (string) $p['reason']) === null ? 'Vente ' . $p['number'] . ' annulée : stock, caisse et crédit contre-passés.' : ''), 'sale', (int) $p['sale_id'], '/sales/' . $p['sale_id']],
            };
        } catch (BusinessException $e) {
            DB::update('ai_action_logs', ['status' => 'failed', 'result' => mb_substr($e->getMessage(), 0, 500), 'executed_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            throw $e;
        }
        DB::update('ai_action_logs', ['status' => 'executed', 'result' => mb_substr($msg, 0, 500), 'ref_type' => $refType, 'ref_id' => $refId, 'executed_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        Audit::log('ai.action', $refType, $refId, ['tool' => $a['tool_code'], 'summary' => $a['summary']]);
        return ['text' => $msg, 'link' => [$label, $link]];
    }

    private static function doPurchase(array $p): array
    {
        $ids = [];
        foreach ($p['suppliers'] as $sid => $items) {
            $ids[] = PurchaseService::createOrder((int) $sid, (int) $p['warehouse_id'], $items, null, 'Brouillon préparé par MARWA AI', 'draft');
        }
        return [count($ids) . ' commande(s) fournisseur créée(s) au statut brouillon : vérifiez-les avant de les envoyer.', 'purchase_order', $ids[0] ?? null, '/purchases'];
    }

    public static function cancel(int $id, UserContext $u): void
    {
        DB::run("UPDATE ai_action_logs SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'proposed'", [$id, $u->id()]);
    }

    private static function log(UserContext $u, ?int $convId, string $tool, array $params, string $summary, string $status): void
    {
        DB::insert('ai_action_logs', ['user_id' => $u->id(), 'conversation_id' => $convId, 'tool_code' => $tool, 'params_json' => json_encode($params, JSON_UNESCAPED_UNICODE), 'summary' => mb_substr($summary, 0, 500), 'status' => $status]);
    }
}
