<?php
use App\Services\TicketService;
$title = 'Caisse';
$blocked = $requireSession && !$session;
?>
<div class="mc-page-head">
    <div><h1><span class="mc-accent">Caisse</span></h1><p>Vente rapide de tickets Wi-Fi.</p></div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <form method="get" action="<?= e(url('/pos')) ?>">
            <select class="form-select" name="router_id" data-autosubmit aria-label="Routeur">
                <?php foreach ($routers as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $routerId === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
            </select>
        </form>
        <a class="btn btn-outline-secondary text-nowrap" href="<?= e(url('/pos/sessions')) ?>"><i class="bi bi-clock-history"></i> Sessions</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <?php if ($blocked): ?>
            <div class="mc-card mb-3"><div class="mc-card-body">
                <?php if (can('cash.manage')): ?>
                <form class="row g-2 align-items-end" method="post" action="<?= e(url('/pos/open')) ?>"><?= csrf_field() ?>
                    <div class="col-md-6"><h2 class="h5 fw-bold mb-1"><i class="bi bi-lock text-primary"></i> Caisse fermée</h2><p class="text-muted small mb-0">Ouvrez la caisse en indiquant le fond de caisse pour commencer à vendre.</p></div>
                    <div class="col-md-3"><label class="form-label small" for="opening_amount">Fond de caisse (FCFA)</label><input class="form-control" id="opening_amount" name="opening_amount" value="0" inputmode="numeric"></div>
                    <div class="col-md-3"><button class="btn btn-primary w-100">Ouvrir la caisse</button></div>
                </form>
                <?php else: ?><p class="mb-0">Caisse fermée : vous n'avez pas l'autorisation de l'ouvrir.</p><?php endif; ?>
            </div></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/pos/sell')) ?>" id="pos-form" class="<?= $blocked ? 'opacity-50 pe-none' : '' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="router_id" value="<?= (int) $routerId ?>">
            <input type="hidden" name="idempotency_key" value="<?= e($idempotency) ?>">
            <input type="hidden" name="offer_id" id="pos-offer" value="">
            <div class="row g-2 mb-3">
                <?php foreach ($offers as $o): ?>
                    <div class="col-6 col-md-4 col-xxl-3">
                        <button type="button" class="mc-pos-offer <?= (int) $o['stock'] === 0 ? 'empty' : '' ?>" data-pos-offer="<?= (int) $o['id'] ?>" data-price="<?= (float) $o['price'] ?>" data-stock="<?= (int) $o['stock'] ?>" data-name="<?= e($o['name']) ?>">
                            <div class="fw-semibold"><?= e($o['name']) ?></div>
                            <div class="price"><?= e(money($o['price'])) ?></div>
                            <div class="small text-muted"><?= e(TicketService::durationLabel($o)) ?> · stock <?= (int) $o['stock'] ?></div>
                        </button>
                    </div>
                <?php endforeach; ?>
                <?php if (!$offers): ?><div class="col-12"><div class="mc-card"><div class="mc-empty"><i class="bi bi-tags"></i>Aucune offre active.</div></div></div><?php endif; ?>
            </div>

            <div class="mc-card">
                <div class="mc-card-body row g-3">
                    <div class="col-md-3"><label class="form-label" for="quantity">Quantité</label>
                        <div class="input-group"><button class="btn btn-outline-secondary" type="button" data-qty="-1">−</button>
                            <input class="form-control text-center" id="quantity" name="quantity" value="1" inputmode="numeric">
                            <button class="btn btn-outline-secondary" type="button" data-qty="1">+</button></div></div>
                    <div class="col-md-5"><label class="form-label" for="payment_method_id">Paiement</label>
                        <select class="form-select" id="payment_method_id" name="payment_method_id">
                            <?php foreach ($methods as $m): ?><option value="<?= (int) $m['id'] ?>" data-type="<?= e($m['type']) ?>"><?= e($m['name']) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="col-md-4" id="pos-ref-wrap"><label class="form-label" for="payment_reference">Référence transaction</label>
                        <input class="form-control" id="payment_reference" name="payment_reference" placeholder="ID reçu par SMS"></div>
                    <div class="col-md-4"><label class="form-label" for="customer_phone">Téléphone client</label><input class="form-control" id="customer_phone" name="customer_phone" placeholder="facultatif" inputmode="tel"></div>
                    <div class="col-md-4"><label class="form-label" for="customer_name">Nom client</label><input class="form-control" id="customer_name" name="customer_name" placeholder="facultatif"></div>
                    <?php if (can('sales.discount')): ?>
                        <div class="col-md-4"><label class="form-label" for="discount">Remise (FCFA)</label><input class="form-control" id="discount" name="discount" value="0" inputmode="numeric">
                            <div class="form-text">Maximum <?= (int) setting('sales.max_discount_percent', 10) ?> %</div></div>
                    <?php endif; ?>
                </div>
                <div class="mc-card-head border-top border-bottom-0">
                    <div><div class="text-muted small" id="pos-summary">Choisissez une offre</div><div class="fs-3 fw-bold text-primary" id="pos-total">0 FCFA</div></div>
                    <button class="btn btn-primary btn-lg px-4" id="pos-submit" disabled><i class="bi bi-cart-check me-1"></i> Encaisser</button>
                </div>
            </div>
        </form>
    </div>

    <div class="col-xl-4">
        <?php if ($session): ?>
        <div class="mc-card mb-3"><div class="mc-card-head"><h2><i class="bi bi-cash-stack me-2 text-primary"></i>Caisse ouverte</h2><?= status_badge('open') ?></div>
            <div class="mc-card-body small">
                <div class="d-flex justify-content-between"><span class="text-muted">Ouverte le</span><span><?= e(date_fr($session['opened_at'])) ?></span></div>
                <div class="d-flex justify-content-between"><span class="text-muted">Fond de caisse</span><span><?= e(money($session['opening_amount'])) ?></span></div>
                <div class="d-flex justify-content-between"><span class="text-muted">Ventes</span><span><?= (int) $sessionTotals['n'] ?> · <?= e(money($sessionTotals['total'])) ?></span></div>
                <div class="d-flex justify-content-between fw-bold"><span>Espèces attendues</span><span><?= e(money($expected)) ?></span></div>
                <?php if (can('cash.manage')): ?>
                <form class="mt-3" method="post" action="<?= e(url('/pos/close')) ?>" data-confirm="Clôturer la caisse ?"><?= csrf_field() ?>
                    <label class="form-label" for="declared_amount">Espèces comptées</label>
                    <input class="form-control mb-2" id="declared_amount" name="declared_amount" inputmode="numeric" required>
                    <input class="form-control mb-2" name="notes" placeholder="Observations (facultatif)">
                    <button class="btn btn-navy w-100">Clôturer et rapprocher</button>
                </form>
                <?php endif; ?>
            </div></div>
        <?php endif; ?>
        <div class="mc-card"><div class="mc-card-head"><h2>Mes dernières ventes</h2><a class="small" href="<?= e(url('/sales')) ?>">Tout voir</a></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($recent as $s): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div><a href="<?= e(url('/sales/' . $s['id'])) ?>" class="fw-semibold small"><?= e($s['reference']) ?></a><br><small class="text-muted"><?= e(date_fr($s['created_at'], 'H:i')) ?> · <?= e($s['method']) ?></small></div>
                        <div class="text-end"><div class="fw-bold small"><?= e(money($s['total'])) ?></div><?= $s['status'] !== 'completed' ? status_badge($s['status']) : '' ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$recent): ?><li class="list-group-item text-muted small">Aucune vente.</li><?php endif; ?>
            </ul></div>
    </div>
</div>
