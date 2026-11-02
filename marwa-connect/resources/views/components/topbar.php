<?php
use App\Core\Database;
use App\Core\Session;
$routers = can('routers.view', 'dashboard.view')
    ? Database::fetchAll("SELECT id, name, connection_state FROM routers WHERE deleted_at IS NULL AND status = 'active' ORDER BY is_default DESC, name")
    : [];
$activeRouter = (int) Session::get('active_router_id', 0);
$initials = mb_strtoupper(implode('', array_map(static fn ($p) => mb_substr($p, 0, 1), array_slice(preg_split('/\s+/', trim($user['full_name'])), 0, 2))));
?>
<header class="mc-topbar">
    <a class="mc-brand" href="<?= e(url('/')) ?>">
        <?php $logo = (string) setting('company.logo', ''); ?>
        <img src="<?= e(asset($logo ?: 'images/logo.svg')) ?>" alt="" height="34" style="max-width:110px;object-fit:contain">
        <span class="mc-brand-text"><strong>MARWA</strong> CONNECT</span>
    </a>

    <div class="mc-router-select">
        <i class="bi bi-router"></i>
        <select class="form-select form-select-sm" id="mc-router-switch" aria-label="Routeur actif" <?= $routers ? '' : 'disabled' ?>>
            <?php if (!$routers): ?>
                <option>Aucun routeur configuré</option>
            <?php else: ?>
                <option value="0">Tous les routeurs</option>
                <?php foreach ($routers as $r): ?>
                    <option value="<?= (int) $r['id'] ?>" <?= $activeRouter === (int) $r['id'] ? 'selected' : '' ?>>
                        <?= e($r['name']) ?><?= $r['connection_state'] === 'offline' ? ' (hors ligne)' : '' ?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
    </div>

    <div class="mc-topbar-actions">
        <span class="mc-conn" id="mc-conn" data-state="checking" title="Vérification de la connexion…">
            <span class="mc-conn-dot"></span><span class="mc-conn-label d-none d-md-inline">Connexion…</span>
        </span>

        <button class="btn mc-icon-btn" type="button" id="mc-theme-toggle" title="Basculer clair / sombre" aria-label="Basculer le thème">
            <i class="bi bi-moon-stars"></i>
        </button>

        <div class="dropdown">
            <button class="btn mc-icon-btn position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications" id="mc-notif-btn">
                <i class="bi bi-bell"></i>
                <span class="mc-badge d-none" id="mc-notif-count">0</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end mc-notif-menu">
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                    <strong>Notifications</strong>
                    <button class="btn btn-link btn-sm p-0" type="button" id="mc-notif-readall">Tout marquer lu</button>
                </div>
                <div id="mc-notif-list" class="mc-notif-list"><div class="text-muted small p-3">Chargement…</div></div>
            </div>
        </div>

        <div class="dropdown">
            <button class="btn mc-user-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="mc-avatar"><?= e($initials ?: '?') ?></span>
                <span class="d-none d-lg-flex flex-column text-start lh-sm">
                    <span class="fw-semibold small"><?= e($user['full_name']) ?></span>
                    <span class="text-muted mc-xs"><?= e($user['role_name']) ?></span>
                </span>
                <i class="bi bi-chevron-down small d-none d-lg-inline"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><h6 class="dropdown-header"><?= e($user['email']) ?></h6></li>
                <li><a class="dropdown-item" href="<?= e(url('/profile')) ?>"><i class="bi bi-person-circle me-2"></i>Mon profil</a></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="post" action="<?= e(url('/logout')) ?>">
                        <?= csrf_field() ?>
                        <button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Déconnexion</button>
                    </form>
                </li>
            </ul>
        </div>

        <button class="btn mc-icon-btn" type="button" id="mc-sidebar-toggle" aria-label="Afficher / masquer le menu" aria-controls="mc-sidebar">
            <i class="bi bi-layout-sidebar-inset-reverse"></i>
        </button>
    </div>
</header>
