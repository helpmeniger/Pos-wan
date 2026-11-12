<?php
$id = (int) ($c['id'] ?? 0); $drv = $c['driver'] ?? 'generic_rest'; $info = $drivers[$drv] ?? null;
$cfg = json_decode((string) ($c['config_json'] ?? ''), true) ?: []; $shown = $cfg; unset($shown['openapi'], $shown['scenario']);
$example = ['base_url' => 'https://sandbox.prestataire.exemple/v1', 'amount_unit' => 'major', 'auth' => ['type' => 'bearer'],
  'endpoints' => ['test' => ['method' => 'GET', 'path' => '/ping'], 'initiate' => ['method' => 'POST', 'path' => '/payments', 'body' => '{"amount":{{json:amount}},"currency":"{{currency}}","reference":"{{number}}","phone":"{{payer_phone}}","notify_url":"{{webhook_url}}"}'],
    'status' => ['method' => 'GET', 'path' => '/payments/{{reference}}'], 'refund' => ['method' => 'POST', 'path' => '/payments/{{reference}}/refunds']],
  'response' => ['reference' => 'id', 'status' => 'status', 'amount' => 'amount', 'currency' => 'currency', 'redirect_url' => 'checkout_url'],
  'status_map' => ['SUCCESS' => 'succeeded', 'PENDING' => 'pending', 'FAILED' => 'declined'], 'refund_status_map' => ['DONE' => 'refunded']];
?>
<?= App\Core\View::partial('pay::_nav', ['tab' => $tab]) ?>
<?php if ($info && !empty($info['skeleton'])): ?><div class="alert alert-danger small py-2"><i class="bi bi-x-octagon"></i> <?= e(t($info['notes'])) ?></div><?php endif; ?>
<?php if ($drv === 'simulation'): ?><div class="alert alert-dark small py-2"><i class="bi bi-cone-striped"></i> <?= e(t('Simulation : aucune somme n\'est débitée ; transactions marquées TEST, jamais comptées comme encaissements, interdite en production.')) ?></div><?php endif; ?>
<div class="row g-3"><div class="col-lg-7"><div class="card"><div class="card-header fw-semibold"><?= e(t('Configuration')) ?></div><div class="card-body">
<form method="post" action="<?= e(url($id ? "/pay/connecteurs/$id" : '/pay/connecteurs')) ?>" class="row g-2"><?= csrf_field() ?>
  <div class="col-md-6"><label class="form-label small mb-0"><?= e(t('Nom')) ?></label><input name="name" value="<?= e($c['name'] ?? '') ?>" maxlength="120" class="form-control form-control-sm" required></div>
  <div class="col-md-6"><label class="form-label small mb-0"><?= e(t('Compte (libellé)')) ?></label><input name="account_label" value="<?= e($c['account_label'] ?? '') ?>" maxlength="120" class="form-control form-control-sm" placeholder="<?= e(t('ex. compte marchand boutique Centre')) ?>"></div>
  <div class="col-md-6"><label class="form-label small mb-0"><?= e(t('Connecteur')) ?></label><select name="driver" class="form-select form-select-sm"><?php foreach ($drivers as $k => $d): ?><option value="<?= e($k) ?>" <?= $drv === $k ? 'selected' : '' ?>><?= e($d['label']) ?><?= !empty($d['skeleton']) ? ' — ' . e(t('non développé')) : '' ?></option><?php endforeach; ?></select></div>
  <div class="col-md-6"><label class="form-label small mb-0"><?= e(t('Fournisseur')) ?></label><select name="provider_id" class="form-select form-select-sm"><option value=""></option><?php foreach ($providers as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($c['provider_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label small mb-0"><?= e(t('Environnement')) ?></label><select name="environment" class="form-select form-select-sm"><option value="test">TEST</option><option value="prod" <?= ($c['environment'] ?? '') === 'prod' ? 'selected' : '' ?>>PRODUCTION</option></select></div>
  <div class="col-md-5"><label class="form-label small mb-0"><?= e(t('Établissement')) ?></label><select name="store_id" class="form-select form-select-sm"><option value=""><?= e(t('Tous')) ?></option><?php foreach ($stores as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) ($c['store_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label small mb-0"><?= e(t('Délai (s)')) ?></label><input type="number" name="timeout_s" min="5" max="120" value="<?= (int) ($c['timeout_s'] ?? 30) ?>" class="form-control form-control-sm"></div>
  <?php if ($drv === 'simulation'): ?><div class="col-md-6"><label class="form-label small mb-0"><?= e(t('Scénario')) ?></label><select name="scenario" class="form-select form-select-sm"><?php foreach (['success' => 'Paiement réussi', 'decline' => 'Paiement refusé', 'pending' => 'En attente puis réussi', 'pending_forever' => 'En attente sans réponse (expiration)', 'network_loss' => 'Perte de connexion', 'invalid' => 'Réponse incorrecte (montant)', 'refund_decline' => 'Remboursement refusé', 'refund_pending' => 'Remboursement en attente'] as $k => $l): ?><option value="<?= $k ?>" <?= ($cfg['scenario'] ?? 'success') === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="col-12"><label class="form-label small mb-0"><?= e(t('Paramètres techniques JSON (sans aucun secret)')) ?></label><textarea name="config_json" rows="12" class="form-control form-control-sm font-monospace" spellcheck="false" placeholder="<?= e(json_encode($example, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?>"><?= e($shown ? json_encode($shown, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : '') ?></textarea>
    <div class="form-text"><?= e(t('URL, points d\'accès, en-têtes, gabarits JSON/XML ({{number}}, {{amount}}, {{currency}}, {{payer_phone}}, {{webhook_url}}…), correspondance des réponses et des statuts : à établir d\'après la documentation officielle.')) ?></div></div>
  <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-save"></i> <?= e(t('Enregistrer')) ?></button></div>
</form></div></div></div>
<div class="col-lg-5">
<?php if ($c): ?>
  <div class="card mb-3"><div class="card-header fw-semibold"><?= e(t('État du service')) ?></div><div class="card-body small">
    <p class="mb-1"><?= (int) $c['active'] ? '<span class="badge text-bg-success">' . e(t('actif')) . '</span>' : '<span class="badge text-bg-light border">' . e(t('inactif')) . '</span>' ?> · <?= e(t('test')) ?> : <b class="<?= $c['status'] === 'ok' ? 'text-success' : ($c['status'] === 'error' ? 'text-danger' : '') ?>"><?= e($c['status']) ?></b> <?= e(fdate($c['last_test_at'])) ?></p>
    <?php if ($c['last_test_message']): ?><p class="text-secondary"><?= e($c['last_test_message']) ?></p><?php endif; ?>
    <div class="d-flex flex-wrap gap-2">
      <?php if (in_array('testConnection', $info['capabilities'] ?? [], true)): ?><form method="post" action="<?= e(url("/pay/connecteurs/$id/tester")) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary"><i class="bi bi-activity"></i> <?= e(t('Tester la connexion')) ?></button></form><?php endif; ?>
      <?php if (empty($info['skeleton'])): ?><form method="post" action="<?= e(url("/pay/connecteurs/$id/activer")) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-<?= (int) $c['active'] ? 'secondary' : 'success' ?>"><?= e(t((int) $c['active'] ? 'Désactiver' : 'Activer')) ?></button></form><?php endif; ?>
      <form method="post" action="<?= e(url("/pay/connecteurs/$id/supprimer")) ?>" data-confirm="<?= e(t('Supprimer cette connexion ? (refusé si elle a des transactions)')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
    </div>
    <p class="mt-2 mb-0 text-secondary"><?= e(t('Fonctions')) ?> : <?= e(implode(', ', $info['capabilities'] ?? []) ?: t('aucune')) ?>. <?= e(t('Vérifié sur documentation officielle')) ?> : <b><?= !empty($info['verified']) ? e(t('oui')) : e(t('non')) ?></b>.</p>
  </div></div>
  <?php if (!str_starts_with($c['driver'], 'simulation') && empty($info['skeleton'])): ?>
  <?= App\Core\View::partial('pay::_secrets', ['owner' => 'connector', 'id' => $id, 'hints' => $hints, 'fields' => Addons\Pay\PayAdmin::SECRET_FIELDS]) ?>
  <?php if (can('pay.secrets')): ?><div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-file-earmark-lock"></i> <?= e(t('Certificats SSL / mTLS')) ?></div><div class="card-body small">
    <?php foreach ($certs as $k => $x): ?><div><?= e($k) ?> · <code><?= e(substr($x['sha256'], 0, 16)) ?>…</code> <?= $x['valid_to'] ? '· ' . e(t('expire le')) . ' ' . e(fdate($x['valid_to'], false)) : '' ?></div><?php endforeach; ?>
    <form method="post" action="<?= e(url("/pay/connecteurs/$id/certificat")) ?>" enctype="multipart/form-data" class="row g-2 mt-1"><?= csrf_field() ?>
      <div class="col-5"><select name="kind" class="form-select form-select-sm"><option value="ca"><?= e(t('Autorité (CA)')) ?></option><option value="client_cert"><?= e(t('Certificat client')) ?></option><option value="client_key"><?= e(t('Clé privée client')) ?></option></select></div>
      <div class="col-7"><input type="file" name="file" accept=".pem,.crt,.key,.cer" class="form-control form-control-sm" required></div>
      <div class="col-12"><button class="btn btn-sm btn-outline-primary"><?= e(t('Téléverser')) ?></button></div></form></div></div>
  <div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-broadcast"></i> <?= e(t('Notifications (webhook)')) ?></div><div class="card-body small">
    <?php if ($webhook): ?><p class="mb-1"><?= e(t('Créé le')) ?> <?= e(fdate($webhook['created_at'])) ?> · <?= e(t('dernière réception')) ?> : <?= e(fdate($webhook['last_received_at']) ?: '—') ?></p><?php endif; ?>
    <p class="text-secondary"><?= e(t('Signature HMAC-SHA256 du corps vérifiée à chaque notification ; les doublons sont ignorés ; le statut est revérifié auprès du prestataire quand son API le permet.')) ?></p>
    <form method="post" action="<?= e(url("/pay/connecteurs/$id/webhook")) ?>" data-confirm="<?= e(t($webhook ? 'Remplacer l\'adresse de notification ?' : 'Créer l\'adresse de notification ?')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary"><?= e(t($webhook ? 'Régénérer' : 'Créer')) ?></button></form></div></div><?php endif; ?>
  <div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-filetype-json"></i> <?= e(t('Importer une description OpenAPI')) ?></div><div class="card-body small">
    <form method="post" action="<?= e(url("/pay/connecteurs/$id/openapi")) ?>" enctype="multipart/form-data" class="d-flex gap-2"><?= csrf_field() ?><input type="file" name="openapi" accept=".json" class="form-control form-control-sm" required><button class="btn btn-sm btn-outline-primary"><?= e(t('Lire')) ?></button></form>
    <?php if (!empty($cfg['openapi'])): $o = $cfg['openapi']; ?><p class="mt-2 mb-1"><b><?= e($o['title']) ?></b> <?= e($o['version']) ?></p><div style="max-height:200px;overflow:auto"><table class="table table-sm mb-0"><?php foreach ($o['operations'] as $op): ?><tr><td><code><?= e($op['method']) ?></code></td><td><code><?= e($op['path']) ?></code></td><td><?= e($op['summary']) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
  </div></div>
  <?php endif; ?>
<?php else: ?>
  <div class="card"><div class="card-body small"><p class="text-secondary"><?= e(t('Une connexion est créée inactive. Saisissez ensuite ses secrets, testez-la, puis activez-la.')) ?></p><ul class="mb-0"><?php foreach ($drivers as $d): ?><li><b><?= e($d['label']) ?></b> — <?= e(t($d['notes'])) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>
</div></div>
