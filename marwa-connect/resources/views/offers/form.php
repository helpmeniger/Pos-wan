<?php
use App\Services\TicketService;
$editing = $offer !== null;
$title = $editing ? 'Modifier ' . $offer['name'] : 'Nouvelle offre';
$v = static fn (string $k, $d = '') => old($k, $editing ? ($offer[$k] ?? $d) : $d);
$tl = $editing && $offer['time_limit_seconds'] ? ($offer['time_limit_seconds'] % 86400 === 0 ? [$offer['time_limit_seconds'] / 86400, 'days'] : ($offer['time_limit_seconds'] % 3600 === 0 ? [$offer['time_limit_seconds'] / 3600, 'hours'] : [$offer['time_limit_seconds'] / 60, 'minutes'])) : ['', 'hours'];
$dl = $editing && $offer['data_limit_bytes'] ? ($offer['data_limit_bytes'] % 1073741824 === 0 ? [$offer['data_limit_bytes'] / 1073741824, 'GB'] : [round($offer['data_limit_bytes'] / 1048576), 'MB']) : ['', 'MB'];
?>
<div class="mc-page-head">
    <div><h1><?= $editing ? 'Modifier l\'' : 'Nouvelle ' ?><span class="mc-accent">offre</span></h1><p>Exemples : 100 FCFA = 1 heure, 250 FCFA = 24 heures, 500 FCFA = 3 jours…</p></div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/offers')) ?>"><i class="bi bi-arrow-left"></i> Retour</a>
</div>
<form method="post" action="<?= e(url($editing ? '/offers/' . $offer['id'] : '/offers')) ?>" novalidate>
    <?= csrf_field() ?><?= $editing ? method_field('PUT') : '' ?>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="mc-card mb-3"><div class="mc-card-head"><h2>Description et prix</h2></div>
                <div class="mc-card-body row g-3">
                    <div class="col-md-6"><label class="form-label" for="name">Nom de l'offre *</label>
                        <input class="form-control<?= invalid('name') ?>" id="name" name="name" value="<?= e($v('name')) ?>" required><?= error_for('name') ?></div>
                    <div class="col-md-3"><label class="form-label" for="code">Code *</label>
                        <input class="form-control<?= invalid('code') ?>" id="code" name="code" value="<?= e($v('code')) ?>" placeholder="H24" required><?= error_for('code') ?></div>
                    <div class="col-md-3"><label class="form-label" for="price">Prix (FCFA) *</label>
                        <input class="form-control<?= invalid('price') ?>" id="price" name="price" value="<?= e($v('price') !== '' ? (float) $v('price') : '') ?>" inputmode="numeric" required><?= error_for('price') ?></div>
                    <div class="col-12"><label class="form-label" for="description">Description</label>
                        <input class="form-control" id="description" name="description" value="<?= e($v('description')) ?>"></div>
                </div></div>
            <div class="mc-card mb-3"><div class="mc-card-head"><h2>Validité et limites</h2></div>
                <div class="mc-card-body row g-3">
                    <div class="col-md-6"><label class="form-label">Durée de validité</label>
                        <div class="input-group"><input class="form-control" name="validity_value" value="<?= e($v('validity_value')) ?>" inputmode="numeric">
                            <select class="form-select" name="validity_unit"><?php foreach (['minutes' => 'minutes', 'hours' => 'heures', 'days' => 'jours', 'weeks' => 'semaines', 'months' => 'mois'] as $k => $l): ?><option value="<?= $k ?>" <?= $v('validity_unit', 'hours') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div></div>
                    <div class="col-md-6"><label class="form-label" for="validity_mode">Mode de validité</label>
                        <select class="form-select" id="validity_mode" name="validity_mode"><?php foreach (TicketService::VALIDITY as $k => $l): ?><option value="<?= $k ?>" <?= $v('validity_mode', 'elapsed_time') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label">Temps de connexion autorisé (cumulé)</label>
                        <div class="input-group"><input class="form-control<?= invalid('time_limit_value') ?>" name="time_limit_value" value="<?= e(old('time_limit_value', $tl[0])) ?>" placeholder="illimité" inputmode="decimal">
                            <select class="form-select" name="time_limit_unit"><?php foreach (['minutes' => 'minutes', 'hours' => 'heures', 'days' => 'jours'] as $k => $l): ?><option value="<?= $k ?>" <?= old('time_limit_unit', $tl[1]) === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div><?= error_for('time_limit_value') ?></div>
                    <div class="col-md-6"><label class="form-label">Volume de données</label>
                        <div class="input-group"><input class="form-control<?= invalid('data_limit_value') ?>" name="data_limit_value" value="<?= e(old('data_limit_value', $dl[0])) ?>" placeholder="illimité" inputmode="decimal">
                            <select class="form-select" name="data_limit_unit"><?php foreach (['MB' => 'Mo', 'GB' => 'Go'] as $k => $l): ?><option value="<?= $k ?>" <?= old('data_limit_unit', $dl[1]) === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div><?= error_for('data_limit_value') ?></div>
                    <div class="col-md-6"><label class="form-label" for="activation_mode">Activation</label>
                        <select class="form-select" id="activation_mode" name="activation_mode"><?php foreach (TicketService::ACTIVATION as $k => $l): ?><option value="<?= $k ?>" <?= $v('activation_mode', 'first_login') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="form-label" for="start_date">Début de vente</label><input class="form-control" type="date" id="start_date" name="start_date" value="<?= e($v('start_date')) ?>"></div>
                    <div class="col-md-3"><label class="form-label" for="end_date">Fin de vente</label><input class="form-control" type="date" id="end_date" name="end_date" value="<?= e($v('end_date')) ?>"></div>
                </div></div>
            <div class="mc-card"><div class="mc-card-head"><h2>Profil MikroTik</h2></div>
                <div class="mc-card-body row g-3">
                    <div class="col-md-3"><label class="form-label" for="rate_down">Débit descendant</label><input class="form-control<?= invalid('rate_down') ?>" id="rate_down" name="rate_down" value="<?= e($v('rate_down')) ?>" placeholder="ex. 5M"><?= error_for('rate_down') ?></div>
                    <div class="col-md-3"><label class="form-label" for="rate_up">Débit montant</label><input class="form-control<?= invalid('rate_up') ?>" id="rate_up" name="rate_up" value="<?= e($v('rate_up')) ?>" placeholder="ex. 2M"><?= error_for('rate_up') ?></div>
                    <div class="col-md-3"><label class="form-label" for="shared_users">Connexions simultanées</label><input class="form-control" id="shared_users" name="shared_users" value="<?= e($v('shared_users', 1)) ?>" inputmode="numeric"></div>
                    <div class="col-md-3"><label class="form-label" for="mikrotik_profile">Nom du profil</label><input class="form-control<?= invalid('mikrotik_profile') ?>" id="mikrotik_profile" name="mikrotik_profile" value="<?= e($v('mikrotik_profile')) ?>" placeholder="MC-CODE"></div>
                    <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="mac_binding" name="mac_binding" value="1" <?= $v('mac_binding') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="mac_binding">Lier le ticket à l'adresse MAC du premier appareil connecté</label></div></div>
                </div></div>
        </div>
        <div class="col-lg-4">
            <div class="mc-card mb-3"><div class="mc-card-head"><h2>Routeurs</h2></div>
                <div class="mc-card-body">
                    <?php foreach ($routers as $r): ?>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="routers[]" value="<?= (int) $r['id'] ?>" id="r<?= (int) $r['id'] ?>" <?= in_array((int) $r['id'], $linked, true) || (!$editing && count($routers) === 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="r<?= (int) $r['id'] ?>"><?= e($r['name']) ?> <?= status_badge($r['connection_state']) ?></label></div>
                    <?php endforeach; ?>
                    <?php if (!$routers): ?><p class="text-muted small mb-0">Aucun routeur actif. <a href="<?= e(url('/routers/create')) ?>">Ajouter un routeur</a></p><?php endif; ?>
                </div></div>
            <div class="mc-card mb-3"><div class="mc-card-head"><h2>Publication</h2></div>
                <div class="mc-card-body">
                    <label class="form-label" for="status">Statut</label>
                    <select class="form-select mb-3" id="status" name="status"><?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archivée'] as $k => $l): ?><option value="<?= $k ?>" <?= $v('status', 'active') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
                    <label class="form-label" for="sort_order">Ordre d'affichage</label>
                    <input class="form-control mb-3" id="sort_order" name="sort_order" value="<?= e($v('sort_order', 0)) ?>" inputmode="numeric">
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="is_public" name="is_public" value="1" <?= $v('is_public') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_public">Proposée à l'achat en ligne</label></div>
                </div></div>
            <button class="btn btn-primary w-100 py-2"><i class="bi bi-check2 me-1"></i> Enregistrer</button>
        </div>
    </div>
</form>
