<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\NotificationService;
use Throwable;

final class NotificationController extends Controller
{
    private function scope(): array
    {
        $perms = Auth::permissions();
        $p = ['uid' => Auth::id()];
        if (in_array('*', $perms, true)) {
            return ['(n.user_id = :uid OR n.user_id IS NULL)', $p];
        }
        $ph = [];
        foreach (array_values($perms) as $i => $perm) {
            $ph[] = ':p' . $i;
            $p['p' . $i] = $perm;
        }
        return ['(n.user_id = :uid OR (n.user_id IS NULL AND (n.permission IS NULL' . ($ph ? ' OR n.permission IN (' . implode(',', $ph) . ')' : '') . ')))', $p];
    }

    public function index(Request $request): Response
    {
        $this->authorize('notifications.view');
        [$sql, $p] = $this->scope();
        $level = $request->str('level');
        if (in_array($level, ['info', 'success', 'warning', 'danger'], true)) {
            $sql .= ' AND n.level = :lvl';
            $p['lvl'] = $level;
        }
        if ($request->str('unread')) {
            $sql .= ' AND nr.user_id IS NULL';
        }
        $page = max(1, $request->int('page', 1));
        $p['me'] = Auth::id();
        $total = (int) Database::value("SELECT COUNT(*) FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id = n.id AND nr.user_id = :me WHERE {$sql}", $p);
        $items = Database::fetchAll("SELECT n.*, nr.read_at FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id = n.id AND nr.user_id = :me WHERE {$sql} ORDER BY n.id DESC LIMIT 50 OFFSET " . (($page - 1) * 50), $p);
        $rules = can('notifications.manage') ? Database::fetchAll('SELECT * FROM alert_rules ORDER BY id') : [];
        $channels = [];
        if (can('notifications.manage')) {
            foreach (['email', 'whatsapp'] as $c) {
                $channels[$c] = NotificationService::channelConfig($c) ?? ['is_active' => 0, 'config' => [], 'recipients' => []];
            }
        }
        return $this->view('notifications/index', ['items' => $items, 'rules' => $rules, 'channels' => $channels, 'level' => $level,
            'unread' => $request->str('unread'), 'page' => $page, 'pages' => (int) ceil($total / 50), 'tab' => $request->str('tab', 'list')]);
    }

    public function readAll(Request $request): Response
    {
        $this->authorize('notifications.view');
        [$sql, $p] = $this->scope();
        Database::query("INSERT IGNORE INTO notification_reads (notification_id, user_id) SELECT n.id, :me FROM notifications n WHERE {$sql}", $p + ['me' => Auth::id()]);
        Session::flash('success', 'Toutes les notifications sont marquées comme lues.');
        return $this->redirect('/notifications');
    }

    public function saveRules(Request $request): Response
    {
        $this->authorize('notifications.manage');
        foreach (Database::fetchAll('SELECT * FROM alert_rules') as $rule) {
            $in = (array) ($request->input('rules', [])[$rule['id']] ?? []);
            $channels = array_values(array_intersect((array) ($in['channels'] ?? []), ['email', 'whatsapp']));
            array_unshift($channels, 'app');
            Database::update('alert_rules', [
                'is_enabled' => !empty($in['enabled']) ? 1 : 0,
                'threshold' => isset($in['threshold']) && $in['threshold'] !== '' && is_numeric($in['threshold']) ? (float) $in['threshold'] : $rule['threshold'],
                'cooldown_minutes' => isset($in['cooldown']) && is_numeric($in['cooldown']) ? max(0, (int) $in['cooldown']) : $rule['cooldown_minutes'],
                'channels' => json_encode($channels),
            ], 'id = :id', ['id' => $rule['id']]);
        }
        AuditService::log('alerts.update', 'alert_rules', null, 'Mise à jour des règles d\'alerte');
        Session::flash('success', 'Règles d\'alerte enregistrées.');
        return $this->redirect('/notifications?tab=rules');
    }

    public function saveChannel(Request $request, string $channel): Response
    {
        $this->authorize('notifications.manage');
        if (!in_array($channel, ['email', 'whatsapp'], true)) {
            return $this->redirect('/notifications');
        }
        $existing = NotificationService::channelConfig($channel);
        $fields = $channel === 'email' ? ['host', 'port', 'encryption', 'username', 'password', 'from', 'from_name'] : ['token', 'phone_number_id'];
        $config = [];
        foreach ($fields as $f) {
            $v = $request->str($f);
            $config[$f] = ($v === '' && in_array($f, ['password', 'token'], true)) ? ($existing['config'][$f] ?? '') : $v;
        }
        $recipients = array_values(array_filter(array_map('trim', preg_split('/[\n,;]+/', $request->str('recipients')))));
        Database::query(
            'INSERT INTO notification_channels (channel, is_active, config_enc, recipients) VALUES (:c, :a, :cfg, :r)
             ON DUPLICATE KEY UPDATE is_active = VALUES(is_active), config_enc = VALUES(config_enc), recipients = VALUES(recipients)',
            ['c' => $channel, 'a' => $request->input('is_active') ? 1 : 0, 'cfg' => Crypto::encrypt((string) json_encode($config)), 'r' => json_encode($recipients)]
        );
        AuditService::log('channel.update', 'notification_channel', null, "Configuration du canal {$channel}");
        if ($request->input('test')) {
            try {
                NotificationService::dispatch($channel, 'Test de notification', 'Ceci est un message de test envoyé par MARWA CONNECT.');
                Session::flash('success', 'Configuration enregistrée et message de test envoyé.');
            } catch (Throwable $e) {
                Session::flash('danger', 'Configuration enregistrée, mais le test a échoué : ' . $e->getMessage());
            }
        } else {
            Session::flash('success', 'Canal enregistré.');
        }
        return $this->redirect('/notifications?tab=channels');
    }
}
