<?php $title = 'Commandes en ligne'; ?>
<div class="mc-page-head"><div><h1>Commandes <span class="mc-accent">en ligne</span></h1>
    <p>Achats depuis la page publique <a href="<?= e(url('/buy')) ?>" target="_blank"><?= e(url('/buy')) ?></a>. Un paiement n'est confirmé que par webhook signé ou après vérification de la référence.</p></div></div>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link" href="<?= e(url('/sales')) ?>">Ventes</a></li>
    <li class="nav-item"><a class="nav-link active" href="<?= e(url('/sales', ['tab' => 'online'])) ?>">Commandes en ligne</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= e(url('/pos/sessions')) ?>">Sessions de caisse</a></li>
</ul>
<div class="mc-card"><div class="table-responsive"><table class="table mc-table table-hover">
    <thead><tr><th>Référence</th><th>Date</th><th>Client</th><th>Offre</th><th>Moyen</th><th class="text-end">Montant</th><th>Statut</th><th>Ticket</th><th></th></tr></thead><tbody>
    <?php foreach ($orders as $o): ?>
        <tr><td class="fw-semibold"><?= e($o['reference']) ?></td><td class="small text-nowrap"><?= e(date_fr($o['created_at'])) ?></td><td><?= e($o['customer_phone']) ?></td>
            <td class="small"><?= e($o['offer_name']) ?><br><span class="text-muted"><?= e($o['router_name']) ?></span></td><td class="small"><?= e($o['method']) ?></td>
            <td class="text-end"><?= e(money($o['amount'])) ?></td><td><?= status_badge($o['status']) ?></td><td><code><?= e($o['username'] ?? '') ?></code></td>
            <td class="text-end"><?php if (in_array($o['status'], ['created', 'awaiting_payment'], true) && can('payments.manage')): ?>
                <form class="d-flex gap-1" method="post" action="<?= e(url('/sales/online/' . $o['id'] . '/confirm')) ?>" data-confirm="Avez-vous vérifié ce paiement chez le prestataire ?"><?= csrf_field() ?>
                    <input class="form-control form-control-sm" name="provider_reference" placeholder="Réf. transaction" required style="max-width:150px">
                    <button class="btn btn-sm btn-success text-nowrap">Confirmer</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$orders): ?><tr><td colspan="9"><div class="mc-empty"><i class="bi bi-globe2"></i>Aucune commande en ligne.</div></td></tr><?php endif; ?>
    </tbody></table></div></div>
