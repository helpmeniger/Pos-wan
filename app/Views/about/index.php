<?php $tels = $author['phones']; ?>
<div class="row g-3 justify-content-center">
  <div class="col-xl-8">
    <div class="card mb-3 about-card"><div class="card-body p-4">
      <div class="d-flex align-items-center gap-3 mb-3">
        <img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="64" height="64">
        <div><div class="fs-3 fw-bold">POS <span class="text-warning">Wan</span></div><div class="text-secondary">Caisse et gestion commerciale · version <?= e(config('app.version')) ?></div></div>
      </div>
      <p class="mb-3">POS Wan est un logiciel conçu et développé au Niger pour les commerces, pharmacies, restaurants, pressings et cliniques : caisse, stocks, achats, clients, rapports, modules complémentaires.</p>
      <div class="border rounded p-3 mb-3 bg-body-tertiary">
        <div class="small text-secondary text-uppercase fw-semibold mb-1">Conception et développement</div>
        <div class="fs-5 fw-bold"><?= e($author['name']) ?></div>
        <div><?= e($author['title']) ?> — <?= e($author['country']) ?></div>
        <div class="mt-2 d-flex flex-wrap gap-2">
          <?php foreach ($tels as $t): ?><a class="btn btn-sm btn-outline-success" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $t)) ?>"><i class="bi bi-telephone"></i> <?= e($t) ?></a><a class="btn btn-sm btn-outline-success" target="_blank" rel="noopener" href="https://wa.me/<?= e(preg_replace('/\D/', '', $t)) ?>"><i class="bi bi-whatsapp"></i></a><?php endforeach; ?>
        </div>
      </div>
      <dl class="row small mb-3">
        <dt class="col-sm-4">Licence</dt>
        <dd class="col-sm-8"><?php if ($lic['state'] === 'licensed'): ?><span class="badge bg-success">Activée</span> <?= e($lic['client']) ?><?= $lic['expires'] ? ' · jusqu\'au ' . e(fdate($lic['expires'], false)) : ' · sans limite de durée' ?><?php elseif ($lic['state'] === 'trial'): ?><span class="badge bg-warning text-dark">Essai</span> <?= (int) $lic['days_left'] ?> jour(s) restant(s)<?php else: ?><span class="badge bg-danger">Non activée — lecture seule</span><?php endif; ?></dd>
        <dt class="col-sm-4">Identifiant d'installation</dt><dd class="col-sm-8"><code><?= e($lic['install_id']) ?></code></dd>
        <dt class="col-sm-4">Droits</dt><dd class="col-sm-8">© <?= date('Y') ?> <?= e($author['name']) ?>. Logiciel propriétaire, tous droits réservés. Toute copie, revente ou utilisation sans licence est interdite.</dd>
      </dl>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="<?= e(url('/about/contrat')) ?>"><i class="bi bi-file-earmark-text"></i> Contrat de licence</a>
        <a class="btn btn-outline-primary" href="<?= e(url('/licence')) ?>"><i class="bi bi-key"></i> Licence et activation</a>
      </div>
    </div></div>
  </div>
</div>
