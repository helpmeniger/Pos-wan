<?php
$title = 'Notifications';
$icons = ['info' => 'info-circle text-info', 'success' => 'check-circle text-success', 'warning' => 'exclamation-triangle text-warning', 'danger' => 'exclamation-octagon text-danger'];
$tabs = ['list' => 'Notifications'] + (can('notifications.manage') ? ['rules' => "Règles d'alerte", 'channels' => 'Canaux (e-mail, WhatsApp)'] : []);
?>
<div class="mc-page-head"><div><h1>Centre de <span class="mc-accent">notifications</span></h1><p>Alertes réseau, stock, paiements et caisse.</p></div>
    <form method="post" action="<?= e(url('/notifications/read-all')) ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary"><i class="bi bi-check2-all"></i> Tout marquer lu</button></form></div>
<ul class="nav nav-tabs mb-3"><?php foreach ($tabs as $k => $l): ?><li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="<?= e(url('/notifications', ['tab' => $k])) ?>"><?= e($l) ?></a></li><?php endforeach; ?></ul>

<?php if ($tab === 'rules' && can('notifications.manage')): ?>
<form class="mc-card" method="post" action="<?= e(url('/notifications/rules')) ?>"><?= csrf_field() ?>
    <div class="table-responsive"><table class="table mc-table">
        <thead><tr><th>Active</th><th>Alerte</th><th>Seuil</th><th>Délai entre 2 alertes (min)</th><th>E-mail</th><th>WhatsApp</th></tr></thead><tbody>
        <?php foreach ($rules as $r): $ch = json_decode((string) $r['channels'], true) ?: []; ?>
            <tr><td><input class="form-check-input" type="checkbox" name="rules[<?= (int) $r['id'] ?>][enabled]" value="1" <?= $r['is_enabled'] ? 'checked' : '' ?> aria-label="Activer"></td>
                <td><?= e($r['name']) ?> <span class="badge text-bg-<?= $r['level'] === 'danger' ? 'danger' : ($r['level'] === 'warning' ? 'warning' : 'secondary') ?>"><?= e($r['level']) ?></span></td>
                <td style="max-width:120px"><?php if ($r['threshold'] !== null): ?><input class="form-control form-control-sm" name="rules[<?= (int) $r['id'] ?>][threshold]" value="<?= e((float) $r['threshold']) ?>"><?php else: ?>—<?php endif; ?></td>
                <td style="max-width:120px"><input class="form-control form-control-sm" name="rules[<?= (int) $r['id'] ?>][cooldown]" value="<?= (int) $r['cooldown_minutes'] ?>"></td>
                <td><input class="form-check-input" type="checkbox" name="rules[<?= (int) $r['id'] ?>][channels][]" value="email" <?= in_array('email', $ch, true) ? 'checked' : '' ?> aria-label="E-mail"></td>
                <td><input class="form-check-input" type="checkbox" name="rules[<?= (int) $r['id'] ?>][channels][]" value="whatsapp" <?= in_array('whatsapp', $ch, true) ? 'checked' : '' ?> aria-label="WhatsApp"></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <div class="mc-card-head border-top border-bottom-0 justify-content-end"><button class="btn btn-primary">Enregistrer</button></div></form>

<?php elseif ($tab === 'channels' && can('notifications.manage')): ?>
<div class="row g-3">
    <div class="col-lg-6"><form class="mc-card h-100" method="post" action="<?= e(url('/notifications/channels/email')) ?>"><?= csrf_field() ?>
        <?php $c = $channels['email']; $cf = $c['config']; ?>
        <div class="mc-card-head"><h2><i class="bi bi-envelope me-2 text-primary"></i>E-mail (SMTP)</h2><div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?= $c['is_active'] ? 'checked' : '' ?> aria-label="Actif"></div></div>
        <div class="mc-card-body row g-2">
            <div class="col-8"><label class="form-label small">Serveur SMTP</label><input class="form-control form-control-sm" name="host" value="<?= e($cf['host'] ?? '') ?>" placeholder="smtp.gmail.com"></div>
            <div class="col-4"><label class="form-label small">Port</label><input class="form-control form-control-sm" name="port" value="<?= e($cf['port'] ?? '587') ?>"></div>
            <div class="col-6"><label class="form-label small">Chiffrement</label><select class="form-select form-select-sm" name="encryption"><?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL', 'none' => 'Aucun'] as $k => $l): ?><option value="<?= $k ?>" <?= ($cf['encryption'] ?? 'tls') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label small">Utilisateur</label><input class="form-control form-control-sm" name="username" value="<?= e($cf['username'] ?? '') ?>" autocomplete="off"></div>
            <div class="col-6"><label class="form-label small">Mot de passe</label><input class="form-control form-control-sm" type="password" name="password" placeholder="<?= !empty($cf['password']) ? 'Inchangé si vide' : '' ?>" autocomplete="new-password"></div>
            <div class="col-6"><label class="form-label small">Expéditeur</label><input class="form-control form-control-sm" name="from" value="<?= e($cf['from'] ?? '') ?>" placeholder="alertes@domaine.ne"></div>
            <div class="col-12"><label class="form-label small">Nom de l'expéditeur</label><input class="form-control form-control-sm" name="from_name" value="<?= e($cf['from_name'] ?? 'MARWA CONNECT') ?>"></div>
            <div class="col-12"><label class="form-label small">Destinataires (un par ligne)</label><textarea class="form-control form-control-sm" name="recipients" rows="2"><?= e(implode("\n", $c['recipients'])) ?></textarea></div>
        </div>
        <div class="mc-card-head border-top border-bottom-0 justify-content-end gap-2"><button class="btn btn-sm btn-outline-secondary" name="test" value="1">Enregistrer et tester</button><button class="btn btn-sm btn-primary">Enregistrer</button></div></form></div>
    <div class="col-lg-6"><form class="mc-card h-100" method="post" action="<?= e(url('/notifications/channels/whatsapp')) ?>"><?= csrf_field() ?>
        <?php $c = $channels['whatsapp']; $cf = $c['config']; ?>
        <div class="mc-card-head"><h2><i class="bi bi-whatsapp me-2 text-success"></i>WhatsApp Business (Cloud API)</h2><div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?= $c['is_active'] ? 'checked' : '' ?> aria-label="Actif"></div></div>
        <div class="mc-card-body row g-2">
            <div class="col-12"><label class="form-label small">Jeton d'accès</label><input class="form-control form-control-sm" type="password" name="token" placeholder="<?= !empty($cf['token']) ? 'Inchangé si vide' : 'EAAG…' ?>" autocomplete="off"></div>
            <div class="col-12"><label class="form-label small">ID du numéro expéditeur</label><input class="form-control form-control-sm" name="phone_number_id" value="<?= e($cf['phone_number_id'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label small">Numéros destinataires (format international, un par ligne)</label><textarea class="form-control form-control-sm" name="recipients" rows="3" placeholder="22790000000"><?= e(implode("\n", $c['recipients'])) ?></textarea></div>
            <p class="small text-muted mb-0">Nécessite un compte WhatsApp Business (Meta) et un accès Internet. Les alertes restent toujours visibles dans l'application.</p>
        </div>
        <div class="mc-card-head border-top border-bottom-0 justify-content-end gap-2"><button class="btn btn-sm btn-outline-secondary" name="test" value="1">Enregistrer et tester</button><button class="btn btn-sm btn-primary">Enregistrer</button></div></form></div>
</div>

<?php else: ?>
<div class="mc-card">
    <form class="mc-card-head mc-filter-bar" method="get" action="<?= e(url('/notifications')) ?>">
        <select class="form-select form-select-sm" name="level" data-autosubmit><option value="">Tous niveaux</option><?php foreach (['danger' => 'Critique', 'warning' => 'Avertissement', 'info' => 'Information', 'success' => 'Succès'] as $k => $l): ?><option value="<?= $k ?>" <?= $level === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
        <div class="form-check m-0"><input class="form-check-input" type="checkbox" name="unread" value="1" id="unread" <?= $unread ? 'checked' : '' ?> data-autosubmit><label class="form-check-label small" for="unread">Non lues</label></div>
    </form>
    <ul class="list-group list-group-flush">
    <?php foreach ($items as $n): ?>
        <li class="list-group-item d-flex gap-3 <?= $n['read_at'] ? '' : 'mc-notif-item unread' ?>">
            <i class="bi bi-<?= $icons[$n['level']] ?? $icons['info'] ?> fs-5"></i>
            <div class="flex-grow-1"><div class="fw-semibold"><?= e($n['title']) ?></div><div class="small"><?= e($n['message']) ?></div>
                <small class="text-muted"><?= e(date_fr($n['created_at'], 'd/m/Y H:i')) ?></small></div>
            <?php if ($n['link']): ?><a class="btn btn-sm btn-outline-secondary align-self-center" href="<?= e(url($n['link'])) ?>">Voir</a><?php endif; ?>
        </li>
    <?php endforeach; ?>
    <?php if (!$items): ?><li class="list-group-item"><div class="mc-empty"><i class="bi bi-bell-slash"></i>Aucune notification.</div></li><?php endif; ?>
    </ul>
    <?= pagination($page, $pages, '/notifications', ['level' => $level, 'unread' => $unread]) ?>
</div>
<?php endif; ?>
