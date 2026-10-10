<?php
$typeLabels = ['V' => 'Vente (ticket / facture)', 'D' => 'Devis', 'C' => 'Commande restaurant'];
$links = ['V' => '/sales/', 'D' => '/quotes/', 'C' => '/restaurant/orders/'];
?>
<div class="row justify-content-center">
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-body">
      <p class="text-secondary small mb-2">Scannez le QR code imprimé en bas d'un ticket, d'une facture ou d'un devis avec une douchette 2D, ou collez son contenu. La signature est contrôlée avec la clé secrète de cette installation.</p>
      <form method="get" action="<?= e(url('/verify')) ?>" class="d-flex gap-2">
        <input type="text" name="code" class="form-control" value="<?= e($code) ?>" placeholder="POSWAN|V|..." autofocus autocomplete="off" required>
        <button class="btn btn-primary"><i class="bi bi-shield-check"></i> Vérifier</button>
      </form>
    </div></div>
    <?php if ($r !== null): ?>
      <div class="alert <?= $r['valid'] ? 'alert-success' : 'alert-danger' ?>">
        <div class="fw-semibold"><i class="bi <?= $r['valid'] ? 'bi-patch-check' : 'bi-exclamation-octagon' ?>"></i> <?= e($r['message']) ?></div>
        <?php if (!empty($r['doc'])): $d = $r['doc']; ?>
          <dl class="row mb-0 mt-2 small">
            <dt class="col-4">Type</dt><dd class="col-8"><?= e($typeLabels[$r['type']] ?? $r['type']) ?></dd>
            <dt class="col-4">Numéro</dt><dd class="col-8"><?= e($d['number']) ?></dd>
            <dt class="col-4">Date</dt><dd class="col-8"><?= e(fdate($d['created_at'] ?? $d['opened_at'] ?? null)) ?></dd>
            <dt class="col-4">Montant</dt><dd class="col-8"><?= isset($d['total']) ? e(money($d['total'])) : '' ?></dd>
            <dt class="col-4">Statut</dt><dd class="col-8"><?= App\Core\View::partial('partials/status', ['status' => $d['status']]) ?></dd>
          </dl>
          <a class="btn btn-sm btn-outline-primary mt-2" href="<?= e(url($links[$r['type']] . (int) $d['id'])) ?>"><i class="bi bi-box-arrow-up-right"></i> Ouvrir le document</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
