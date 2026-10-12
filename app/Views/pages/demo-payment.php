<div class="auth-wrap"><div class="card auth-card" id="demo-pay" data-uuid="<?= e($payment['uuid']) ?>" data-order="<?= (int) $payment['order_id'] ?>">
  <div class="alert alert-warn"><strong>Passerelle de démonstration.</strong> Aucun argent réel n'est débité. En production, cette page est remplacée par celle du prestataire de paiement (Mobile Money, carte) retenu par MARWA.</div>
  <h1>Paiement de <?= e(fcfa((int) $payment['amount'])) ?></h1>
  <dl class="kv"><dt>Commande</dt><dd><?= e($payment['reference']) ?></dd><dt>Référence de paiement</dt><dd><?= e($payment['external_ref']) ?></dd><dt>Statut</dt><dd><?= e($payment['status']) ?></dd></dl>
  <?php if ($payment['status'] === 'pending'): ?>
  <div class="stack" style="margin-top:18px">
    <button class="btn btn-primary btn-block btn-lg" data-outcome="success">Simuler un paiement accepté</button>
    <button class="btn btn-outline btn-block" data-outcome="failure">Simuler un paiement refusé</button>
  </div>
  <?php else: ?>
  <p style="margin-top:14px"><a class="btn btn-primary" href="<?= e(url('client#/commande/' . (int) $payment['order_id'])) ?>">Retour à la commande</a></p>
  <?php endif; ?>
  <p class="small muted" style="margin-top:14px">La confirmation est traitée côté serveur via une notification signée (HMAC), exactement comme avec un vrai prestataire.</p>
</div></div>
