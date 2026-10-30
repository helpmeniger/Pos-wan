<?php use Addons\Pressing\PressingService as PS; ?>
<?php if (!$cashOpen && setting('require_cash_session', '1') === '1'): ?>
<div class="alert alert-warning small"><i class="bi bi-cash-coin"></i> Votre caisse est fermée : un dépôt déjà payé peut être livré, mais pour encaisser un solde chez le client, ouvrez d'abord votre caisse (<a href="<?= e(url('/cash')) ?>">Ma caisse</a>).</div>
<?php endif; ?>
<h2 class="h5 mb-2"><i class="bi bi-truck"></i> À livrer maintenant <span class="badge bg-primary"><?= count($ready) ?></span></h2>
<div class="row g-3 mb-4">
<?php foreach ($ready as $o): $rest = max(0, round((float) $o['total'] - (float) $o['paid'], 2)); ?>
  <div class="col-md-6 col-xl-4"><div class="card h-100 <?= (int) $o['express'] ? 'border-danger' : '' ?>"><div class="card-body">
    <div class="d-flex align-items-start gap-2"><div class="fw-bold"><?= e($o['number']) ?></div><?= (int) $o['express'] ? '<span class="badge bg-danger">EXPRESS</span>' : '' ?><?= $o['status'] === 'partial' ? '<span class="badge bg-warning text-dark">Retrait partiel</span>' : '' ?><span class="ms-auto small text-secondary">prévu le <?= e(fdate($o['due_date'], false)) ?></span></div>
    <div class="mt-1"><i class="bi bi-person"></i> <?= e($o['customer_name']) ?> · <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $o['phone'])) ?>"><?= e($o['phone']) ?></a></div>
    <div><i class="bi bi-geo-alt"></i> <b><?= e($o['delivery_address'] ?? '') ?></b></div>
    <div class="small text-secondary mt-1"><?= e($o['clothes'] ?? '') ?> · <?= (int) $o['pieces'] ?> colis<?= (float) $o['weight'] > 0 ? ' · ' . e(PS::qty($o['weight'], 'kg')) : '' ?><?= (float) $o['area'] > 0 ? ' · ' . e(PS::qty($o['area'], 'm2')) : '' ?></div>
    <?php if ($o['note']): ?><div class="small mt-1"><i class="bi bi-chat-left-text"></i> <?= e($o['note']) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('/pressing/livraisons/' . $o['id'])) ?>" class="mt-2 border-top pt-2" data-confirm="Confirmer la remise du dépôt <?= e($o['number']) ?> au client ?"><?= csrf_field() ?>
      <?php if ($rest > 0): ?>
        <div class="small mb-1">Reste à encaisser : <b class="text-danger"><?= money($rest) ?></b></div>
        <div class="row g-1 mb-2"><?= App\Core\View::partial('pressing::_pay', ['amount' => (string) $rest]) ?></div>
      <?php else: ?><div class="small text-success mb-2"><i class="bi bi-check-circle"></i> Déjà payé</div><?php endif; ?>
      <button class="btn btn-success w-100"><i class="bi bi-box-arrow-in-down"></i> Livré<?= $rest > 0 ? ' et encaissé' : '' ?></button>
    </form>
  </div></div></div>
<?php endforeach; ?>
<?php if (!$ready): ?><div class="col-12 text-secondary">Aucune livraison prête pour le moment.</div><?php endif; ?>
</div>
<?php if ($coming): ?>
<h2 class="h6 text-secondary">En préparation à l'atelier (<?= count($coming) ?>)</h2>
<ul class="list-group mb-4"><?php foreach ($coming as $o): ?><li class="list-group-item small d-flex gap-2"><b><?= e($o['number']) ?></b><span><?= e($o['customer_name']) ?> — <?= e($o['delivery_address'] ?? '') ?></span><span class="ms-auto"><?= e(PS::SHORT[$o['status']]) ?> · prévu le <?= e(fdate($o['due_date'], false)) ?></span></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if ($done): ?>
<h2 class="h6 text-secondary">Livrés aujourd'hui (<?= count($done) ?>)</h2>
<ul class="list-group"><?php foreach ($done as $o): ?><li class="list-group-item small d-flex gap-2"><i class="bi bi-check2 text-success"></i><b><?= e($o['number']) ?></b><span><?= e($o['customer_name']) ?> — <?= e($o['delivery_address'] ?? '') ?></span><span class="ms-auto"><?= e(substr((string) $o['delivered_at'], 11, 5)) ?></span></li><?php endforeach; ?></ul>
<?php endif; ?>
