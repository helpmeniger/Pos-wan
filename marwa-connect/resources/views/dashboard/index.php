<?php
$title = 'Tableau de bord';
$t = static fn (string $s) => (int) ($ticketCounts[$s] ?? 0);
$soldTotal = $t('sold') + $t('active') + $t('used') + $t('expired');
?>
<div class="mc-page-head">
    <div>
        <h1>Tableau de <span class="mc-accent">bord</span></h1>
        <p>Vue d'ensemble de votre activité Wi-Fi — données réelles issues de la base.</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="mc-card mc-kpi">
            <div class="mc-kpi-icon"><i class="bi bi-cash-stack"></i></div>
            <div class="mc-kpi-label">CA du jour</div>
            <div class="mc-kpi-value"><?= e(money($stats['revenue_today'])) ?></div>
            <div class="mc-kpi-sub"><?= (int) $stats['sales_today'] ?> vente(s) aujourd'hui</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="mc-card mc-kpi navy">
            <div class="mc-kpi-icon"><i class="bi bi-calendar3"></i></div>
            <div class="mc-kpi-label">CA du mois</div>
            <div class="mc-kpi-value"><?= e(money($stats['revenue_month'])) ?></div>
            <div class="mc-kpi-sub"><?= e(date('m/Y')) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="mc-card mc-kpi green">
            <div class="mc-kpi-icon"><i class="bi bi-ticket-perforated"></i></div>
            <div class="mc-kpi-label">Tickets disponibles</div>
            <div class="mc-kpi-value"><?= $t('available') ?></div>
            <div class="mc-kpi-sub"><?= $soldTotal ?> vendus · <?= $t('expired') ?> expirés</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="mc-card mc-kpi amber">
            <div class="mc-kpi-icon"><i class="bi bi-router"></i></div>
            <div class="mc-kpi-label">Routeurs en ligne</div>
            <div class="mc-kpi-value"><?= (int) $stats['routers_online'] ?> / <?= (int) $stats['routers_total'] ?></div>
            <div class="mc-kpi-sub"><?= (int) $stats['routers_offline'] ?> hors ligne · <?= (int) $stats['active_sessions'] ?> client(s) connecté(s)</div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="mc-card h-100">
            <div class="mc-card-head"><h2><i class="bi bi-flag me-2 text-primary"></i>Mise en service</h2></div>
            <div class="mc-card-body">
                <ol class="mc-setup-steps">
                    <li class="done"><div><strong>Installation et sécurité</strong><div class="text-muted small">Base de données, rôles et compte administrateur opérationnels.</div></div></li>
                    <li class="<?= $stats['users'] > 1 ? 'done' : '' ?>"><div><strong>Créer les comptes de l'équipe</strong><div class="text-muted small">Caissiers, techniciens, comptables… <?php if (can('users.manage')): ?><a href="<?= e(url('/admin/users/create')) ?>">Ajouter un utilisateur</a><?php endif; ?></div></div></li>
                    <li class="<?= $stats['routers_total'] > 0 ? 'done' : '' ?>"><div><strong>Enregistrer les routeurs MikroTik</strong><div class="text-muted small">Connexion réelle via l'API RouterOS (phase 3).</div></div></li>
                    <li class="<?= $stats['offers'] > 0 ? 'done' : '' ?>"><div><strong>Configurer les offres</strong><div class="text-muted small"><?= (int) $stats['offers'] ?> offre(s) active(s) — association aux profils Hotspot en phase 4.</div></div></li>
                    <li class="<?= array_sum(array_map('intval', $ticketCounts)) > 0 ? 'done' : '' ?>"><div><strong>Générer et vendre des tickets</strong><div class="text-muted small">Phases 5 et 6.</div></div></li>
                </ol>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="mc-card h-100">
            <div class="mc-card-head"><h2><i class="bi bi-clock-history me-2 text-primary"></i>Activité récente</h2>
                <?php if (can('audit.view')): ?><a class="small" href="<?= e(url('/admin/audit')) ?>">Tout voir</a><?php endif; ?></div>
            <?php if (!$recentActivity): ?>
                <div class="mc-empty"><i class="bi bi-inbox"></i>Aucune activité à afficher.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($recentActivity as $a): ?>
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="small fw-semibold"><?= e($a['description'] ?: $a['action']) ?></span>
                                <small class="text-muted text-nowrap"><?= e(date_fr($a['created_at'], 'd/m H:i')) ?></small>
                            </div>
                            <small class="text-muted"><?= e($a['full_name'] ?? 'Système') ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
