<?php use App\Services\TicketService; $title = 'Acheter un forfait Wi-Fi'; ?>
<div class="text-center mb-4"><h1 class="fw-bold">Acheter un forfait <span class="text-primary">Wi-Fi</span></h1>
    <p class="text-muted">Choisissez votre forfait, payez par mobile money et recevez immédiatement vos identifiants.</p></div>
<?php if (!$offers || !$methods): ?>
    <div class="mc-card"><div class="mc-empty"><i class="bi bi-cart-x"></i>La vente en ligne n'est pas disponible pour le moment. Rapprochez-vous d'un point de vente.</div></div>
<?php else: ?>
<form method="post" action="<?= e(url('/buy')) ?>" class="mc-card"><?= csrf_field() ?>
    <div class="mc-card-body">
        <h2 class="h6 fw-bold mb-3">1. Votre forfait</h2>
        <div class="row g-2 mb-4">
            <?php foreach ($offers as $i => $o): ?>
                <div class="col-6 col-md-4"><input type="radio" class="btn-check" name="offer_id" id="o<?= (int) $o['id'] ?>" value="<?= (int) $o['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
                    <label class="btn btn-outline-primary w-100 text-start p-3" for="o<?= (int) $o['id'] ?>"><div class="fw-semibold"><?= e($o['name']) ?></div><div class="fs-5 fw-bold"><?= e(money($o['price'])) ?></div><small><?= e(TicketService::durationLabel($o)) ?></small></label></div>
            <?php endforeach; ?>
        </div>
        <h2 class="h6 fw-bold mb-3">2. Point d'accès et paiement</h2>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="router_id">Lieu de connexion</label>
                <select class="form-select" id="router_id" name="router_id"><?php foreach ($routers as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?><?= $r['location'] ? ' — ' . e($r['location']) : '' ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="payment_method_id">Moyen de paiement</label>
                <select class="form-select" id="payment_method_id" name="payment_method_id"><?php foreach ($methods as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="phone">Votre numéro de téléphone</label><input class="form-control" id="phone" name="phone" inputmode="tel" placeholder="+227 …" required></div>
        </div>
    </div>
    <div class="mc-card-head border-top border-bottom-0 justify-content-end"><button class="btn btn-primary btn-lg"><i class="bi bi-arrow-right-circle me-1"></i> Continuer</button></div>
</form>
<?php endif; ?>
