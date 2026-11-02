<?php use App\Services\TicketService; $title = 'Commande ' . $order['reference']; ?>
<div class="mc-card" data-order-poll="<?= e(url('/buy/order/' . $order['reference'], ['t' => $token, 'format' => 'json'])) ?>" data-order-status="<?= e($order['status']) ?>">
    <div class="mc-card-head"><h2>Commande <?= e($order['reference']) ?></h2><?= status_badge($order['status']) ?></div>
    <div class="mc-card-body">
        <p class="mb-1"><strong><?= e($order['offer_name']) ?></strong> — <?= e(money($order['amount'])) ?> · <?= e($order['router_name']) ?></p>
        <?php if ($order['status'] === 'delivered'): ?>
            <div class="alert alert-success mt-3"><i class="bi bi-check-circle me-1"></i> Paiement confirmé. Voici vos identifiants Wi-Fi :</div>
            <div class="mc-ticket-preview mx-auto"><div class="head"><?= e(mb_strtoupper(setting('company.name', 'MARWA CONNECT'))) ?></div>
                <div class="p-3"><div class="d-flex justify-content-between"><span class="text-muted">Identifiant</span><code class="fs-5 fw-bold"><?= e($order['ticket_username']) ?></code></div>
                    <div class="d-flex justify-content-between"><span class="text-muted">Mot de passe</span><code class="fs-5 fw-bold"><?= e($order['ticket_password']) ?></code></div>
                    <div class="d-flex justify-content-between"><span class="text-muted">Durée</span><span><?= e(TicketService::durationLabel($order)) ?></span></div>
                    <?php if ($order['wifi_ssid']): ?><div class="d-flex justify-content-between"><span class="text-muted">Wi-Fi</span><span><?= e($order['wifi_ssid']) ?></span></div><?php endif; ?></div></div>
            <p class="small text-muted text-center mt-3"><?= e(setting('tickets.login_instructions', '')) ?> Conservez cette page : elle reste accessible avec son lien.</p>
        <?php elseif ($order['status'] === 'awaiting_payment'): ?>
            <div class="alert alert-info mt-3">
                <p class="mb-2"><strong>Payez <?= e(money($order['amount'])) ?> par <?= e($order['method_name']) ?></strong><?= $order['merchant_number'] ? ' au numéro <strong>' . e($order['merchant_number']) . '</strong>' : '' ?>.</p>
                <p class="mb-2">Indiquez la référence <strong><?= e($order['reference']) ?></strong> dans le motif du paiement.</p>
                <?php if ($order['instructions']): ?><p class="mb-0 small"><?= e($order['instructions']) ?></p><?php endif; ?>
            </div>
            <p class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span> En attente de la confirmation du paiement par le prestataire… Cette page se met à jour automatiquement. Commande valable jusqu'à <?= e(date_fr($order['expires_at'], 'H:i')) ?>.</p>
        <?php else: ?>
            <div class="alert alert-warning mt-3">Cette commande n'est plus active (<?= e($order['status']) ?>). <a href="<?= e(url('/buy')) ?>">Passer une nouvelle commande</a>.</div>
        <?php endif; ?>
    </div>
</div>
