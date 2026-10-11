<div class="page-header"><h1><i class="bi bi-person-gear"></i> Utilisateurs</h1>
    <a class="btn btn-primary" href="<?= e(url('users.create')) ?>"><i class="bi bi-person-plus"></i> Nouveau compte</a></div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Identifiant</th><th>Nom</th><th>Rôle</th><th>Service</th><th>Dernière connexion</th><th>État</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr class="<?= $u['is_active'] ? '' : 'table-secondary' ?>">
            <td><?= e($u['username']) ?><?= $u['is_demo'] ? ' <span class="badge badge-demo">démo</span>' : '' ?></td>
            <td><?= e($u['full_name']) ?></td><td><?= e($u['role_label']) ?></td><td class="small"><?= e($u['service'] ?? '') ?></td>
            <td class="small"><?= e(fmt_date($u['last_login_at'], true)) ?></td>
            <td>
                <?php if (!$u['is_active']): ?><span class="badge text-bg-secondary" title="<?= e($u['disabled_reason']) ?>">Désactivé</span>
                <?php elseif ($u['locked_until'] && strtotime($u['locked_until']) > time()): ?><span class="badge text-bg-danger">Verrouillé</span>
                <?php else: ?><span class="badge text-bg-success">Actif</span><?php endif; ?>
                <?php if ($u['must_change_password']): ?><span class="badge text-bg-light">MDP à changer</span><?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="<?= e(url('users.edit', ['id' => $u['id']])) ?>">Modifier</a>
                <?php if ((int) $u['id'] !== \App\Core\Auth::id()): ?>
                <form method="post" class="d-inline" action="<?= e(url('users.toggle', ['id' => $u['id']])) ?>"
                      data-confirm="<?= $u['is_active'] ? 'Désactiver ce compte ? Ses sessions seront révoquées.' : 'Réactiver ce compte ?' ?>"
                      <?= $u['is_active'] ? 'data-prompt="Motif de la désactivation" data-prompt-field="reason"' : '' ?>>
                    <?= csrf_field() ?><input type="hidden" name="reason" value="">
                    <button class="btn btn-sm <?= $u['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit"><?= $u['is_active'] ? 'Désactiver' : 'Réactiver' ?></button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
<p class="small text-muted mt-2">Les comptes inactifs depuis plus de <?= (int) \App\Core\Settings::int('account_inactivity_days') ?> jours sont automatiquement désactivés à la tentative de connexion suivante.</p>
