<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * API interne : état du système, notifications et préférences.
 */
final class SystemController extends Controller
{
    /** Indicateur de connexion temps réel (serveur + base + état des routeurs). */
    public function ping(Request $request): Response
    {
        $start = microtime(true);
        $dbOk = true;
        try {
            Database::value('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }
        $routers = $dbOk ? Database::fetch(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(connection_state = 'online'), 0) AS online,
                    COALESCE(SUM(connection_state = 'offline'), 0) AS offline
               FROM routers WHERE deleted_at IS NULL AND status = 'active'"
        ) : null;
        return $this->json([
            'success' => true,
            'server_time' => date('c'),
            'database' => $dbOk,
            'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            'routers' => $routers ? array_map('intval', $routers) : null,
        ]);
    }

    public function me(Request $request): Response
    {
        $u = Auth::user();
        return $this->json([
            'success' => true,
            'user' => [
                'id' => (int) $u['id'], 'full_name' => $u['full_name'], 'username' => $u['username'],
                'role' => $u['role_slug'], 'theme' => $u['theme'],
            ],
            'permissions' => Auth::permissions(),
        ]);
    }

    /** Notifications visibles par l'utilisateur (personnelles ou liées à ses permissions). */
    public function notifications(Request $request): Response
    {
        [$sql, $params] = $this->visibleScope();
        $items = Database::fetchAll(
            "SELECT n.id, n.type, n.level, n.title, n.message, n.link, n.created_at, (nr.user_id IS NOT NULL) AS is_read
               FROM notifications n
               LEFT JOIN notification_reads nr ON nr.notification_id = n.id AND nr.user_id = :me
              WHERE {$sql} ORDER BY n.id DESC LIMIT 20",
            $params + ['me' => Auth::id()]
        );
        $unread = (int) Database::value(
            "SELECT COUNT(*) FROM notifications n
               LEFT JOIN notification_reads nr ON nr.notification_id = n.id AND nr.user_id = :me
              WHERE {$sql} AND nr.user_id IS NULL",
            $params + ['me' => Auth::id()]
        );
        foreach ($items as &$i) {
            $i['is_read'] = (bool) $i['is_read'];
        }
        return $this->json(['success' => true, 'unread' => $unread, 'items' => $items]);
    }

    public function markRead(Request $request): Response
    {
        [$sql, $params] = $this->visibleScope();
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));
        $extra = '';
        if ($ids) {
            $extra = ' AND n.id IN (' . implode(',', $ids) . ')';
        }
        Database::query(
            "INSERT IGNORE INTO notification_reads (notification_id, user_id)
             SELECT n.id, :me FROM notifications n WHERE {$sql}{$extra}",
            $params + ['me' => Auth::id()]
        );
        return $this->json(['success' => true]);
    }

    private function visibleScope(): array
    {
        $perms = Auth::permissions();
        $params = ['uid' => Auth::id()];
        if (in_array('*', $perms, true)) {
            return ['(n.user_id = :uid OR n.user_id IS NULL)', $params];
        }
        $placeholders = [];
        foreach (array_values($perms) as $i => $p) {
            $placeholders[] = ':p' . $i;
            $params['p' . $i] = $p;
        }
        $permSql = $placeholders ? 'n.permission IN (' . implode(',', $placeholders) . ')' : '0';
        return ["(n.user_id = :uid OR (n.user_id IS NULL AND (n.permission IS NULL OR {$permSql})))", $params];
    }

    public function theme(Request $request): Response
    {
        $theme = $request->str('theme');
        if (!in_array($theme, ['light', 'dark', 'auto'], true)) {
            return $this->json(['success' => false, 'message' => 'Thème invalide.'], 422);
        }
        Database::update('users', ['theme' => $theme], 'id = :id', ['id' => Auth::id()]);
        return $this->json(['success' => true, 'theme' => $theme]);
    }

    /** Mémorise le routeur actif dans la session (sélecteur de la barre supérieure). */
    public function selectRouter(Request $request): Response
    {
        $id = $request->int('router_id');
        if ($id === 0) {
            Session::forget('active_router_id');
            return $this->json(['success' => true, 'router_id' => null]);
        }
        $exists = Database::value('SELECT id FROM routers WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
        if (!$exists) {
            return $this->json(['success' => false, 'message' => 'Routeur introuvable.'], 404);
        }
        Session::put('active_router_id', $id);
        return $this->json(['success' => true, 'router_id' => $id]);
    }
}
