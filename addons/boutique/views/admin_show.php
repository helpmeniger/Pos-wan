<?php
/** Commande en ligne : vérification du stock, validation, encaissement (vente), livraison. */
[$label, $color, $icon] = $statuses[$o['status']];
$fmtQ = fn ($q) => rtrim(rtrim(number_format((float) $q, 3, ',', ' '), '0'), ',');
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a href="<?= e(url('/boutique-admin')) ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> Commandes</a>
  <span class="badge fs-6 text-bg-<?= e($color) ?>"><i class="bi <?= e($icon) ?>"></i> <?= e($label) ?></span>
  <span class="text-secondary small">Passée le <?= e(fdate($o['created_at'])) ?></span>
  <?php if ($o['sale_id']): ?><a class="btn btn-sm btn-outline-primary ms-auto" href="<?= e(url('/sales/' . $o['sale_id'])) ?>"><i class="bi bi-receipt"></i> Vente <?= e($o['sale_number']) ?></a><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/sales/' . $o['sale_id'] . '/ticket')) ?>" target="_blank" data-no-progress><i class="bi bi-printer"></i> Ticket</a><?php endif; ?>
</div>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-3"><div class="card-header d-flex align-items-center"><i class="bi bi-bag me-1"></i> Articles commandés
      <?php if (in_array($o['status'], ['new', 'confirmed'], true)): ?><span class="ms-auto small <?= $check['ok'] ? 'text-success' : 'text-danger fw-bold' ?>"><i class="bi <?= $check['ok'] ? 'bi-check-circle' : 'bi-exclamation-triangle' ?>"></i> Stock vérifié dans « <?= e($check['warehouse']) ?> »<?= $check['ok'] ? ' : tout est disponible' : ' : stock insuffisant' ?></span><?php endif; ?></div>
      <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Article</th><th class="text-end">Qté</th><th class="text-end">Prix</th><th class="text-end">Total</th><?php if (in_array($o['status'], ['new', 'confirmed'], true)): ?><th class="text-end">En stock</th><?php endif; ?></tr></thead><tbody>
      <?php foreach ($o['items'] as $i): $c = $check['lines'][(int) $i['id']] ?? null; ?>
        <tr class="<?= $c && !$c['ok'] && in_array($o['status'], ['new', 'confirmed'], true) ? 'table-danger' : '' ?>"><td><?= e($i['product_name']) ?></td><td class="text-end fw-bold"><?= e($fmtQ($i['quantity'])) ?></td><td class="text-end text-nowrap"><?= e(money($i['unit_price'])) ?></td><td class="text-end text-nowrap"><?= e(money($i['line_total'])) ?></td>
        <?php if (in_array($o['status'], ['new', 'confirmed'], true)): ?><td class="text-end"><?= $c === null ? '' : ($c['stock'] === null ? '<span class="text-secondary small">non suivi</span>' : '<span class="' . ($c['ok'] ? 'text-success' : 'text-danger fw-bold') . '">' . e($fmtQ($c['stock'])) . '</span>') ?></td><?php endif; ?></tr>
      <?php endforeach; ?>
      </tbody><tfoot>
        <tr><td colspan="3">Articles</td><td class="text-end"><?= e(money($o['subtotal'])) ?></td><?php if (in_array($o['status'], ['new', 'confirmed'], true)): ?><td></td><?php endif; ?></tr>
        <?php if ((float) $o['delivery_fee'] > 0): ?><tr><td colspan="3">Livraison</td><td class="text-end"><?= e(money($o['delivery_fee'])) ?></td><?php if (in_array($o['status'], ['new', 'confirmed'], true)): ?><td></td><?php endif; ?></tr><?php endif; ?>
        <tr class="fs-5 fw-bold"><td colspan="3">Total à encaisser</td><td class="text-end text-nowrap"><?= e(money($o['total'])) ?></td><?php if (in_array($o['status'], ['new', 'confirmed'], true)): ?><td></td><?php endif; ?></tr>
      </tfoot></table></div></div>

    <?php if (in_array($o['status'], ['new', 'confirmed'], true)): ?>
    <div class="card border-primary mb-3"><div class="card-header bg-primary text-white"><i class="bi bi-cash-coin"></i> Encaisser la commande</div><div class="card-body">
      <?php if (!$hasRegister): ?><div class="alert alert-warning mb-0">Aucune caisse n'est associée à votre compte : un administrateur doit vous en affecter une (Utilisateurs).</div>
      <?php elseif (!$session && setting('require_cash_session', '1') === '1'): ?><div class="alert alert-warning mb-0">Votre caisse est fermée. <a href="<?= e(url('/cash')) ?>">Ouvrez la caisse</a> pour encaisser cette commande.</div>
      <?php else: ?>
      <form method="post" action="<?= e(url('/boutique-admin/' . $o['id'] . '/encaisser')) ?>" class="row g-2 align-items-end" data-confirm="Encaisser <?= e(money($o['total'])) ?> ? Une vente sera créée et le stock diminuera."><?= csrf_field() ?>
        <div class="col-md-4"><label class="form-label small mb-0">Mode de paiement</label><select name="method" class="form-select"><?php foreach ($methods as $k => $l): ?><?php if ($k === 'mobile_money' && $providers): foreach ($providers as $prov): ?><option value="mobile_money|<?= e($prov) ?>"><?= e($prov) ?></option><?php endforeach; else: ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endif; ?><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small mb-0">Montant reçu (espèces)</label><input type="number" name="tendered" class="form-control" min="0" step="1" value="<?= e((string) (float) $o['total']) ?>"></div>
        <div class="col-md-3"><label class="form-label small mb-0">Référence (mobile, virement)</label><input name="reference" class="form-control" maxlength="100"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100" <?= $check['ok'] || setting('allow_negative_stock') === '1' ? '' : 'disabled' ?>><i class="bi bi-check2"></i> Encaisser</button></div>
        <?php if (!$check['ok']): ?><div class="col-12 small text-danger">Stock insuffisant dans votre entrepôt : réapprovisionnez, faites un transfert de stock, ou appelez le client pour modifier / annuler la commande.</div><?php endif; ?>
        <div class="col-12 small text-secondary">L'encaissement crée une vente dans votre caisse (ticket, rapports, clôture) et sort les articles du stock de « <?= e($check['warehouse']) ?> ».<?= (float) $o['delivery_fee'] > 0 ? ' Les frais de livraison sont enregistrés comme recette de caisse.' : '' ?></div>
      </form>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-person"></i> Client</div><div class="card-body">
      <div class="fw-bold fs-5"><?= e($o['customer_name']) ?></div>
      <div class="d-flex gap-2 my-2"><a class="btn btn-sm btn-outline-primary" href="tel:<?= e(preg_replace('/[^\d+]/', '', $o['phone'])) ?>" data-no-progress><i class="bi bi-telephone"></i> <?= e($o['phone']) ?></a><a class="btn btn-sm btn-success" target="_blank" rel="noopener" data-no-progress href="https://wa.me/<?= e(preg_replace('/\D/', '', $o['phone'])) ?>?text=<?= rawurlencode('Bonjour ' . $o['customer_name'] . ', concernant votre commande ' . $o['number'] . ' : ') ?>"><i class="bi bi-whatsapp"></i></a></div>
      <?php if ($o['email']): ?><div class="small"><i class="bi bi-envelope"></i> <?= e($o['email']) ?></div><?php endif; ?>
      <div class="mt-2"><?= $o['delivery'] === 'delivery' ? '<i class="bi bi-truck"></i> <b>Livraison</b><div>' . e($o['address'] ?? '') . '</div>' : '<i class="bi bi-shop"></i> <b>Retrait en boutique</b>' ?></div>
      <?php if ($o['note']): ?><div class="alert alert-info small mt-2 mb-0"><i class="bi bi-chat-left-text"></i> <?= e($o['note']) ?></div><?php endif; ?>
    </div></div>

    <div class="card mb-3"><div class="card-header"><i class="bi bi-lightning"></i> Actions</div><div class="card-body d-grid gap-2">
      <?php if ($o['status'] === 'new'): ?><form method="post" action="<?= e(url('/boutique-admin/' . $o['id'] . '/valider')) ?>" class="d-grid"><?= csrf_field() ?><button class="btn btn-info"><i class="bi bi-check2-circle"></i> Valider la commande (vérifiée)</button></form><?php endif; ?>
      <?php if ($o['status'] === 'paid'): ?><form method="post" action="<?= e(url('/boutique-admin/' . $o['id'] . '/livrer')) ?>" class="d-grid"><?= csrf_field() ?><button class="btn btn-success btn-lg"><i class="bi bi-bag-check"></i> <?= $o['delivery'] === 'delivery' ? 'Marquer comme livrée' : 'Remise au client (retirée)' ?></button></form><?php endif; ?>
      <?php if (in_array($o['status'], ['new', 'confirmed'], true)): ?>
        <form method="post" action="<?= e(url('/boutique-admin/' . $o['id'] . '/annuler')) ?>" class="d-flex gap-1" data-confirm="Annuler cette commande ? Le client verra « Annulée » sur son reçu."><?= csrf_field() ?><input name="reason" class="form-control form-control-sm" placeholder="Motif (client injoignable, rupture…)" required maxlength="255"><button class="btn btn-sm btn-outline-danger text-nowrap"><i class="bi bi-x-circle"></i> Annuler</button></form>
      <?php endif; ?>
      <?php if ($o['status'] === 'delivered'): ?><div class="text-success"><i class="bi bi-check-all"></i> Commande terminée.</div><?php endif; ?>
      <?php if ($o['status'] === 'cancelled'): ?><div class="text-secondary">Annulée : <?= e($o['cancel_reason'] ?? '') ?></div><?php endif; ?>
    </div></div>

    <div class="card"><div class="card-header"><i class="bi bi-clock-history"></i> Historique</div><ul class="list-group list-group-flush small">
      <?php foreach ($o['log'] as $l): [$ll, $lc] = $statuses[$l['status']] ?? [$l['status'], 'secondary']; ?><li class="list-group-item"><span class="badge text-bg-<?= e($lc) ?>"><?= e($ll) ?></span> <?= e(fdate($l['created_at'])) ?><div class="text-secondary"><?= e($l['note'] ?? '') ?><?= $l['full_name'] ? ' · ' . e($l['full_name']) : ' · client' ?></div></li><?php endforeach; ?>
    </ul></div>
  </div>
</div>
