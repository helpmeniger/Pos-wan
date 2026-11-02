<?php
use App\Services\TicketService;
$title = 'Générer des tickets';
$o = static fn (string $k, $d = '') => old($k, $d);
?>
<div class="mc-page-head">
    <div><h1>Générer des <span class="mc-accent">tickets</span></h1><p>Les comptes Hotspot sont réellement créés sur le routeur sélectionné.</p></div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/tickets')) ?>"><i class="bi bi-arrow-left"></i> Retour</a>
</div>
<?php if (!$offers || !$routers): ?>
    <div class="alert alert-warning">Il faut au moins <?= !$routers ? '<a href="' . e(url('/routers/create')) . '">un routeur actif</a>' : '' ?><?= !$routers && !$offers ? ' et ' : '' ?><?= !$offers ? '<a href="' . e(url('/offers/create')) . '">une offre active</a>' : '' ?> pour générer des tickets.</div>
<?php endif; ?>
<?php if (errors()): ?><div class="alert alert-danger"><?= e(implode(" ", errors())) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('/tickets/generate')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="mc-card mb-3"><div class="mc-card-head"><h2>Offre et destination</h2></div>
                <div class="mc-card-body row g-3">
                    <div class="col-md-6"><label class="form-label" for="offer_id">Offre *</label>
                        <select class="form-select<?= invalid('offer_id') ?>" id="offer_id" name="offer_id" required data-offer-select>
                            <?php foreach ($offers as $of): ?>
                                <option value="<?= (int) $of['id'] ?>" data-activation="<?= e($of['activation_mode']) ?>" data-validity="<?= e($of['validity_mode']) ?>" data-shared="<?= (int) $of['shared_users'] ?>"
                                    <?= (int) $o('offer_id', $sel['offer_id']) === (int) $of['id'] ? 'selected' : '' ?>><?= e($of['name']) ?> — <?= e(money($of['price'])) ?> (<?= e(TicketService::durationLabel($of)) ?>)</option>
                            <?php endforeach; ?>
                        </select><?= error_for('offer_id') ?></div>
                    <div class="col-md-6"><label class="form-label" for="router_id">Routeur de destination *</label>
                        <select class="form-select<?= invalid('router_id') ?>" id="router_id" name="router_id" required>
                            <?php foreach ($routers as $r): ?><option value="<?= (int) $r['id'] ?>" <?= (int) $o('router_id', $sel['router_id']) === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?><?= $r['connection_state'] !== 'online' ? ' (' . ($r['connection_state'] === 'offline' ? 'hors ligne' : 'état inconnu') . ')' : '' ?></option><?php endforeach; ?>
                        </select><?= error_for('router_id') ?></div>
                    <div class="col-12"><label class="form-label">Quantité *</label>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ([1, 10, 50, 100, 500, 1000] as $q): ?>
                                <input type="radio" class="btn-check" name="quantity" id="q<?= $q ?>" value="<?= $q ?>" <?= (string) $o('quantity', '10') === (string) $q ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary" for="q<?= $q ?>"><?= $q ?></label>
                            <?php endforeach; ?>
                            <input type="radio" class="btn-check" name="quantity" id="qc" value="custom" <?= $o('quantity') === 'custom' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary" for="qc">Autre</label>
                            <input class="form-control" style="max-width:130px" name="custom_quantity" value="<?= e($o('custom_quantity')) ?>" placeholder="ex. 250" inputmode="numeric" aria-label="Quantité personnalisée">
                        </div><?= error_for('quantity') ?></div>
                    <?php if ($resellers): ?>
                    <div class="col-md-6"><label class="form-label" for="reseller_id">Affecter au stock d'un revendeur (dépôt-vente)</label>
                        <select class="form-select" id="reseller_id" name="reseller_id"><option value="0">— Stock de l'opérateur —</option><?php foreach ($resellers as $rs): ?><option value="<?= (int) $rs['id'] ?>"><?= e($rs['business_name']) ?></option><?php endforeach; ?></select></div>
                    <?php endif; ?>
                </div></div>
            <div class="mc-card"><div class="mc-card-head"><h2>Identifiants</h2></div>
                <div class="mc-card-body row g-3">
                    <div class="col-md-3"><label class="form-label" for="prefix">Préfixe</label><input class="form-control<?= invalid('prefix') ?>" id="prefix" name="prefix" value="<?= e($o('prefix', setting('tickets.default_prefix', 'MC'))) ?>" maxlength="10"><?= error_for('prefix') ?></div>
                    <div class="col-md-3"><label class="form-label" for="username_length">Longueur identifiant</label><input class="form-control" type="number" min="4" max="16" id="username_length" name="username_length" value="<?= e($o('username_length', setting('tickets.username_length', 8))) ?>"></div>
                    <div class="col-md-3"><label class="form-label" for="password_length">Longueur mot de passe</label><input class="form-control" type="number" min="4" max="16" id="password_length" name="password_length" value="<?= e($o('password_length', setting('tickets.password_length', 6))) ?>"></div>
                    <div class="col-md-3"><label class="form-label" for="charset">Caractères</label>
                        <select class="form-select" id="charset" name="charset"><?php foreach (['alphanumeric_upper' => 'Majuscules + chiffres', 'alphanumeric' => 'Minuscules + chiffres', 'numeric' => 'Chiffres', 'alpha' => 'Lettres'] as $k => $l): ?><option value="<?= $k ?>" <?= $o('charset', setting('tickets.charset', 'alphanumeric_upper')) === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
                    <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="same_credentials" name="same_credentials" value="1" <?= $o('same_credentials') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="same_credentials">Identifiant et mot de passe identiques (un seul code à saisir)</label></div>
                        <div class="form-text">Les caractères ambigus (0/O, 1/l/I) sont exclus. Chaque identifiant est unique sur tous les routeurs.</div></div>
                </div></div>
        </div>
        <div class="col-lg-4">
            <div class="mc-card mb-3"><div class="mc-card-head"><h2>Activation et validité</h2></div>
                <div class="mc-card-body">
                    <label class="form-label" for="activation_mode">Activation</label>
                    <select class="form-select mb-3" id="activation_mode" name="activation_mode"><?php foreach (TicketService::ACTIVATION as $k => $l): ?><option value="<?= $k ?>" <?= $o('activation_mode') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                    <label class="form-label" for="validity_mode">Validité</label>
                    <select class="form-select mb-3" id="validity_mode" name="validity_mode"><?php foreach (TicketService::VALIDITY as $k => $l): ?><option value="<?= $k ?>" <?= $o('validity_mode') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                    <label class="form-label" for="shared_users">Appareils simultanés</label>
                    <input class="form-control mb-3" type="number" min="1" max="50" id="shared_users" name="shared_users" value="<?= e($o('shared_users', 1)) ?>">
                    <label class="form-label" for="expires_at">Date d'expiration fixe (facultatif)</label>
                    <input class="form-control" type="datetime-local" id="expires_at" name="expires_at" value="<?= e($o('expires_at')) ?>">
                    <div class="form-text">Obligatoire en mode « date fixe » si l'offre n'a pas de durée.</div>
                </div></div>
            <button class="btn btn-primary w-100 py-2" <?= !$offers || !$routers ? 'disabled' : '' ?>><i class="bi bi-magic me-1"></i> Générer et créer sur le routeur</button>
        </div>
    </div>
</form>
