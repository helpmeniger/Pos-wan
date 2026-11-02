<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Services\MikroTik\RouterService;
use Throwable;

/**
 * OPTIMUS IA — assistant d'exploitation.
 *
 * Moteur local (hors ligne) : reconnaissance d'intentions en français et réponses
 * construites UNIQUEMENT à partir de la base et des routeurs. Aucune donnée inventée.
 * Les opérations (génération de tickets…) sont préparées puis soumises à confirmation humaine.
 * Un modèle externe (Claude, via le SDK officiel) est facultatif et ne reçoit que des chiffres réels.
 */
final class AiService
{
    /** @return array{text:string, intent:string, data:array, table?:array, action?:array, engine:string} */
    public static function answer(string $question): array
    {
        $q = self::normalize($question);
        $period = self::period($q);

        $intents = [
            'help' => '/\b(aide|help|que sais[- ]tu|quoi faire|commandes)\b/',
            'generate' => '/\b(genere|generer|cree|creer|fabrique)\b.*\bticket/',
            'connected' => '/\b(client|utilisateur|personne|appareil)s?\b.*\b(connecte|en ligne|actif)|\bconnecte(s)?\b.*\b(maintenant|actuellement)/',
            'offline' => '/\brouteur.*\b(deconnecte|hors ligne|injoignable|panne|down)|\b(deconnecte|hors ligne)s?\b.*\brouteur/',
            'top_router' => '/\brouteur\b.*\b(plus|meilleur|max)\b.*\b(revenu|vend|chiffre|rapport)|\b(revenu|chiffre).*\bpar routeur/',
            'top_offer' => '/\b(forfait|offre)s?\b.*\b(plus|mieux)\b.*\bvendu|\bmeilleure?s? (offre|forfait)/',
            'top_consumers' => '/\b(consomm|donnees|data|gros consommateur|bande passante)/',
            'tickets_sold' => '/\bticket.*\bvendu|\bvente.*\bticket|\bvendu.*\bticket/',
            'stock' => '/\b(stock|disponible|reste)\b/',
            'revenue' => '/\b(chiffre d.?affaire|ca\b|revenu|recette|gagne|encaisse|vente)/',
            'resellers' => '/\brevendeur/',
            'cash' => '/\bcaisse/',
            'routers' => '/\brouteur|mikrotik/',
            'alerts' => '/\b(alerte|notification|probleme)/',
        ];
        $intent = 'unknown';
        foreach ($intents as $name => $re) {
            if (preg_match($re, $q)) {
                $intent = $name;
                break;
            }
        }
        $method = 'intent' . str_replace('_', '', ucwords($intent, '_'));
        $res = method_exists(self::class, $method) ? self::$method($q, $period) : self::intentUnknown($q, $period);
        return $res + ['intent' => $intent, 'data' => [], 'engine' => 'local'];
    }

    private static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', '’' => "'"]);
        return preg_replace('/\s+/', ' ', $s);
    }

    /** @return array{0:string,1:string,2:string} début, fin, libellé */
    private static function period(string $q): array
    {
        $today = date('Y-m-d');
        return match (true) {
            str_contains($q, 'hier') => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day')), 'hier'],
            (bool) preg_match('/semaine|7 (derniers )?jours/', $q) => [date('Y-m-d', strtotime('monday this week')), $today, 'cette semaine'],
            (bool) preg_match('/mois|30 (derniers )?jours/', $q) => [date('Y-m-01'), $today, 'ce mois-ci'],
            (bool) preg_match('/annee|an\b/', $q) => [date('Y-01-01'), $today, 'cette année'],
            default => [$today, $today, "aujourd'hui"],
        };
    }

    private static function deny(string $perm): ?array
    {
        return Auth::can($perm) ? null : ['text' => "Vous n'avez pas l'autorisation de consulter ces informations ({$perm})."];
    }

    private static function intentHelp(): array
    {
        return ['text' => "Je réponds à partir de vos données réelles (base MARWA CONNECT et routeurs). Exemples :\n"
            . "• Combien de clients sont connectés actuellement ?\n• Quel routeur a généré le plus de revenus cette semaine ?\n"
            . "• Affiche les tickets vendus aujourd'hui.\n• Quels routeurs sont déconnectés ?\n• Génère 100 tickets de 500 FCFA sur le routeur principal.\n"
            . "• Quel est le chiffre d'affaires du mois ?\n• Quels forfaits sont les plus vendus ?\n• Affiche les clients qui consomment le plus de données.\n"
            . "• Quel est le stock de tickets ?\n• Où en est la caisse ?"];
    }

    private static function intentConnected(): array
    {
        if ($d = self::deny('hotspot.view')) {
            return $d;
        }
        $rows = [];
        $total = 0;
        $errors = [];
        foreach (Database::fetchAll("SELECT * FROM routers WHERE deleted_at IS NULL AND status = 'active'") as $r) {
            try {
                $n = count(RouterService::client($r)->query('/ip/hotspot/active/print', ['.proplist' => '.id']));
                $rows[] = [$r['name'], $n, 'en direct'];
                $total += $n;
            } catch (Throwable $e) {
                RouterService::close((int) $r['id']);
                $last = $r['last_active_users'];
                $errors[] = $r['name'];
                $rows[] = [$r['name'], $last ?? '—', 'injoignable, dernier relevé ' . date_fr($r['last_checked_at'])];
            }
        }
        if (!$rows) {
            return ['text' => "Aucun routeur actif n'est enregistré : je ne peux pas compter les clients connectés."];
        }
        $txt = "{$total} client(s) connecté(s) actuellement (lecture en direct sur les routeurs).";
        if ($errors) {
            $txt .= ' Attention : ' . implode(', ', $errors) . ' injoignable(s), non compté(s) dans le total.';
        }
        return ['text' => $txt, 'table' => ['head' => ['Routeur', 'Clients', 'Source'], 'rows' => $rows], 'data' => ['total' => $total]];
    }

    private static function intentOffline(): array
    {
        if ($d = self::deny('routers.view')) {
            return $d;
        }
        $rows = Database::fetchAll("SELECT name, host, last_seen_at, last_error FROM routers WHERE deleted_at IS NULL AND status = 'active' AND connection_state = 'offline' ORDER BY name");
        $total = (int) Database::value("SELECT COUNT(*) FROM routers WHERE deleted_at IS NULL AND status = 'active'");
        if (!$rows) {
            return ['text' => $total ? "Aucun routeur déconnecté : les {$total} routeur(s) actif(s) répondaient lors du dernier contrôle." : "Aucun routeur n'est enregistré."];
        }
        return ['text' => count($rows) . " routeur(s) déconnecté(s) sur {$total} :", 'table' => ['head' => ['Routeur', 'Adresse', 'Vu pour la dernière fois', 'Erreur'],
            'rows' => array_map(static fn ($r) => [$r['name'], $r['host'], date_fr($r['last_seen_at']), $r['last_error']], $rows)]];
    }

    private static function intentTopRouter(string $q, array $p): array
    {
        if ($d = self::deny('reports.view')) {
            return $d;
        }
        $rows = Database::fetchAll(
            "SELECT r.name, COUNT(s.id) n, SUM(s.total) total FROM sales s JOIN routers r ON r.id = s.router_id
              WHERE s.status = 'completed' AND s.created_at BETWEEN :a AND :b GROUP BY r.id ORDER BY total DESC",
            ['a' => $p[0] . ' 00:00:00', 'b' => $p[1] . ' 23:59:59']);
        if (!$rows) {
            return ['text' => "Aucune vente enregistrée {$p[2]}."];
        }
        return ['text' => "Le routeur « {$rows[0]['name']} » a généré le plus de revenus {$p[2]} : " . money($rows[0]['total']) . " ({$rows[0]['n']} vente(s)).",
            'table' => ['head' => ['Routeur', 'Ventes', 'Revenus'], 'rows' => array_map(static fn ($r) => [$r['name'], $r['n'], money($r['total'])], $rows)]];
    }

    private static function intentTopOffer(string $q, array $p): array
    {
        if ($d = self::deny('reports.view')) {
            return $d;
        }
        if ($p[2] === "aujourd'hui" && !str_contains($q, 'aujourd')) {
            $p = [date('Y-m-01'), date('Y-m-d'), 'ce mois-ci'];
        }
        $rows = Database::fetchAll(
            "SELECT o.name, COUNT(*) n, SUM(si.total) total FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN offers o ON o.id = si.offer_id
              WHERE s.status = 'completed' AND s.created_at BETWEEN :a AND :b GROUP BY o.id ORDER BY n DESC",
            ['a' => $p[0] . ' 00:00:00', 'b' => $p[1] . ' 23:59:59']);
        if (!$rows) {
            return ['text' => "Aucun forfait vendu {$p[2]}."];
        }
        return ['text' => "Forfait le plus vendu {$p[2]} : « {$rows[0]['name']} » ({$rows[0]['n']} ticket(s)).",
            'table' => ['head' => ['Forfait', 'Tickets', 'Montant'], 'rows' => array_map(static fn ($r) => [$r['name'], $r['n'], money($r['total'])], $rows)]];
    }

    private static function intentTopConsumers(string $q, array $p): array
    {
        if ($d = self::deny('hotspot.view')) {
            return $d;
        }
        if ($p[2] === "aujourd'hui" && !str_contains($q, 'aujourd')) {
            $p = [date('Y-m-d', strtotime('-30 days')), date('Y-m-d'), 'sur 30 jours'];
        }
        $rows = Database::fetchAll(
            'SELECT username, COUNT(*) n, SUM(bytes_in + bytes_out) b, SUM(uptime_seconds) up FROM wifi_sessions
              WHERE login_at BETWEEN :a AND :b GROUP BY username ORDER BY b DESC LIMIT 10', ['a' => $p[0] . ' 00:00:00', 'b' => $p[1] . ' 23:59:59']);
        if (!$rows) {
            return ['text' => "Aucune session Wi-Fi enregistrée {$p[2]} (les sessions sont relevées à chaque synchronisation des routeurs)."];
        }
        return ['text' => "Les 10 plus gros consommateurs {$p[2]} :", 'table' => ['head' => ['Utilisateur', 'Sessions', 'Données', 'Temps'],
            'rows' => array_map(static fn ($r) => [$r['username'], $r['n'], bytes_human($r['b']), human_duration((int) $r['up'])], $rows)]];
    }

    private static function intentTicketsSold(string $q, array $p): array
    {
        if ($d = self::deny('sales.view')) {
            return $d;
        }
        $rows = Database::fetchAll(
            "SELECT t.username, o.name AS offer, t.price, s.reference, s.created_at, r.name AS router FROM sale_items si JOIN sales s ON s.id = si.sale_id
               JOIN tickets t ON t.id = si.ticket_id JOIN offers o ON o.id = si.offer_id LEFT JOIN routers r ON r.id = t.router_id
              WHERE s.status = 'completed' AND s.created_at BETWEEN :a AND :b ORDER BY s.created_at DESC LIMIT 100",
            ['a' => $p[0] . ' 00:00:00', 'b' => $p[1] . ' 23:59:59']);
        $count = (int) Database::value("SELECT COUNT(*) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.status = 'completed' AND s.created_at BETWEEN :a AND :b", ['a' => $p[0] . ' 00:00:00', 'b' => $p[1] . ' 23:59:59']);
        if (!$count) {
            return ['text' => "Aucun ticket vendu {$p[2]}."];
        }
        return ['text' => "{$count} ticket(s) vendu(s) {$p[2]}" . ($count > 100 ? ' (100 derniers affichés)' : '') . ' :',
            'table' => ['head' => ['Ticket', 'Offre', 'Prix', 'Vente', 'Heure', 'Routeur'],
                'rows' => array_map(static fn ($r) => [$r['username'], $r['offer'], money($r['price']), $r['reference'], date_fr($r['created_at'], 'd/m H:i'), $r['router']], $rows)]];
    }

    private static function intentStock(): array
    {
        if ($d = self::deny('tickets.view')) {
            return $d;
        }
        $rows = TicketService::stock();
        if (!$rows) {
            return ['text' => 'Aucun ticket disponible à la vente. Générez des tickets depuis le module Tickets.'];
        }
        return ['text' => 'Stock vendable (tickets créés sur les routeurs, non attribués aux revendeurs) : ' . array_sum(array_column($rows, 'n')) . ' ticket(s).',
            'table' => ['head' => ['Routeur', 'Offre', 'Prix', 'Disponibles'], 'rows' => array_map(static fn ($r) => [$r['router_name'], $r['offer_name'], money($r['price']), $r['n']], $rows)]];
    }

    private static function intentRevenue(string $q, array $p): array
    {
        if ($d = self::deny('reports.view')) {
            return $d;
        }
        $row = Database::fetch(
            "SELECT COUNT(*) n, COALESCE(SUM(total),0) total, COALESCE(SUM(CASE WHEN channel='physical' THEN total END),0) phys,
                    COALESCE(SUM(CASE WHEN channel='online' THEN total END),0) online, COALESCE(SUM(CASE WHEN channel='reseller' THEN total END),0) res
               FROM sales WHERE status = 'completed' AND created_at BETWEEN :a AND :b", ['a' => $p[0] . ' 00:00:00', 'b' => $p[1] . ' 23:59:59']);
        return ['text' => "Chiffre d'affaires {$p[2]} : " . money($row['total']) . " sur {$row['n']} vente(s).",
            'table' => ['head' => ['Canal', 'Montant'], 'rows' => [['Caisse', money($row['phys'])], ['En ligne', money($row['online'])], ['Revendeurs', money($row['res'])]]],
            'data' => ['total' => (float) $row['total'], 'period' => $p]];
    }

    private static function intentResellers(string $q, array $p): array
    {
        if ($d = self::deny('resellers.view')) {
            return $d;
        }
        $rows = Database::fetchAll(
            "SELECT rs.business_name, rs.balance, (SELECT COALESCE(SUM(total),0) FROM sales s WHERE s.reseller_id = rs.id AND s.status='completed' AND s.created_at BETWEEN :a AND :b) v
               FROM resellers rs ORDER BY v DESC", ['a' => $p[0] . ' 00:00:00', 'b' => $p[1] . ' 23:59:59']);
        if (!$rows) {
            return ['text' => 'Aucun revendeur enregistré.'];
        }
        return ['text' => "Revendeurs — ventes {$p[2]} :", 'table' => ['head' => ['Revendeur', 'Ventes', 'Solde'], 'rows' => array_map(static fn ($r) => [$r['business_name'], money($r['v']), money($r['balance'])], $rows)]];
    }

    private static function intentCash(): array
    {
        if ($d = self::deny('sales.view')) {
            return $d;
        }
        $open = Database::fetchAll("SELECT cs.*, u.full_name FROM cash_sessions cs JOIN users u ON u.id = cs.user_id WHERE cs.status = 'open'");
        if (!$open) {
            return ['text' => 'Aucune caisse ouverte actuellement.'];
        }
        return ['text' => count($open) . ' caisse(s) ouverte(s) :', 'table' => ['head' => ['Caissier', 'Ouverte le', 'Espèces attendues'],
            'rows' => array_map(static fn ($s) => [$s['full_name'], date_fr($s['opened_at']), money(SaleService::expectedCash($s))], $open)]];
    }

    private static function intentRouters(): array
    {
        if ($d = self::deny('routers.view')) {
            return $d;
        }
        $rows = Database::fetchAll("SELECT name, connection_state, last_cpu, last_memory_used_pct, last_active_users, last_checked_at FROM routers WHERE deleted_at IS NULL AND status = 'active' ORDER BY name");
        if (!$rows) {
            return ['text' => "Aucun routeur enregistré."];
        }
        $states = ['online' => 'en ligne', 'offline' => 'hors ligne', 'unknown' => 'inconnu'];
        return ['text' => count($rows) . ' routeur(s) actif(s) — état au dernier contrôle :', 'table' => ['head' => ['Routeur', 'État', 'CPU', 'RAM', 'Clients', 'Contrôlé'],
            'rows' => array_map(static fn ($r) => [$r['name'], $states[$r['connection_state']], ($r['last_cpu'] ?? '—') . ' %', ($r['last_memory_used_pct'] ?? '—') . ' %', $r['last_active_users'] ?? '—', date_fr($r['last_checked_at'])], $rows)]];
    }

    private static function intentAlerts(): array
    {
        $rows = Database::fetchAll("SELECT title, message, created_at FROM notifications WHERE level IN ('warning','danger') AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY id DESC LIMIT 15");
        if (!$rows) {
            return ['text' => 'Aucune alerte au cours des dernières 24 heures.'];
        }
        return ['text' => count($rows) . ' alerte(s) sur 24 heures :', 'table' => ['head' => ['Alerte', 'Détail', 'Heure'], 'rows' => array_map(static fn ($r) => [$r['title'], $r['message'], date_fr($r['created_at'], 'd/m H:i')], $rows)]];
    }

    /** « Génère 100 tickets de 500 FCFA sur le routeur principal » → action à confirmer. */
    private static function intentGenerate(string $q): array
    {
        if ($d = self::deny('tickets.generate')) {
            return $d;
        }
        preg_match('/(\d+)\s*ticket/', $q, $mq);
        $qty = (int) ($mq[1] ?? 0);
        $offer = null;
        if (preg_match('/(\d[\d\s]*)\s*(f|fcfa|cfa|francs?)\b/', $q, $mp)) {
            $offer = Database::fetch("SELECT * FROM offers WHERE status = 'active' AND price = :p ORDER BY sort_order LIMIT 1", ['p' => (float) str_replace(' ', '', $mp[1])]);
        }
        if (!$offer) {
            foreach (Database::fetchAll("SELECT * FROM offers WHERE status = 'active'") as $o) {
                if (str_contains($q, self::normalize($o['name'])) || preg_match('/\b' . preg_quote(strtolower($o['code']), '/') . '\b/', $q)) {
                    $offer = $o;
                    break;
                }
            }
        }
        $router = null;
        foreach (Database::fetchAll("SELECT * FROM routers WHERE deleted_at IS NULL AND status = 'active'") as $r) {
            if (str_contains($q, self::normalize($r['name']))) {
                $router = $r;
            }
        }
        if (!$router && preg_match('/principal|defaut|par defaut/', $q)) {
            $router = Database::fetch("SELECT * FROM routers WHERE deleted_at IS NULL AND status = 'active' ORDER BY is_default DESC, id LIMIT 1");
        }
        $missing = [];
        if ($qty < 1 || $qty > 5000) {
            $missing[] = 'la quantité (1 à 5 000)';
        }
        if (!$offer) {
            $missing[] = "l'offre (prix ou nom d'une offre active)";
        }
        if (!$router) {
            $missing[] = 'le routeur (son nom, ou « routeur principal »)';
        }
        if ($missing) {
            return ['text' => 'Pour préparer la génération, précisez ' . implode(', ', $missing) . ".\nExemple : « Génère 100 tickets de 500 FCFA sur le routeur principal »."];
        }
        $summary = "Générer {$qty} ticket(s) « {$offer['name']} » (" . money($offer['price']) . ") sur le routeur « {$router['name']} »";
        return ['text' => "Opération préparée : {$summary}. Cette action crée réellement les comptes sur le routeur : confirmez-la pour l'exécuter.",
            'action' => ['action' => 'generate_tickets', 'params' => ['offer_id' => (int) $offer['id'], 'router_id' => (int) $router['id'], 'quantity' => $qty], 'summary' => $summary]];
    }

    private static function intentUnknown(string $q): array
    {
        return ['text' => "Je n'ai pas reconnu cette demande. Tapez « aide » pour voir ce que je sais faire à partir de vos données."];
    }

    /** Exécute une action confirmée par l'utilisateur. */
    public static function execute(array $pending, int $userId): string
    {
        $params = json_decode((string) $pending['params'], true) ?: [];
        return match ($pending['action']) {
            'generate_tickets' => (static function () use ($params, $userId) {
                $r = TicketService::generate($params, $userId);
                return "Lot {$r['batch_code']} : {$r['created']} ticket(s) générés, {$r['pushed']} créés sur le routeur" . ($r['error'] ? " (erreur : {$r['error']})" : '') . '.';
            })(),
            default => throw new \RuntimeException('Action inconnue.'),
        };
    }

    /** Instantané chiffré des indicateurs réels transmis au modèle externe (jamais d'identifiants). */
    public static function snapshot(): array
    {
        $today = date('Y-m-d');
        return [
            'date' => date('Y-m-d H:i'),
            'ventes_jour' => Database::fetch("SELECT COUNT(*) n, COALESCE(SUM(total),0) total FROM sales WHERE status='completed' AND created_at >= :d", ['d' => $today]),
            'ventes_mois' => Database::fetch("SELECT COUNT(*) n, COALESCE(SUM(total),0) total FROM sales WHERE status='completed' AND created_at >= :d", ['d' => date('Y-m-01')]),
            'tickets_par_statut' => array_column(Database::fetchAll('SELECT status, COUNT(*) n FROM tickets GROUP BY status'), 'n', 'status'),
            'routeurs' => Database::fetchAll("SELECT name, connection_state, last_cpu, last_memory_used_pct, last_active_users FROM routers WHERE deleted_at IS NULL AND status='active'"),
            'ventes_par_offre_mois' => Database::fetchAll("SELECT o.name, COUNT(*) n, SUM(si.total) total FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN offers o ON o.id=si.offer_id WHERE s.status='completed' AND s.created_at >= :d GROUP BY o.id", ['d' => date('Y-m-01')]),
        ];
    }

    public static function externalAvailable(): bool
    {
        return (bool) setting('ai.external_enabled', false) && setting('ai.api_key', '') !== '' && class_exists(\Anthropic\Client::class);
    }

    /**
     * Réponse d'un modèle Claude (SDK officiel anthropic-ai/sdk, facultatif) à partir de l'instantané réel.
     */
    public static function external(string $question): ?array
    {
        if (!self::externalAvailable()) {
            return null;
        }
        $client = new \Anthropic\Client(apiKey: (string) setting('ai.api_key'));
        $model = (string) (setting('ai.model', '') ?: 'claude-opus-5-5');
        $system = "Tu es OPTIMUS IA, l'assistant de MARWA CONNECT (gestion de Wi-Fi et de tickets au Niger, montants en FCFA). "
            . "Réponds en français, brièvement. Utilise UNIQUEMENT les données JSON fournies par l'application ; si l'information n'y figure pas, "
            . "dis-le clairement et n'invente jamais de chiffres. Tu ne peux exécuter aucune action.";
        $message = $client->messages->create(
            model: $model,
            maxTokens: 2000,
            system: $system,
            messages: [['role' => 'user', 'content' => "Données réelles de l'application :\n" . json_encode(self::snapshot(), JSON_UNESCAPED_UNICODE) . "\n\nQuestion : " . $question]],
        );
        if ($message->stopReason === 'refusal') {
            return ['text' => "Le modèle externe a décliné cette demande. Reformulez votre question ou utilisez les questions prédéfinies (tapez « aide »).", 'intent' => 'external', 'data' => [], 'engine' => $model];
        }
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        return ['text' => trim($text) ?: 'Réponse vide.', 'intent' => 'external', 'data' => [], 'engine' => $model];
    }
}
