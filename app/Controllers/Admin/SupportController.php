<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\NotificationService;

/** Réclamations, litiges, alertes de sécurité, modération des avis, notifications de masse. */
final class SupportController extends Controller
{
    public function complaints(Request $r): Response
    {
        $s = $r->input('status');
        return $this->json(Database::select('SELECT c.*, u.full_name, o.reference order_reference, a.full_name assignee FROM complaints c JOIN users u ON u.id = c.user_id
              LEFT JOIN orders o ON o.id = c.order_id LEFT JOIN users a ON a.id = c.assigned_to ' . ($s ? 'WHERE c.status = :s ' : '') .
            "ORDER BY FIELD(c.priority,'urgent','high','normal','low'), c.id DESC LIMIT 300", $s ? ['s' => (string) $s] : []));
    }

    public function complaint(Request $r): Response
    {
        $c = Database::first('SELECT c.*, u.full_name, o.reference order_reference FROM complaints c JOIN users u ON u.id = c.user_id LEFT JOIN orders o ON o.id = c.order_id WHERE c.id = :id', ['id' => $r->intParam('id')]);
        if (!$c) {
            throw HttpException::notFound();
        }
        $c['messages'] = Database::select('SELECT m.*, u.full_name FROM complaint_messages m JOIN users u ON u.id = m.author_id WHERE m.complaint_id = :c ORDER BY m.id', ['c' => $c['id']]);
        return $this->json($c);
    }

    public function updateComplaint(Request $r): Response
    {
        $id = $r->intParam('id');
        $v = $this->validate($r, ['status' => 'required|in:open,in_progress,resolved,rejected', 'priority' => 'nullable|in:low,normal,high,urgent', 'resolution' => 'nullable|string|max:4000',
            'message' => 'nullable|string|max:4000', 'internal' => 'nullable|bool', 'assign_to_me' => 'nullable|bool']);
        $c = Database::first('SELECT * FROM complaints WHERE id = :id', ['id' => $id]);
        if (!$c) {
            throw HttpException::notFound();
        }
        if (in_array($v['status'], ['resolved', 'rejected'], true) && empty($v['resolution'])) {
            throw \App\Core\ValidationException::field('resolution', 'Indiquez la décision prise.');
        }
        $data = ['status' => $v['status'], 'priority' => $v['priority'] ?? $c['priority'], 'resolution' => $v['resolution'] ?? $c['resolution']];
        if (!empty($v['assign_to_me'])) {
            $data['assigned_to'] = Auth::id();
        }
        if (in_array($v['status'], ['resolved', 'rejected'], true)) {
            $data['resolved_at'] = date('Y-m-d H:i:s');
        }
        Database::update('complaints', $data, 'id = :id', ['id' => $id]);
        if (!empty($v['message'])) {
            Database::insert('complaint_messages', ['complaint_id' => $id, 'author_id' => Auth::id(), 'body' => $v['message'], 'is_internal' => !empty($v['internal']) ? 1 : 0]);
        }
        Audit::log('complaint.updated', 'complaint', $id, ['status' => $c['status']], $data);
        if (empty($v['internal']) && (!empty($v['message']) || $c['status'] !== $v['status'])) {
            NotificationService::notify((int) $c['user_id'], 'complaint', 'Réclamation ' . $c['reference'], $v['message'] ?? ('Statut : ' . $v['status']), ['complaint_id' => $id], ['email']);
        }
        return $this->ok('Réclamation mise à jour.');
    }

    public function alerts(Request $r): Response
    {
        return $this->json(Database::select('SELECT a.*, u.full_name, u.phone, o.reference FROM emergency_alerts a JOIN users u ON u.id = a.user_id LEFT JOIN orders o ON o.id = a.order_id ORDER BY a.status = \'open\' DESC, a.id DESC LIMIT 200'));
    }

    public function handleAlert(Request $r): Response
    {
        $id = $r->intParam('id');
        $v = $this->validate($r, ['status' => 'required|in:acknowledged,closed']);
        Database::update('emergency_alerts', ['status' => $v['status'], 'handled_by' => Auth::id(), 'handled_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        Audit::log('alert.' . $v['status'], 'emergency_alert', $id);
        return $this->ok('Alerte mise à jour.');
    }

    public function ratings(Request $r): Response
    {
        return $this->json(Database::select("SELECT ra.*, u.full_name rater, o.reference FROM ratings ra JOIN users u ON u.id = ra.rater_id JOIN orders o ON o.id = ra.order_id
                                              WHERE ra.comment IS NOT NULL ORDER BY ra.status = 'pending' DESC, ra.id DESC LIMIT 300"));
    }

    public function moderateRating(Request $r): Response
    {
        $v = $this->validate($r, ['status' => 'required|in:published,hidden']);
        Database::update('ratings', ['status' => $v['status']], 'id = :id', ['id' => $r->intParam('id')]);
        Audit::log('rating.' . $v['status'], 'rating', $r->intParam('id'));
        return $this->ok('Avis modéré.');
    }

    public function broadcast(Request $r): Response
    {
        $v = $this->validate($r, ['audience' => 'required|in:client,driver,courier,merchant,business,all', 'title' => 'required|string|max:150', 'body' => 'required|string|max:500']);
        $sql = "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.status = 'active' AND r.is_staff = 0";
        $p = [];
        if ($v['audience'] !== 'all') {
            $sql .= ' AND r.code = :r';
            $p['r'] = $v['audience'];
        }
        $n = 0;
        foreach (Database::select($sql, $p) as $u) {
            NotificationService::notify((int) $u['id'], 'broadcast', $v['title'], $v['body']);
            $n++;
        }
        Audit::log('notification.broadcast', null, null, null, $v + ['recipients' => $n]);
        return $this->ok("Notification envoyée à $n utilisateur(s).");
    }
}
