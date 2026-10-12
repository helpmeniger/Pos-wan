<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Verifia\Auth;
use Verifia\Database;
use Verifia\View;

Auth::require('viewer');
$level = in_array($_GET['level'] ?? '', ['debug', 'info', 'warning', 'error', 'critical'], true) ? $_GET['level'] : '';
$sid = (int) ($_GET['session_id'] ?? 0);
$page = max(0, (int) ($_GET['page'] ?? 0));
$where = [];
$params = [];
if ($level) { $where[] = 'e.level = ?'; $params[] = $level; }
if ($sid) { $where[] = 'e.session_id = ?'; $params[] = $sid; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$events = Database::all("SELECT e.*, s.name AS session_name FROM system_events e LEFT JOIN monitoring_sessions s ON s.id = e.session_id $w ORDER BY e.id DESC LIMIT 200 OFFSET " . ($page * 200), $params);
$audit = Auth::can('admin') ? Database::all('SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 100') : [];
$sessions = Database::all('SELECT id, name FROM monitoring_sessions ORDER BY name');
View::header('Journal', 'events');
?>
<h1 class="h4">Journal des événements</h1>
<form class="row g-2 mb-2 small">
  <div class="col-auto"><select class="form-select form-select-sm" name="level"><option value="">Tous niveaux</option><?php foreach (['info', 'warning', 'error', 'critical'] as $l): ?><option <?= $l === $level ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><select class="form-select form-select-sm" name="session_id"><option value="">Toutes sessions</option><?php foreach ($sessions as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sid === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><button class="btn btn-sm btn-secondary">Filtrer</button></div>
</form>
<table class="table table-sm table-striped small"><thead><tr><th>Date</th><th>Niveau</th><th>Composant</th><th>Session</th><th>Message</th></tr></thead><tbody>
<?php foreach ($events as $ev): ?><tr class="lvl-<?= e($ev['level']) ?>"><td class="text-nowrap"><?= e(View::date($ev['created_at'])) ?></td><td><?= e($ev['level']) ?></td><td><?= e($ev['component']) ?></td><td><?= e($ev['session_name'] ?? '') ?></td><td><?= e($ev['message']) ?><?= $ev['context_json'] ? '<br><code class="small">' . e($ev['context_json']) . '</code>' : '' ?></td></tr><?php endforeach; ?>
</tbody></table>
<a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query(['level' => $level, 'session_id' => $sid ?: '', 'page' => $page + 1])) ?>">Page suivante</a>
<?php if ($audit): ?>
<h2 class="h5 mt-4">Journal d'audit (administrateurs)</h2>
<table class="table table-sm small"><thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Cible</th><th>IP</th><th>Détails</th></tr></thead><tbody>
<?php foreach ($audit as $a): ?><tr><td class="text-nowrap"><?= e(View::date($a['created_at'])) ?></td><td><?= e($a['username'] ?? '—') ?></td><td><?= e($a['action']) ?></td><td><?= e(trim(($a['target_type'] ?? '') . ' ' . ($a['target_id'] ?? ''))) ?></td><td><?= e($a['ip_address']) ?></td><td><code class="small"><?= e($a['details_json'] ?? '') ?></code></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?>
<?php View::footer();
