<?php
$title = 'Utilisateurs';
$statusMap = ['active' => ['ok', 'Actif'], 'suspended' => ['warn', 'Suspendu'], 'disabled' => ['muted', 'Désactivé']];
?>
<div class="mc-page-head">
    <div>
        <h1>Utilisa<span class="mc-accent">teurs</span></h1>
        <p>Comptes de l'équipe et rôles attribués.</p>
    </div>
    <?php if (can('users.manage')): ?>
        <a class="btn btn-primary" href="<?= e(url('/admin/users/create')) ?>"><i class="bi bi-person-plus me-1"></i> Nouvel utilisateur</a>
    <?php endif; ?>
</div>

<div class="mc-card">
    <form class="mc-card-head flex-wrap" method="get" action="<?= e(url('/admin/users')) ?>">
        <div class="d-flex gap-2 flex-wrap flex-grow-1">
            <input class="form-control form-control-sm" style="max-width:260px" type="search" name="q" value="<?= e($q) ?>" placeholder="Nom, identifiant, e-mail…">
            <select class="form-select form-select-sm" style="max-width:200px" name="role">
                <option value="0">Tous les rôles</option>
                <?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $role === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm" style="max-width:160px" name="status">
                <option value="">Tous les statuts</option>
                <?php foreach ($statusMap as $k => [, $l]): ?><option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-navy" type="submit"><i class="bi bi-funnel"></i> Filtrer</button>
        </div>
        <span class="text-muted small"><?= count($users) ?> résultat(s)</span>
    </form>
    <div class="table-responsive">
        <table class="table mc-table table-hover">
            <thead><tr><th>Nom</th><th>Identifiant</th><th>Rôle</th><th>Statut</th><th>Dernière connexion</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php if (!$users): ?>
                <tr><td colspan="6"><div class="mc-empty"><i class="bi bi-people"></i>Aucun utilisateur trouvé.</div></td></tr>
            <?php endif; ?>
            <?php foreach ($users as $u): [$cls, $lbl] = $statusMap[$u['status']] ?? ['muted', $u['status']]; ?>
                <tr>
                    <td><div class="fw-semibold"><?= e($u['full_name']) ?></div><small class="text-muted"><?= e($u['email']) ?></small></td>
                    <td><code><?= e($u['username']) ?></code></td>
                    <td><?= e($u['role_name']) ?></td>
                    <td><span class="mc-status <?= e($cls) ?>"><?= e($lbl) ?></span></td>
                    <td class="small text-muted"><?= e(date_fr($u['last_login_at'])) ?></td>
                    <td class="text-end text-nowrap">
                        <?php if (can('users.manage') && ($u['role_slug'] !== 'super_admin' || App\Core\Auth::hasRole('super_admin'))): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/users/' . $u['id'] . '/edit')) ?>" title="Modifier"><i class="bi bi-pencil"></i></a>
                            <?php if ((int) $u['id'] !== App\Core\Auth::id()): ?>
                                <form class="d-inline" method="post" action="<?= e(url('/admin/users/' . $u['id'] . '/status')) ?>"
                                      data-confirm="<?= $u['status'] === 'active' ? 'Suspendre ce compte ?' : 'Réactiver ce compte ?' ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-<?= $u['status'] === 'active' ? 'warning' : 'success' ?>" title="<?= $u['status'] === 'active' ? 'Suspendre' : 'Réactiver' ?>">
                                        <i class="bi bi-<?= $u['status'] === 'active' ? 'pause-circle' : 'play-circle' ?>"></i></button>
                                </form>
                                <form class="d-inline" method="post" action="<?= e(url('/admin/users/' . $u['id'])) ?>" data-confirm="Supprimer définitivement l'accès de cet utilisateur ? L'historique est conservé.">
                                    <?= csrf_field() ?><?= method_field('DELETE') ?>
                                    <button class="btn btn-sm btn-outline-danger" title="Supprimer"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
